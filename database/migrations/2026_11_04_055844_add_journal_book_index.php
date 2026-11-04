<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The journal book reads the posted entries of a fiscal year in the order (date, number).
        // This partial index is exactly that order, so any page is found without sorting.
        DB::unprepared("CREATE INDEX ix_je_journal ON journal_entries (fiscal_year_id, entry_date, number) WHERE status = 'posted';");
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS ix_je_journal;');
    }
};
