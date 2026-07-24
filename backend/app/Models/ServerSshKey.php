<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSshKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'name',
        'public_key',
        'fingerprint',
        'username',
        'status',
        'error',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isInstalling(): bool
    {
        return $this->status === 'installing';
    }

    public function isInstalled(): bool
    {
        return $this->status === 'installed';
    }

    public function isRemoving(): bool
    {
        return $this->status === 'removing';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsInstalled(): void
    {
        $this->update(['status' => 'installed']);
    }

    public function markAsRemoving(): void
    {
        $this->update(['status' => 'removing']);
    }

    public function markAsFailed(string $error): void
    {
        $this->update(['status' => 'failed', 'error' => $error]);
    }

    /**
     * Single source of truth for the fingerprint formula: sha256 hex digest
     * of the decoded base64 key blob (the key line's second field).
     */
    public static function fingerprintFor(string $normalizedKey): string
    {
        $parts = preg_split('/\s+/', trim($normalizedKey), 3);
        $blob = base64_decode($parts[1] ?? '', true);

        return hash('sha256', (string) $blob);
    }
}
