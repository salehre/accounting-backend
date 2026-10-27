<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'admin' => ['*'],
        'accountant' => [
            'accounts.read', 'entries.read', 'entries.write',
            'entries.post', 'entries.reverse', 'reports.read',
        ],
        'viewer' => ['accounts.read', 'entries.read', 'reports.read'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $role => $permissions) {
            DB::table('roles')
                ->where('name', $role)
                ->update(['permissions' => json_encode($permissions)]);
        }
    }

    public function down(): void
    {
        // Permissions are data, nothing to undo.
    }
};
