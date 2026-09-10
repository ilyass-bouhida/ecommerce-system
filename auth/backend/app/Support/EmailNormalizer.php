<?php

namespace App\Support;

/**
 * Single normalization rule for emails, used by login, user
 * management, and the owner command alike.
 */
class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
