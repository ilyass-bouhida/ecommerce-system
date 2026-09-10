<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteConnection extends Model
{
    public const STATUS_NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const STATUS_CONNECTED = 'CONNECTED';

    public const STATUS_DISCONNECTED = 'DISCONNECTED';

    public const STATUS_ERROR = 'ERROR';

    public const STATUSES = [
        self::STATUS_NOT_CONFIGURED,
        self::STATUS_CONNECTED,
        self::STATUS_DISCONNECTED,
        self::STATUS_ERROR,
    ];

    protected $fillable = [
        'name',
        'website_url',
        'api_base_url',
        'api_key',
        'webhook_secret',
        'is_active',
        'connection_status',
        'last_tested_at',
        'last_connected_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY. Never returned by the API.
            'api_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_connected_at' => 'datetime',
        ];
    }

    /**
     * The single connection row for the whole system.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'name' => 'Website',
            'connection_status' => self::STATUS_NOT_CONFIGURED,
        ]);
    }

    public function isConfigured(): bool
    {
        return filled($this->api_base_url);
    }

    /**
     * Safe representation — flags instead of raw secrets.
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'website_url' => $this->website_url,
            'api_base_url' => $this->api_base_url,
            'is_active' => (bool) $this->is_active,
            'connection_status' => $this->connection_status,
            'has_api_key' => filled($this->api_key),
            'has_webhook_secret' => filled($this->webhook_secret),
            'last_tested_at' => $this->last_tested_at?->toISOString(),
            'last_connected_at' => $this->last_connected_at?->toISOString(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
