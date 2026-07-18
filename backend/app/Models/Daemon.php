<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Daemon extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'application_id',
        'command',
        'user',
        'directory',
        'processes',
        'status',
        'log',
    ];

    protected function casts(): array
    {
        return [
            'processes' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
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

    public function unitName(): string
    {
        return "shipyard-daemon-{$this->id}";
    }

    public function unitFilePath(): string
    {
        return "/etc/systemd/system/{$this->unitName()}@.service";
    }

    public function wrapperPath(): string
    {
        return "/etc/shipyard/daemons/{$this->unitName()}.sh";
    }

    /**
     * @return string[] instance unit names, e.g. ["shipyard-daemon-7@1.service", ...]
     */
    public function instanceUnits(): array
    {
        return array_map(
            fn (int $i) => "{$this->unitName()}@{$i}.service",
            range(1, max(1, $this->processes))
        );
    }
}
