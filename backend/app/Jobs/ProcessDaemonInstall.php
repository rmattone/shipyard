<?php

namespace App\Jobs;

use App\Models\Daemon;
use App\Services\SystemdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessDaemonInstall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // High tries with maxExceptions = 1: lock-blocked releases from
    // WithoutOverlapping count as attempts, but a real exception still
    // fails the job immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Daemon $daemon
    ) {}

    public function middleware(): array
    {
        // Per-daemon key (unit files have unique names, so different
        // daemons on one server cannot interfere); shared across the
        // install/removal classes to serialize create-then-delete races.
        return [
            (new WithoutOverlapping("systemd-daemon:{$this->daemon->id}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(SystemdService $systemdService): void
    {
        $systemdService->installDaemon($this->daemon);

        $this->daemon->markAsInstalled();
    }

    public function failed(\Throwable $exception): void
    {
        $daemon = $this->daemon->fresh();

        if ($daemon === null) {
            return;
        }

        $daemon->appendLog("ERROR: {$exception->getMessage()}");
        $daemon->markAsFailed();
    }
}
