<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const SOURCE_WEBSITE = 'website';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSED = 'PROCESSED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_IGNORED = 'IGNORED';

    /**
     * Event types the platform understands without Commerce. Everything
     * else is stored PENDING for the future Commerce service.
     */
    public const SYSTEM_EVENTS = ['ping', 'test'];

    protected $fillable = [
        'source',
        'external_event_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'received_at',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
