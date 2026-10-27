<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The SQL file manages its own BEGIN/COMMIT, so Laravel must not wrap it
    // in another transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        // The SQL file is the single source of truth for the accounting core:
        // tables, constraints, triggers (balance + immutability) and functions.
        DB::unprepared(file_get_contents(database_path('sql/001_core.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS v_trial_balance;
            DROP FUNCTION IF EXISTS reverse_entry(bigint, bigint, date);
            DROP TABLE IF EXISTS
                audit_logs, entry_counters, journal_lines, journal_entries,
                detail_accounts, accounts, fiscal_years, users, roles
                CASCADE;
            DROP FUNCTION IF EXISTS trg_entries_guard();
            DROP FUNCTION IF EXISTS trg_lines_guard();
        SQL);
    }
};
