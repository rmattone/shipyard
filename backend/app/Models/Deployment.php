<?php

namespace App\Models;

use App\Jobs\SendDeploymentNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class Deployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'commit_hash',
        'commit_message',
        'status',
        'log',
        'started_at',
        'finished_at',
        'release_id',
        'release_path',
        'is_active',
        'type',
        'rollback_target_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
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
        $this->log = ($this->log ?? '').$formattedMessage;
        $this->save();

        // Publish log chunk to Redis for SSE streaming
        $this->publishLogChunk($formattedMessage);
    }

    private function publishLogChunk(string $chunk): void
    {
        try {
            Redis::publish("deployment.{$this->id}.logs", json_encode([
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
            Redis::publish("deployment.{$this->id}.logs", json_encode([
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
        $this->dispatchNotification(NotificationChannel::EVENT_SUCCEEDED);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        $this->publishCompletion('failed');
        $this->dispatchNotification(NotificationChannel::EVENT_FAILED);
    }

    private function dispatchNotification(string $event): void
    {
        try {
            SendDeploymentNotification::dispatch($this, $event);
        } catch (\Exception $e) {
            // The queue backend being unavailable must never break
            // deployment completion (same stance as publishCompletion).
        }
    }

    public function getDuration(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return $this->finished_at->diffInSeconds($this->started_at);
    }

    /**
     * Generate a timestamp-based release ID.
     * The random suffix prevents collisions when two deployments are created
     * within the same second (e.g. a webhook push racing a manual deploy).
     * The fixed-width timestamp prefix keeps lexicographic release ordering.
     */
    public static function generateReleaseId(): string
    {
        return now()->format('YmdHis').'-'.Str::lower(Str::random(6));
    }

    /**
     * Check if this is a rollback deployment.
     */
    public function isRollback(): bool
    {
        return $this->type === 'rollback';
    }

    /**
     * Check if this is a deploy deployment.
     */
    public function isDeploy(): bool
    {
        return $this->type === 'deploy';
    }

    /**
     * Mark this deployment as active and deactivate all others for the same application.
     */
    public function markAsActive(): void
    {
        DB::transaction(function () {
            // Deactivate all other deployments for this application
            static::where('application_id', $this->application_id)
                ->where('id', '!=', $this->id)
                ->update(['is_active' => false]);

            // Activate this deployment
            $this->update(['is_active' => true]);
        });
    }

    /**
     * Get the rollback target deployment (for rollback deployments).
     */
    public function rollbackTarget(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'rollback_target_id');
    }

    /**
     * Get deployments that rolled back to this deployment.
     */
    public function rollbacks()
    {
        return $this->hasMany(Deployment::class, 'rollback_target_id');
    }
}
