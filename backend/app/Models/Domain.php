<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'domain',
        'is_primary',
        'ssl_enabled',
        'ssl_expires_at',
        'ssl_issuer',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'ssl_enabled' => 'boolean',
            'ssl_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the application that owns the domain.
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Scope a query to only include primary domains.
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope a query to only include alias (non-primary) domains.
     */
    public function scopeAliases(Builder $query): Builder
    {
        return $query->where('is_primary', false);
    }

    /**
     * Scope a query to only include domains with SSL enabled.
     */
    public function scopeWithSsl(Builder $query): Builder
    {
        return $query->where('ssl_enabled', true);
    }

    /**
     * Check if the SSL certificate is expiring soon (within 14 days).
     */
    public function isExpiringSoon(): bool
    {
        if (!$this->ssl_enabled || !$this->ssl_expires_at) {
            return false;
        }

        return $this->ssl_expires_at->lessThanOrEqualTo(Carbon::now()->addDays(14));
    }

    /**
     * Get the number of days until SSL certificate expires.
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->ssl_enabled || !$this->ssl_expires_at) {
            return null;
        }

        return (int) Carbon::now()->diffInDays($this->ssl_expires_at, false);
    }
}
