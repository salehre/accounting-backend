<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Checks that the ledger agrees with itself: every posted entry balances and matches its lines,
 * and the stored daily totals equal the totals recomputed from the lines.
 */
final class LedgerIntegrity
{
    private const RECOMPUTED = <<<'SQL'
        SELECT x.fiscal_year_id, l.account_id, COALESCE(l.detail_id, 0) AS detail_id, x.entry_date, x.closing,
               sum(l.debit) AS debit, sum(l.credit) AS credit
          FROM (SELECT e.id, e.fiscal_year_id, e.entry_date,
                       (e.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing')
                        OR EXISTS (SELECT 1 FROM journal_entries o
                                    WHERE o.id = e.reverses_entry_id
                                      AND o.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing'))) AS closing
                  FROM journal_entries e
                 WHERE e.status = 'posted') x
          JOIN journal_lines l ON l.entry_id = x.id
         GROUP BY x.fiscal_year_id, l.account_id, COALESCE(l.detail_id, 0), x.entry_date, x.closing
        SQL;

    /**
     * @return array{ok: bool, postedEntries: int, brokenEntries: int, totalsMismatches: int, examples: array<int, array<string, mixed>>}
     */
    public static function check(int $examples = 10): array
    {
        $postedEntries = DB::table('journal_entries')->where('status', 'posted')->count();

        $brokenEntries = (int) DB::selectOne(<<<'SQL'
            SELECT count(*) AS n
              FROM journal_entries e
             WHERE e.status = 'posted'
               AND (e.total_debit <> e.total_credit
                    OR e.total_debit  <> COALESCE((SELECT sum(l.debit)  FROM journal_lines l WHERE l.entry_id = e.id), 0)
                    OR e.total_credit <> COALESCE((SELECT sum(l.credit) FROM journal_lines l WHERE l.entry_id = e.id), 0))
            SQL)->n;

        $differences = <<<'SQL'
              FROM (SQL;
        $differences = '
              FROM ('.self::RECOMPUTED.') r
              FULL OUTER JOIN account_daily_totals s
                ON s.fiscal_year_id = r.fiscal_year_id AND s.account_id = r.account_id
               AND s.detail_id = r.detail_id AND s.entry_date = r.entry_date AND s.closing = r.closing
             WHERE r.debit IS DISTINCT FROM s.debit OR r.credit IS DISTINCT FROM s.credit';

        $mismatches = (int) DB::selectOne('SELECT count(*) AS n'.$differences)->n;

        $rows = $mismatches === 0 ? [] : DB::select(
            'SELECT COALESCE(r.fiscal_year_id, s.fiscal_year_id) AS "fiscalYearId",
                    COALESCE(r.account_id, s.account_id)         AS "accountId",
                    COALESCE(r.detail_id, s.detail_id)           AS "detailId",
                    COALESCE(r.entry_date, s.entry_date)         AS "entryDate",
                    COALESCE(r.closing, s.closing)               AS closing,
                    r.debit AS "expectedDebit", r.credit AS "expectedCredit",
                    s.debit AS "storedDebit",   s.credit AS "storedCredit"'
            .$differences.' LIMIT '.max(1, $examples),
        );

        return [
            'ok' => $brokenEntries === 0 && $mismatches === 0,
            'postedEntries' => $postedEntries,
            'brokenEntries' => $brokenEntries,
            'totalsMismatches' => $mismatches,
            'examples' => array_map(fn ($row) => (array) $row, $rows),
        ];
    }
}
