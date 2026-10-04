<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogger
{
    /**
     * Equivalent of log_activity(user, action, entity, entity_id, old_value, new_value).
     */
    public static function log(?User $user, string $action, string $entity, string|int $entityId = '', ?array $before = null, ?array $after = null): ActivityLog
    {
        return ActivityLog::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_name' => $user?->name,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => (string) $entityId,
            'old_value' => $before,
            'new_value' => $after,
            'timestamp' => now(),
        ]);
    }
}
