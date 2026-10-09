<?php

namespace App\Http\Middleware;

use App\Services\SupplierPortal\PortalAuth;
use Closure;
use Illuminate\Http\Request;

/** Guards /api/portal/*: only a valid supplier-portal session passes. ERP sessions and tokens mean nothing here, and vice versa. */
class SupplierPortalAuth
{
    public function handle(Request $request, Closure $next)
    {
        $user = PortalAuth::resolve($request);
        if (! $user) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }
        $request->attributes->set('supplier_user', $user);

        return $next($request);
    }
}
