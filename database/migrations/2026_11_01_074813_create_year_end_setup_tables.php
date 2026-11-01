<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Which kind of account each top-level group (level 1) is.
            -- Every account below the group inherits it.
            CREATE TABLE account_group_categories (
                account_id bigint PRIMARY KEY REFERENCES accounts(id) ON DELETE CASCADE,
                category   text   NOT NULL
                           CHECK (category IN ('asset', 'liability', 'equity', 'revenue', 'expense'))
            );

            -- The technical accounts used by the year-end entries (always exactly one row).
            CREATE TABLE accounting_settings (
                id                           smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
                profit_loss_account_id       bigint REFERENCES accounts(id),
                retained_earnings_account_id bigint REFERENCES accounts(id),
                closing_account_id           bigint REFERENCES accounts(id),
                opening_account_id           bigint REFERENCES accounts(id),
                updated_at                   timestamptz NOT NULL DEFAULT now()
            );
            INSERT INTO accounting_settings (id) VALUES (1);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS accounting_settings, account_group_categories;');
    }
};
