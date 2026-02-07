<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Redis;

class DatabaseInstallation extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'engine',
        'status',
        'log',
        'version_installed',
        'admin_password',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'admin_password',
    ];

    protected function casts(): array
    {
        return [
            'admin_password' => 'encrypted',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $formattedMessage = "[{$timestamp}] {$message}\n";
        $this->log = ($this->log ?? '') . $formattedMessage;
        $this->save();

        $this->publishLogChunk($formattedMessage);
    }

    private function publishLogChunk(string $chunk): void
    {
        try {
            Redis::publish("db-installation.{$this->id}.logs", json_encode([
                'chunk' => $chunk,
                'timestamp' => now()->toIso8601String(),
                'is_complete' => false,
            ]));
        } catch (\Exception $e) {
            // Silently fail if Redis is unavailable - logs are still persisted to DB
        }
    }

    private function publishCompletion(string $status): void
    {
        try {
            Redis::publish("db-installation.{$this->id}.logs", json_encode([
                'is_complete' => true,
                'status' => $status,
                'timestamp' => now()->toIso8601String(),
            ]));
        } catch (\Exception $e) {
            // Silently fail if Redis is unavailable
        }
    }

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markAsSuccess(): void
    {
        $this->update([
            'status' => 'success',
            'finished_at' => now(),
        ]);

        $this->publishCompletion('success');
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $this->publishCompletion('failed');
    }

    public function getDuration(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return $this->finished_at->diffInSeconds($this->started_at);
    }
}
