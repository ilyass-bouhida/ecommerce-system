<?php

namespace App\Support;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Single place that turns an opaque token into a user:
 * exists, not expired, owner exists and is active.
 */
class TokenAuth
{
    public static function resolve(string $rawToken): ?User
    {
        if ($rawToken === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($rawToken);

        if (! $accessToken) {
            return null;
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        $user = $accessToken->tokenable;

        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        return $user;
    }

    public static function findAccessToken(string $rawToken): ?PersonalAccessToken
    {
        if ($rawToken === '') {
            return null;
        }

        return PersonalAccessToken::findToken($rawToken);
    }
}
