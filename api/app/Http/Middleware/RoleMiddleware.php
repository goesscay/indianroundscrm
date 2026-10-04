<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Usage: ->middleware('role:admin,sales')  ('ops' is accepted as an alias of 'operations').
 * Unauthenticated -> 401 {"detail":"Not authenticated"}; wrong role -> 403.
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();
        if (! $user) {
            abort(401, 'Not authenticated');
        }
        if (! $user->hasRole(...$roles)) {
            abort(403, 'Forbidden: insufficient role');
        }

        return $next($request);
    }
}
