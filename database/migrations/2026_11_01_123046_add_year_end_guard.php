<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Small partial indexes: they only contain the (very few) year-end entries,
            -- so looking them up stays instant however large journal_entries becomes.
            CREATE INDEX ix_je_year_end_source ON journal_entries (source_id)
                WHERE source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing', 'year_end_opening');

            CREATE INDEX ix_je_year_end_closing ON journal_entries (fiscal_year_id)
                WHERE source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing');

            -- Once the closing entries of a fiscal year are posted (and not reversed), nothing
            -- else may be posted into that year. Only the closing entries themselves and their
            -- reversals are allowed through.
            CREATE FUNCTION trg_entries_year_end_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              v_reversed_type text;
            BEGIN
              IF NEW.status <> 'posted' OR OLD.status <> 'draft' THEN
                RETURN NEW;
              END IF;

              IF NEW.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing') THEN
                RETURN NEW;
              END IF;

              IF NEW.reverses_entry_id IS NOT NULL THEN
                SELECT source_type INTO v_reversed_type
                  FROM journal_entries WHERE id = NEW.reverses_entry_id;

                IF v_reversed_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing') THEN
                  RETURN NEW;
                END IF;
              END IF;

              IF EXISTS (
                SELECT 1
                  FROM journal_entries y
                 WHERE y.fiscal_year_id = NEW.fiscal_year_id
                   AND y.status = 'posted'
                   AND y.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing')
                   AND NOT EXISTS (
                         SELECT 1 FROM journal_entries r
                          WHERE r.reverses_entry_id = y.id AND r.status = 'posted')
              ) THEN
                RAISE EXCEPTION 'Year-end entries are posted for this fiscal year. Reverse them before posting anything else.';
              END IF;

              RETURN NEW;
            END $$;

            CREATE TRIGGER entries_year_end_guard
              BEFORE UPDATE ON journal_entries
              FOR EACH ROW EXECUTE FUNCTION trg_entries_year_end_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS entries_year_end_guard ON journal_entries;
            DROP FUNCTION IF EXISTS trg_entries_year_end_guard();
            DROP INDEX IF EXISTS ix_je_year_end_closing;
            DROP INDEX IF EXISTS ix_je_year_end_source;
            SQL);
    }
};
