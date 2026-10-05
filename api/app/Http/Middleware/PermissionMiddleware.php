<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Usage: ->middleware('perm:leads.create,leads.edit')  (ANY-of; admin role bypasses; unknown keys deny).
 * Unauthenticated -> 401; missing permission -> 403 {"detail": "..."}.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Not authenticated');
        }
        if (! $user->hasAnyPermission(...$permissions)) {
            abort(403, 'Forbidden: missing permission');
        }

        return $next($request);
    }
}
