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

class ProcessDaemonRemoval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Daemon $daemon
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("systemd-daemon:{$this->daemon->id}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(SystemdService $systemdService): void
    {
        $systemdService->removeDaemon($this->daemon);

        $this->daemon->delete();
    }

    public function failed(\Throwable $exception): void
    {
        $daemon = $this->daemon->fresh();

        if ($daemon === null) {
            return;
        }

        // Keep the row: the units may still be live on the server, and a
        // retried DELETE can finish the cleanup.
        $daemon->appendLog("ERROR: {$exception->getMessage()}");
        $daemon->markAsFailed();
    }
}
