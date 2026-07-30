<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationThroughParent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Redis;

class BackupRun extends Model
{
    use BelongsToOrganizationThroughParent, HasFactory;

    public const KIND_BACKUP = 'backup';

    public const KIND_RESTORE = 'restore';

    public const SOURCE_S3 = 's3';

    public const SOURCE_UPLOAD = 'upload';

    public const FORMAT_SQL = 'sql';

    public const FORMAT_SQL_GZ = 'sql_gz';

    protected static function organizationParentRelation(): string
    {
        return 'database.server';
    }

    protected $fillable = [
        'backup_config_id',
        'database_id',
        'user_id',
        'kind',
        'trigger',
        'source',
        'status',
        'failed_step',
        'database_name',
        's3_key',
        'original_filename',
        'format',
        'upload_path',
        'safety_dump_path',
        'size_bytes',
        'duration_seconds',
        'log',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'upload_path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] {$message}\n";
        $this->log = ($this->log ?? '').$formatted;
        $this->save();

        try {
            Redis::publish("backup-run.{$this->id}.logs", json_encode([
                'chunk' => $formatted,
                'timestamp' => now()->toIso8601String(),
                'is_complete' => false,
            ]));
        } catch (\Exception $e) {
            // Redis being down must not fail a restore; the log is already
            // persisted and the SSE stream falls back to polling the row.
        }
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markSuccess(): void
    {
        $this->update([
            'status' => 'success',
            'finished_at' => now(),
            // started_at->diffInSeconds(now()), not the reverse: Carbon 3's
            // diffInSeconds($other) returns $other - $this, so calling it on
            // the later timestamp with the earlier one as the argument
            // yields a negative duration.
            'duration_seconds' => $this->started_at ? $this->started_at->diffInSeconds(now()) : null,
        ]);
    }

    public function markFailed(?string $step = null): void
    {
        $this->update([
            'status' => 'failed',
            'failed_step' => $step,
            'finished_at' => now(),
            'duration_seconds' => $this->started_at ? $this->started_at->diffInSeconds(now()) : null,
        ]);
    }
}
