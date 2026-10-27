<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function log(
        ?int $userId,
        string $action,
        string $entity,
        ?int $entityId = null,
        array $details = [],
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
