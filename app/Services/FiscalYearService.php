<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalYearService
{
    /** The longest fiscal year we accept (catches typos such as 2026 -> 2036). */
    private const MAX_DAYS = 730;

    private const SELECT = <<<'SQL'
        f.id,
        f.title,
        f.start_date as "startDate",
        f.end_date   as "endDate",
        f.status,
        (select count(*) from journal_entries e where e.fiscal_year_id = f.id and e.status = 'posted') as "postedEntries",
        (select count(*) from journal_entries e where e.fiscal_year_id = f.id and e.status = 'draft')  as "draftEntries"
        SQL;

    public function all(): Collection
    {
        return DB::table('fiscal_years as f')
            ->orderByDesc('f.start_date')
            ->selectRaw(self::SELECT)
            ->get()
            ->map(fn ($row) => $this->withFlags($row));
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $year = DB::table('fiscal_years as f')->where('f.id', $id)->selectRaw(self::SELECT)->first();

        if (! $year) {
            abort(404, 'Fiscal year not found');
        }

        return $this->withFlags($year);
    }

    /**
     * @param array{title: string, startDate: string, endDate: string} $data
     * @return array{id: int, warnings: array<int, string>}
     */
    public function create(array $data, int $actorId): array
    {
        return DB::transaction(function () use ($data, $actorId) {
            $this->lockFiscalYears();
            $this->assertValidRange($data['startDate'], $data['endDate']);
            $this->assertNoOverlap($data['startDate'], $data['endDate'], null);

            try {
                $id = (int) DB::table('fiscal_years')->insertGetId([
                    'title' => $data['title'],
                    'start_date' => $data['startDate'],
                    'end_date' => $data['endDate'],
                ]);
            } catch (QueryException $e) {
                $this->explainOverlap($e);
                throw $e;
            }

            Audit::log($actorId, 'create', 'fiscal_year', $id, [
                'title' => $data['title'],
                'startDate' => $data['startDate'],
                'endDate' => $data['endDate'],
            ]);

            return ['id' => $id, 'warnings' => $this->gapWarnings($data['startDate'], $data['endDate'], $id)];
        });
    }

    /**
     * @param array{title?: string, startDate?: string, endDate?: string} $data
     * @return array<int, string> warnings
     */
    public function update(int $id, array $data, int $actorId): array
    {
        return DB::transaction(function () use ($id, $data, $actorId) {
            $this->lockFiscalYears();

            $year = DB::table('fiscal_years')->where('id', $id)->first();
            if (! $year) {
                abort(404, 'Fiscal year not found');
            }

            $title = array_key_exists('title', $data) ? (string) $data['title'] : $year->title;
            $start = $data['startDate'] ?? $year->start_date;
            $end = $data['endDate'] ?? $year->end_date;

            $datesChange = $start !== $year->start_date || $end !== $year->end_date;
            $titleChanges = $title !== $year->title;

            if (! $datesChange && ! $titleChanges) {
                return []; // nothing really changed
            }

            if ($datesChange) {
                if ($year->status === 'closed') {
                    abort(422, 'A closed fiscal year cannot be edited. Reopen it first.');
                }
                if ($this->entryCount($id) > 0) {
                    abort(422, 'Dates can no longer be changed: this fiscal year already has entries.');
                }

                $this->assertValidRange($start, $end);
                $this->assertNoOverlap($start, $end, $id);
            }

            try {
                DB::table('fiscal_years')->where('id', $id)->update([
                    'title' => $title,
                    'start_date' => $start,
                    'end_date' => $end,
                ]);
            } catch (QueryException $e) {
                $this->explainOverlap($e);
                throw $e;
            }

            $changes = [];
            if ($titleChanges) {
                $changes['title'] = ['from' => $year->title, 'to' => $title];
            }
            if ($start !== $year->start_date) {
                $changes['startDate'] = ['from' => $year->start_date, 'to' => $start];
            }
            if ($end !== $year->end_date) {
                $changes['endDate'] = ['from' => $year->end_date, 'to' => $end];
            }
            Audit::log($actorId, 'update', 'fiscal_year', $id, ['changes' => $changes]);

            return $datesChange ? $this->gapWarnings($start, $end, $id) : [];
        });
    }

    public function delete(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            $this->lockFiscalYears();

            $year = DB::table('fiscal_years')->where('id', $id)->first();
            if (! $year) {
                abort(404, 'Fiscal year not found');
            }
            if ($year->status === 'closed') {
                abort(409, 'A closed fiscal year cannot be deleted.');
            }

            $message = 'This fiscal year has entries and cannot be deleted.';
            if ($this->entryCount($id) > 0) {
                abort(409, $message);
            }

            try {
                DB::table('fiscal_years')->where('id', $id)->delete();
            } catch (QueryException $e) {
                // an entry was created at the same moment
                if (($e->errorInfo[0] ?? '') === '23503') {
                    abort(409, $message);
                }

                throw $e;
            }

            Audit::log($actorId, 'delete', 'fiscal_year', $id, ['title' => $year->title]);
        });
    }

    /**
     * What stands in the way of closing this year, and what to be careful about.
     *
     * @return array<string, mixed>
     */
    public function closeCheck(int $id): array
    {
        $year = DB::table('fiscal_years')->where('id', $id)->first();
        if (! $year) {
            abort(404, 'Fiscal year not found');
        }

        $blockers = [];
        $warnings = [];

        if ($year->status === 'closed') {
            $blockers[] = ['code' => 'already_closed', 'message' => 'This fiscal year is already closed.'];
        }

        $drafts = DB::table('journal_entries')->where('fiscal_year_id', $id)->where('status', 'draft');
        $draftCount = (clone $drafts)->count();

        if ($draftCount > 0) {
            $blockers[] = [
                'code' => 'draft_entries',
                'message' => "{$draftCount} draft entr".($draftCount === 1 ? 'y' : 'ies').' must be posted or deleted first.',
                'count' => $draftCount,
                'entries' => (clone $drafts)
                    ->orderBy('entry_date')
                    ->orderBy('id')
                    ->limit(10)
                    ->get(['id', 'entry_date as entryDate', 'description'])
                    ->all(),
            ];
        }

        $totals = DB::table('journal_entries')
            ->where('fiscal_year_id', $id)
            ->where('status', 'posted')
            ->selectRaw('count(*) as posted, coalesce(sum(total_debit), 0) as debit, coalesce(sum(total_credit), 0) as credit, max(number) as last')
            ->first();

        $earlierOpen = DB::table('fiscal_years')
            ->where('start_date', '<', $year->start_date)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first(['title']);
        if ($earlierOpen) {
            $warnings[] = [
                'code' => 'earlier_year_open',
                'message' => "An earlier fiscal year (\"{$earlierOpen->title}\") is still open.",
            ];
        }

        if ((int) $totals->posted === 0 && $draftCount === 0) {
            $warnings[] = ['code' => 'no_entries', 'message' => 'This fiscal year has no entries.'];
        }

        return [
            'fiscalYearId' => $id,
            'status' => $year->status,
            'canClose' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => [
                'postedEntries' => (int) $totals->posted,
                'draftEntries' => $draftCount,
                'lastEntryNumber' => $totals->last === null ? null : (int) $totals->last,
                'totalDebit' => (int) $totals->debit,
                'totalCredit' => (int) $totals->credit,
            ],
        ];
    }

    public function close(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            // Wait for entries that are being saved right now, and keep new changes out while we
            // check, so a draft cannot slip in between the check and the closing.
            DB::statement('LOCK TABLE journal_entries IN SHARE MODE');

            // "no key update": still blocks other changes of this row, but does not
            // block the foreign-key checks of concurrent inserts (no deadlock).
            $year = DB::table('fiscal_years')->where('id', $id)->lock('for no key update')->first();
            if (! $year) {
                abort(404, 'Fiscal year not found');
            }
            if ($year->status === 'closed') {
                abort(409, 'This fiscal year is already closed.');
            }

            $check = $this->closeCheck($id);
            if (! $check['canClose']) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The fiscal year cannot be closed yet.',
                    'blockers' => $check['blockers'],
                ], 422));
            }

            DB::table('fiscal_years')->where('id', $id)->update(['status' => 'closed']);

            Audit::log($actorId, 'close', 'fiscal_year', $id, $check['summary']);
        });
    }

    public function reopen(int $id, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($id, $reason, $actorId) {
            $year = DB::table('fiscal_years')->where('id', $id)->lock('for no key update')->first();
            if (! $year) {
                abort(404, 'Fiscal year not found');
            }
            if ($year->status !== 'closed') {
                abort(409, 'This fiscal year is not closed.');
            }

            DB::table('fiscal_years')->where('id', $id)->update(['status' => 'open']);

            Audit::log($actorId, 'reopen', 'fiscal_year', $id, ['reason' => $reason]);
        });
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function withFlags(object $row): array
    {
        $year = (array) $row;
        $year['hasEntries'] = ((int) $year['postedEntries'] + (int) $year['draftEntries']) > 0;

        return $year;
    }

    private function entryCount(int $id): int
    {
        return DB::table('journal_entries')->where('fiscal_year_id', $id)->count();
    }

    /** Serialises changes of fiscal years so the overlap check cannot be raced. */
    private function lockFiscalYears(): void
    {
        DB::statement('LOCK TABLE fiscal_years IN SHARE ROW EXCLUSIVE MODE');
    }

    private function assertValidRange(string $start, string $end): void
    {
        $days = (int) DB::selectOne('select (?::date - ?::date) as days', [$end, $start])->days;

        if ($days <= 0) {
            $this->invalid('endDate', 'The end date must be after the start date.');
        }
        if ($days > self::MAX_DAYS) {
            $this->invalid('endDate', 'A fiscal year cannot be longer than '.self::MAX_DAYS.' days.');
        }
    }

    private function assertNoOverlap(string $start, string $end, ?int $ignoreId): void
    {
        $overlap = DB::table('fiscal_years')
            ->whereRaw("daterange(start_date, end_date, '[]') && daterange(?::date, ?::date, '[]')", [$start, $end])
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first(['title', 'start_date', 'end_date']);

        if ($overlap) {
            $this->invalid(
                'startDate',
                "This fiscal year overlaps fiscal year \"{$overlap->title}\" ({$overlap->start_date} to {$overlap->end_date}).",
            );
        }
    }

    /** @return array<int, string> */
    private function gapWarnings(string $start, string $end, int $id): array
    {
        $warnings = [];

        $previous = DB::table('fiscal_years')
            ->where('id', '!=', $id)
            ->where('end_date', '<', $start)
            ->orderByDesc('end_date')
            ->first(['title', 'end_date']);

        if ($previous) {
            $gap = (int) DB::selectOne('select (?::date - ?::date) as days', [$start, $previous->end_date])->days - 1;

            if ($gap > 0) {
                $warnings[] = "There is a gap of {$gap} day(s) after fiscal year \"{$previous->title}\": entries dated in the gap cannot be posted.";
            }
        }

        $next = DB::table('fiscal_years')
            ->where('id', '!=', $id)
            ->where('start_date', '>', $end)
            ->orderBy('start_date')
            ->first(['title', 'start_date']);

        if ($next) {
            $gap = (int) DB::selectOne('select (?::date - ?::date) as days', [$next->start_date, $end])->days - 1;

            if ($gap > 0) {
                $warnings[] = "There is a gap of {$gap} day(s) before fiscal year \"{$next->title}\": entries dated in the gap cannot be posted.";
            }
        }

        return $warnings;
    }

    private function explainOverlap(QueryException $e): void
    {
        // 23P01 = exclusion constraint: another request created an overlapping year at the same moment
        if (($e->errorInfo[0] ?? '') === '23P01') {
            $this->invalid('startDate', 'This fiscal year overlaps another one.');
        }
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
