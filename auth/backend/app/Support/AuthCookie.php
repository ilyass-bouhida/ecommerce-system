<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The opaque auth_token cookie. HttpOnly by design: React never reads it,
 * the browser attaches it automatically (withCredentials).
 */
class AuthCookie
{
    public static function name(): string
    {
        return (string) config('auth_token.cookie', env('AUTH_COOKIE_NAME', 'auth_token'));
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('auth_token.ttl_minutes', env('AUTH_TOKEN_TTL_MINUTES', 720)));
    }

    public static function make(string $rawToken): Cookie
    {
        return new Cookie(
            self::name(),
            $rawToken,
            time() + self::ttlMinutes() * 60,
            '/',
            null,
            filter_var(env('AUTH_COOKIE_SECURE', false), FILTER_VALIDATE_BOOLEAN),
            true,
            false,
            (string) env('AUTH_COOKIE_SAMESITE', 'lax')
        );
    }

    public static function forget(): Cookie
    {
        return new Cookie(self::name(), null, 1, '/');
    }
}
