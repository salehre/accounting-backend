<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class JournalEntryService
{
    /**
     * Creates a DRAFT entry with its lines, atomically.
     * Balance and the other accounting rules are checked by the database
     * when the entry is posted.
     *
     * @param array{fiscalYearId: int, entryDate: string, description?: ?string, lines: array<int, array<string, mixed>>} $data
     */
    public function createDraft(array $data, int $userId): int
    {
        return DB::transaction(function () use ($data, $userId) {
            $entryId = (int) DB::table('journal_entries')->insertGetId([
                'fiscal_year_id' => (int) $data['fiscalYearId'],
                'entry_date' => $data['entryDate'],
                'description' => $data['description'] ?? null,
                'created_by' => $userId,
            ]);

            $rows = [];
            foreach (array_values($data['lines']) as $index => $line) {
                $rows[] = [
                    'entry_id' => $entryId,
                    'line_no' => $index + 1,
                    'account_id' => (int) $line['accountId'],
                    'detail_id' => isset($line['detailId']) ? (int) $line['detailId'] : null,
                    'description' => $line['description'] ?? null,
                    'debit' => (int) $line['debit'],
                    'credit' => (int) $line['credit'],
                ];
            }
            DB::table('journal_lines')->insert($rows);

            Audit::log($userId, 'create', 'journal_entry', $entryId, ['lines' => count($rows)]);

            return $entryId;
        });
    }

    /** Turns a draft into a posted entry. The database validates and numbers it. */
    public function post(int $id, int $userId): void
    {
        DB::transaction(function () use ($id, $userId) {
            $updated = DB::select(
                "update journal_entries
                    set status = 'posted', posted_by = ?
                  where id = ? and status = 'draft'
              returning number",
                [$userId, $id],
            );

            if ($updated === []) {
                if (! DB::table('journal_entries')->where('id', $id)->exists()) {
                    abort(404, 'Entry not found');
                }
                abort(409, 'Entry is already posted');
            }

            Audit::log($userId, 'post', 'journal_entry', $id, ['number' => $updated[0]->number]);
        });
    }

    /** Creates a DRAFT reversing entry (debit and credit swapped) and returns its id. */
    public function reverse(int $id, int $userId, ?string $date): int
    {
        return DB::transaction(function () use ($id, $userId, $date) {
            $row = DB::selectOne(
                'select reverse_entry(?::bigint, ?::bigint, ?::date) as id',
                [$id, $userId, $date],
            );
            $reversalId = (int) $row->id;

            Audit::log($userId, 'reverse', 'journal_entry', $id, ['reversalEntryId' => $reversalId]);

            return $reversalId;
        });
    }

    /** Deletes a draft. The database refuses to delete a posted entry. */
    public function deleteDraft(int $id, int $userId): void
    {
        DB::transaction(function () use ($id, $userId) {
            $deleted = DB::delete('delete from journal_entries where id = ?', [$id]);

            if ($deleted === 0) {
                abort(404, 'Entry not found');
            }

            Audit::log($userId, 'delete', 'journal_entry', $id);
        });
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $entry = DB::table('journal_entries')
            ->selectRaw('id,
                         fiscal_year_id    as "fiscalYearId",
                         number,
                         entry_date        as "entryDate",
                         description,
                         status,
                         source_type       as "sourceType",
                         source_id         as "sourceId",
                         reverses_entry_id as "reversesEntryId",
                         created_by        as "createdBy",
                         created_at        as "createdAt",
                         posted_by         as "postedBy",
                         posted_at         as "postedAt"')
            ->where('id', $id)
            ->first();

        if (! $entry) {
            abort(404, 'Entry not found');
        }

        $lines = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('detail_accounts as d', 'd.id', '=', 'l.detail_id')
            ->where('l.entry_id', $id)
            ->orderBy('l.line_no')
            ->selectRaw('l.id,
                         l.line_no    as "lineNo",
                         l.account_id as "accountId",
                         a.code       as "accountCode",
                         a.name       as "accountName",
                         l.detail_id  as "detailId",
                         d.name       as "detailName",
                         l.description,
                         l.debit,
                         l.credit')
            ->get();

        $totalDebit = (int) $lines->sum('debit');
        $totalCredit = (int) $lines->sum('credit');

        return array_merge((array) $entry, [
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'isBalanced' => $totalDebit === $totalCredit,
            'lines' => $lines,
        ]);
    }

    /** @return array{items: \Illuminate\Support\Collection, page: int, pageSize: int, total: int} */
    public function paginate(?int $fiscalYearId, ?string $status, int $page, int $pageSize): array
    {
        $query = DB::table('journal_entries as e')
            ->when($fiscalYearId, fn ($q) => $q->where('e.fiscal_year_id', $fiscalYearId))
            ->when($status, fn ($q) => $q->where('e.status', $status));

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('e.entry_date')
            ->orderByDesc('e.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->selectRaw('e.id,
                         e.number,
                         e.entry_date  as "entryDate",
                         e.description,
                         e.status,
                         e.source_type as "sourceType",
                         (select coalesce(sum(l.debit), 0)
                            from journal_lines l
                           where l.entry_id = e.id) as "totalDebit"')
            ->get()
            ->map(function ($row) {
                $row->totalDebit = (int) $row->totalDebit; // sum() comes back as text

                return $row;
            });

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }
}
