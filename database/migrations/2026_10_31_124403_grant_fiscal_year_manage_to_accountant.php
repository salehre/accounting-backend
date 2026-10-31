<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Accountants may create and edit fiscal years. Closing and reopening
    // ("fiscal-years.close") stays with administrators only: separation of duties.
    private const PERMISSION = 'fiscal-years.manage';

    public function up(): void
    {
        $this->update(fn (array $permissions) => array_values(array_unique([...$permissions, self::PERMISSION])));
    }

    public function down(): void
    {
        $this->update(fn (array $permissions) => array_values(array_diff($permissions, [self::PERMISSION])));
    }

    private function update(callable $change): void
    {
        $current = json_decode((string) DB::table('roles')->where('name', 'accountant')->value('permissions'), true) ?? [];

        DB::table('roles')
            ->where('name', 'accountant')
            ->update(['permissions' => json_encode($change($current))]);
    }
};
