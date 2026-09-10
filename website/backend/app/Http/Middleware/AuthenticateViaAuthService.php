<?php

namespace App\Http\Middleware;

use App\Services\AuthClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates via the Auth microservice (HTTP introspection).
 * Attaches the safe identity array; creates no local users.
 * Auth down -> 503 (never misreported as 401).
 */
class AuthenticateViaAuthService
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = (string) $request->cookie('auth_token', '');

        if (trim($rawToken) === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $result = (new AuthClient)->introspect($rawToken);

        if ($result['state'] === 'unavailable') {
            return response()->json(['message' => 'Authentication service unavailable.'], 503);
        }

        if ($result['state'] !== 'ok') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->attributes->set('auth.identity', $result['user']);

        return $next($request);
    }
}
