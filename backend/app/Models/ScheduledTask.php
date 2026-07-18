<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTask extends Model
{
    use HasFactory;

    public const FREQUENCY_EXPRESSIONS = [
        'minutely' => '* * * * *',
        'hourly' => '0 * * * *',
        'nightly' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
        'reboot' => '@reboot',
    ];

    protected $appends = ['cron_expression'];

    protected $fillable = [
        'server_id',
        'application_id',
        'command',
        'user',
        'frequency',
        'minute',
        'hour',
        'day',
        'month',
        'weekday',
        'status',
        'log',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function getCronExpressionAttribute(): string
    {
        if ($this->frequency === 'custom') {
            return "{$this->minute} {$this->hour} {$this->day} {$this->month} {$this->weekday}";
        }

        return self::FREQUENCY_EXPRESSIONS[$this->frequency];
    }

    public function getLogPath(): string
    {
        return "/var/log/shipyard/task-{$this->id}.log";
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

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $this->log = ($this->log ?? '')."[{$timestamp}] {$message}\n";
        $this->save();
    }
}
