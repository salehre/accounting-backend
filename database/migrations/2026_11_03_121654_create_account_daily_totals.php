<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Daily totals of the POSTED lines, per fiscal year, account and detail account.
            -- Posted entries never change, so this table only ever grows: reports read these
            -- few thousand rows instead of millions of journal lines.
            CREATE TABLE account_daily_totals (
                fiscal_year_id bigint  NOT NULL REFERENCES fiscal_years(id),
                account_id     bigint  NOT NULL REFERENCES accounts(id),
                detail_id      bigint  NOT NULL DEFAULT 0,      -- 0 = no detail account
                entry_date     date    NOT NULL,
                closing        boolean NOT NULL DEFAULT false,  -- year-end closing entries and their reversals
                debit          bigint  NOT NULL DEFAULT 0,
                credit         bigint  NOT NULL DEFAULT 0,
                PRIMARY KEY (fiscal_year_id, account_id, detail_id, entry_date, closing)
            );
            CREATE INDEX ix_adt_year_date ON account_daily_totals (fiscal_year_id, entry_date);

            -- Fills the totals when an entry is posted. Posting is already serialised per fiscal
            -- year (the gapless numbering), so this adds no extra waiting.
            CREATE FUNCTION trg_entries_totals() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_closing boolean;
            BEGIN
              IF NEW.status = 'posted' AND OLD.status = 'draft' THEN
                v_closing := NEW.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing')
                          OR EXISTS (SELECT 1 FROM journal_entries o
                                      WHERE o.id = NEW.reverses_entry_id
                                        AND o.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing'));

                INSERT INTO account_daily_totals
                       (fiscal_year_id, account_id, detail_id, entry_date, closing, debit, credit)
                SELECT NEW.fiscal_year_id, l.account_id, COALESCE(l.detail_id, 0), NEW.entry_date,
                       v_closing, sum(l.debit), sum(l.credit)
                  FROM journal_lines l
                 WHERE l.entry_id = NEW.id
                 GROUP BY l.account_id, COALESCE(l.detail_id, 0)
                ON CONFLICT (fiscal_year_id, account_id, detail_id, entry_date, closing)
                DO UPDATE SET debit  = account_daily_totals.debit  + EXCLUDED.debit,
                              credit = account_daily_totals.credit + EXCLUDED.credit;
              END IF;

              RETURN NULL;
            END $$;

            CREATE TRIGGER entries_totals
              AFTER UPDATE ON journal_entries
              FOR EACH ROW EXECUTE FUNCTION trg_entries_totals();

            -- Entries that were posted before this migration
            INSERT INTO account_daily_totals
                   (fiscal_year_id, account_id, detail_id, entry_date, closing, debit, credit)
            SELECT x.fiscal_year_id, l.account_id, COALESCE(l.detail_id, 0), x.entry_date, x.closing,
                   sum(l.debit), sum(l.credit)
              FROM (SELECT e.id, e.fiscal_year_id, e.entry_date,
                           (e.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing')
                            OR EXISTS (SELECT 1 FROM journal_entries o
                                        WHERE o.id = e.reverses_entry_id
                                          AND o.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing'))) AS closing
                      FROM journal_entries e
                     WHERE e.status = 'posted') x
              JOIN journal_lines l ON l.entry_id = x.id
             GROUP BY x.fiscal_year_id, l.account_id, COALESCE(l.detail_id, 0), x.entry_date, x.closing;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS entries_totals ON journal_entries;
            DROP FUNCTION IF EXISTS trg_entries_totals();
            DROP TABLE IF EXISTS account_daily_totals;
            SQL);
    }
};
