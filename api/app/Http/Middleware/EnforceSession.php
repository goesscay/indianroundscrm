<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Appended to the `api` middleware group. For session-authenticated requests it enforces:
 *  - token_version match (password change / admin force-reset / kick-all invalidate sessions)
 *  - per-session revocation (user_sessions.revoked)
 *  - idle timeout (IDLE_TIMEOUT_MINUTES, default 60) + throttled last_active heartbeat
 *  - account_status gate (inactive / suspended / locked => 403)
 * password_reset_required is allowed through (frontend routes to /change-password).
 */
class EnforceSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->hasSession()) {
            return $next($request);
        }
        $guard = Auth::guard('web');
        $user = $guard->user();
        if (! $user) {
            return $next($request);
        }
        $session = $request->session();

        $fail = function (string $detail, int $status = 401) use ($guard, $session) {
            $guard->logout();
            $session->invalidate();
            abort($status, $detail);
        };

        if ((int) $session->get('tv', -1) !== (int) $user->token_version) {
            $fail('Session revoked. Please log in again.');
        }

        $jti = $session->get('jti');
        $row = $jti ? UserSession::where('jti', $jti)->first() : null;
        if (! $row) {
            $fail('Invalid session. Please log in again.');
        }
        if ($row->revoked) {
            $fail('This session has been signed out. Please log in again.');
        }

        $idle = (int) config('auth.idle_timeout_minutes', 60);
        if ($idle > 0 && $row->last_active_at && $row->last_active_at->lt(now()->subMinutes($idle))) {
            $row->revoke('idle_timeout');
            $fail('Session expired due to inactivity. Please log in again.');
        }

        if (! $row->last_active_at || $row->last_active_at->lt(now()->subSeconds(60))) {
            $row->forceFill(['last_active_at' => now(), 'session_id' => $session->getId()])->save();
        }

        $status = $user->account_status;
        if ($status === 'inactive' || ! $user->active) {
            abort(403, 'Your account is inactive. Contact admin.');
        }
        if ($status === 'suspended') {
            abort(403, 'Your account is suspended. Contact admin.');
        }
        if ($status === 'locked') {
            abort(403, 'Your account is locked due to too many failed attempts.');
        }

        $request->attributes->set('user_session', $row);

        return $next($request);
    }
}
