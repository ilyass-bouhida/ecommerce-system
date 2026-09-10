<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Small append-only audit helper.
 *
 * Never pass passwords, raw auth tokens, token hashes, internal keys,
 * session ids, or cookies in $metadata.
 */
class AuditLogger
{
    public static function log(
        string $action,
        ?User $user = null,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        ?Request $request = null
    ): void {
        $request ??= request();

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId !== null ? (string) $entityId : null,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
