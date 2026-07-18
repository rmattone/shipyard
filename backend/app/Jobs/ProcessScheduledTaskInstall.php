<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use App\Services\CrontabService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessScheduledTaskInstall implements ShouldQueue
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
        public ScheduledTask $task
    ) {}

    public function middleware(): array
    {
        // Shared across install/removal jobs: crontab writes for the same
        // server+user are a read-modify-write and must not interleave.
        return [
            (new WithoutOverlapping("crontab:{$this->task->server_id}:{$this->task->user}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(CrontabService $crontabService): void
    {
        $crontabService->installTask($this->task);

        $this->task->markAsInstalled();
    }

    public function failed(\Throwable $exception): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        $task->appendLog("ERROR: {$exception->getMessage()}");
        $task->markAsFailed();
    }
}
