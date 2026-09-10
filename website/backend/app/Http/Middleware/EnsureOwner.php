<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OWNER-only authorization from the introspected identity.
 */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('auth.identity');

        if (! is_array($identity)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($identity['role'] ?? '') !== 'OWNER') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
