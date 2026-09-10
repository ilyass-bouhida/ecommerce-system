<?php

namespace App\Support;

/**
 * Internal microservice key check. Constant-time comparison so a wrong
 * key reveals nothing about the expected value.
 */
class InternalKey
{
    public static function expected(): string
    {
        return (string) config('auth_token.internal_key', env('INTERNAL_API_KEY', ''));
    }

    public static function valid(?string $provided): bool
    {
        $expected = self::expected();

        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
