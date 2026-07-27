<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationChannel extends Model
{
    use BelongsToOrganization, HasFactory;

    public const TYPE_DISCORD = 'discord';

    public const TYPE_TELEGRAM = 'telegram';

    public const TYPE_EMAIL = 'email';

    public const TYPES = [
        self::TYPE_DISCORD,
        self::TYPE_TELEGRAM,
        self::TYPE_EMAIL,
    ];

    public const EVENT_SUCCEEDED = 'deployment_succeeded';

    public const EVENT_FAILED = 'deployment_failed';

    public const EVENTS = [
        self::EVENT_SUCCEEDED,
        self::EVENT_FAILED,
    ];

    protected $fillable = [
        'type',
        'name',
        'config',
        'events',
        'is_enabled',
    ];

    protected $hidden = [
        'config',
    ];

    protected $appends = [
        'config_display',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'events' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Non-secret view of the config for API responses; secrets are only
     * ever reported as present/absent (GitProvider has_* precedent).
     */
    public function getConfigDisplayAttribute(): array
    {
        $config = $this->config ?? [];

        return match ($this->type) {
            self::TYPE_DISCORD => [
                'has_webhook_url' => filled($config['webhook_url'] ?? null),
            ],
            self::TYPE_TELEGRAM => [
                'has_bot_token' => filled($config['bot_token'] ?? null),
                'chat_id' => $config['chat_id'] ?? null,
            ],
            self::TYPE_EMAIL => [
                'has_api_key' => filled($config['api_key'] ?? null),
                'from_email' => $config['from_email'] ?? null,
                'to_email' => $config['to_email'] ?? null,
            ],
            default => [],
        };
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeSubscribedTo(Builder $query, string $event): Builder
    {
        return $query->whereJsonContains('events', $event);
    }
}
