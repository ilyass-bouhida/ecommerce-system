<?php

namespace App\Http\Middleware;

use App\Support\AuthCookie;
use App\Support\TokenAuth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opaque-token authentication via the HttpOnly auth_token cookie.
 * No sessions, no localStorage — the browser attaches the cookie.
 */
class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawToken = (string) $request->cookie(AuthCookie::name(), '');
        $user = TokenAuth::resolve($rawToken);

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
