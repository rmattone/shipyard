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

class ProcessScheduledTaskRemoval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public ScheduledTask $task
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("crontab:{$this->task->server_id}:{$this->task->user}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(CrontabService $crontabService): void
    {
        // The task is already status 'removing', so the regenerated block
        // excludes it; the row goes away only once the crontab write stuck.
        $crontabService->removeTask($this->task);

        $this->task->delete();
    }

    public function failed(\Throwable $exception): void
    {
        $task = $this->task->fresh();

        if ($task === null) {
            return;
        }

        // Keep the row: the cron line may still be live on the server, and
        // a retried DELETE can finish the cleanup.
        $task->appendLog("ERROR: {$exception->getMessage()}");
        $task->markAsFailed();
    }
}
