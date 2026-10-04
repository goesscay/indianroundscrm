<?php

namespace App\Support;

use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityLogger
{
    /**
     * Append a security audit event (equivalent of log_security in the FastAPI app).
     *
     * @param  User|null  $user  the actor (or the subject for login events)
     */
    public static function log(?User $user, string $event, ?Request $request = null, array $extra = []): SecurityAuditLog
    {
        $request ??= request();

        return SecurityAuditLog::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_name' => $user?->name,
            'event' => $event,
            'ip' => mb_substr((string) $request?->ip(), 0, 64),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 200),
            'extra' => $extra ?: null,
            'timestamp' => now(),
        ]);
    }
}
