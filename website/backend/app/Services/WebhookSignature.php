<?php

namespace App\Services;

/**
 * HMAC-SHA256 webhook signature handling. Signs timestamp + "." + raw body.
 * Single place for the comparison so it stays timing-safe and testable.
 */
class WebhookSignature
{
    public static function payload(string $timestamp, string $rawBody): string
    {
        return $timestamp.'.'.$rawBody;
    }

    public static function sign(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', self::payload($timestamp, $rawBody), $secret);
    }

    public static function valid(string $timestamp, string $rawBody, string $header, string $secret): bool
    {
        $provided = trim($header);

        if ($provided === '' || $secret === '') {
            return false;
        }

        if (str_starts_with(strtolower($provided), 'sha256=')) {
            $provided = substr($provided, 7);
        }

        $provided = strtolower(trim($provided));

        if ($provided === '' || ! ctype_xdigit($provided)) {
            return false;
        }

        return hash_equals(self::sign($timestamp, $rawBody, $secret), $provided);
    }

    /**
     * Replay window: numeric timestamp within $toleranceSeconds of now.
     */
    public static function fresh(string $timestamp, int $toleranceSeconds): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        return abs(time() - (int) $timestamp) <= $toleranceSeconds;
    }
}
