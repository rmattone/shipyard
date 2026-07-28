<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single browser terminal session. User+server scoped rather than a
 * tenant root (no BelongsToOrganization): controllers must verify
 * ownership manually, since the stream route binds unscoped.
 */
class TerminalSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'server_id',
        'status',
        'cols',
        'rows',
        'started_at',
        'ended_at',
        'last_seen_at',
        'ended_reason',
    ];

    protected function casts(): array
    {
        return [
            'cols' => 'integer',
            'rows' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function markEnded(string $reason): void
    {
        $this->update([
            'status' => 'ended',
            'ended_at' => now(),
            'ended_reason' => $reason,
        ]);
    }
}
