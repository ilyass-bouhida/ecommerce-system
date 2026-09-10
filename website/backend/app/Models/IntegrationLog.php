<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    public const DIRECTIONS = ['INBOUND', 'OUTBOUND', 'SYSTEM'];

    public const STATUSES = ['PENDING', 'SUCCESS', 'FAILED', 'IGNORED'];

    protected $fillable = [
        'direction',
        'event_type',
        'reference_type',
        'reference_id',
        'status',
        'http_method',
        'endpoint',
        'http_status',
        'message',
        'metadata',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'http_status' => 'integer',
            'actor_user_id' => 'integer',
        ];
    }

    /**
     * Keys that must never be persisted in logs (headers, secrets, tokens).
     */
    private const SECRET_KEYS = [
        'api_key', 'apikey', 'webhook_secret', 'secret', 'signature',
        'password', 'password_confirmation', 'authorization', 'cookie',
        'set-cookie', 'x-webhook-signature', 'x-webhook-id', 'x-auth-token',
        'x-internal-key', 'token', 'access_token',
    ];

    public static function sanitize(mixed $metadata): ?array
    {
        if (! is_array($metadata)) {
            return $metadata === null ? null : ['value' => '[redacted:non-array]'];
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            $flat = strtolower((string) $key);
            if (in_array($flat, self::SECRET_KEYS, true)
                || str_contains($flat, 'secret')
                || str_contains($flat, 'password')
                || str_contains($flat, 'token')
                || str_contains($flat, 'auth')
                || str_contains($flat, 'cookie')
                || str_contains($flat, 'signature')) {
                $clean[$key] = '[redacted]';
                continue;
            }
            $clean[$key] = is_array($value) ? (self::sanitize($value) ?? []) : $value;
        }

        return $clean;
    }

    public static function record(array $attributes): self
    {
        if (array_key_exists('metadata', $attributes)) {
            $attributes['metadata'] = self::sanitize($attributes['metadata']);
        }

        return static::query()->create($attributes);
    }
}
