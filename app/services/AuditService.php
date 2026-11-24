<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Records important actions (who did what, when). Never store passwords, codes or secrets here. */
final class AuditService
{
    public static function log(string $action, string $entityType, ?int $entityId = null, array $details = [], ?int $userId = null): void
    {
        Database::insert('activity_log', [
            'user_id'     => $userId ?? AuthService::id(),
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
