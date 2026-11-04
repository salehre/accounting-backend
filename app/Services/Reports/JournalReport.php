<?php

namespace App\Services\Reports;

use App\Support\ReportScope;
use App\Support\SignedCursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The journal book: every posted entry (the year-end entries too) with its lines, in the order
 * (date, number). Pages are fetched with a signed cursor (keyset paging), so the 5,000th page
 * is as fast as the first.
 */
final class JournalReport
{
    /** @return array<string, mixed> */
    public function generate(
        int $fiscalYearId,
        ?string $from,
        ?string $to,
        ?int $fromNumber,
        ?int $toNumber,
        ?string $cursor,
        int $pageSize,
    ): array {
        $scope = ReportScope::resolve($fiscalYearId, $from, $to);
        $from = $scope['from'];
        $to = $scope['to'];

        if ($fromNumber !== null && $toNumber !== null && $fromNumber > $toNumber) {
            throw ValidationException::withMessages(['toNumber' => 'The last number must not be before the first number.']);
        }

        $entries = fn (): Builder => DB::table('journal_entries as e')
            ->where('e.status', 'posted')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereBetween('e.entry_date', [$from, $to])
            ->when($fromNumber !== null, fn ($q) => $q->where('e.number', '>=', $fromNumber))
            ->when($toNumber !== null, fn ($q) => $q->where('e.number', '<=', $toNumber));

        // ---- totals of the whole range ----------------------------------
        if ($fromNumber === null && $toNumber === null) {
            // the usual case: a few thousand daily rows instead of millions of lines
            $totals = DB::table('account_daily_totals')
                ->where('fiscal_year_id', $fiscalYearId)
                ->whereBetween('entry_date', [$from, $to])
                ->selectRaw('coalesce(sum(debit), 0) as debit, coalesce(sum(credit), 0) as credit')
                ->first();
        } else {
            $totals = $entries()
                ->selectRaw('coalesce(sum(e.total_debit), 0) as debit, coalesce(sum(e.total_credit), 0) as credit')
                ->first();
        }

        // ---- one page of entries ----------------------------------------
        $position = null;
        if ($cursor !== null) {
            $position = SignedCursor::decode($cursor, ['d', 'n']);

            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $position['d'])) {
                throw ValidationException::withMessages(['cursor' => 'The cursor is not valid.']);
            }
        }

        $found = $entries()
            ->when($position !== null, fn ($q) => $q->whereRaw(
                '(e.entry_date, e.number) > (?::date, ?::bigint)',
                [(string) $position['d'], (int) $position['n']],
            ))
            ->orderBy('e.entry_date')
            ->orderBy('e.number')
            ->limit($pageSize + 1)
            ->get(['e.id', 'e.number', 'e.entry_date', 'e.description', 'e.source_type', 'e.total_debit', 'e.total_credit']);

        $hasMore = $found->count() > $pageSize;
        $page = $found->take($pageSize);

        // ---- their lines, in one query ----------------------------------
        $byEntry = $page->isEmpty() ? collect() : DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('detail_accounts as d', 'd.id', '=', 'l.detail_id')
            ->whereIn('l.entry_id', $page->pluck('id')->all())
            ->orderBy('l.entry_id')
            ->orderBy('l.line_no')
            ->get(['l.entry_id', 'l.line_no', 'a.code as account_code', 'a.name as account_name', 'd.name as detail_name', 'l.description', 'l.debit', 'l.credit'])
            ->groupBy('entry_id');

        $items = [];
        foreach ($page as $entry) {
            $items[] = [
                'id' => (int) $entry->id,
                'number' => (int) $entry->number,
                'date' => $entry->entry_date,
                'description' => $entry->description,
                'sourceType' => $entry->source_type,
                'totalDebit' => (int) $entry->total_debit,
                'totalCredit' => (int) $entry->total_credit,
                'lines' => $byEntry->get($entry->id, collect())->map(fn ($line) => [
                    'lineNo' => (int) $line->line_no,
                    'accountCode' => $line->account_code,
                    'accountName' => $line->account_name,
                    'detailName' => $line->detail_name,
                    'description' => $line->description,
                    'debit' => (int) $line->debit,
                    'credit' => (int) $line->credit,
                ])->values()->all(),
            ];
        }

        $last = $page->last();

        return [
            'fiscalYear' => ['id' => (int) $scope['year']->id, 'title' => $scope['year']->title],
            'from' => $from,
            'to' => $to,
            'fromNumber' => $fromNumber,
            'toNumber' => $toNumber,
            'totals' => ['debit' => (int) $totals->debit, 'credit' => (int) $totals->credit],
            'items' => $items,
            'nextCursor' => $hasMore && $last !== null
                ? SignedCursor::encode(['d' => $last->entry_date, 'n' => (int) $last->number])
                : null,
        ];
    }
}
