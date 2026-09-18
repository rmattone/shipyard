<?php

namespace App\Jobs;

use App\Services\SystemUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Run update.sh against the mounted checkout and record the outcome.
 *
 * Runs in the queue container, which is root and shares the checkout, the
 * cache, and storage/ with the app container. Output streams to the log
 * file as it happens so the UI can follow along. update.sh ends by calling
 * queue:restart, so this worker exits after the job and Docker restarts it.
 */
class RunSystemUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('system-update'))->expireAfter(1900)->dontRelease(),
        ];
    }

    public function handle(SystemUpdateService $updates): void
    {
        $script = $updates->scriptPath();

        if ($script === null) {
            $updates->appendLog("Error: update.sh not found. Is the checkout mounted at /var/www/shipyard?\n");
            $updates->setState([
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'message' => 'update.sh not found in the container',
            ]);

            return;
        }

        $updates->appendLog('Running '.$script.' on '.gethostname()."\n\n");

        $result = Process::timeout($this->timeout - 120)
            ->env(['SHIPYARD_UPDATER' => 'ui'])
            ->run("bash {$script} 2>&1", function (string $type, string $output) use ($updates) {
                $updates->appendLog($output);
            });

        $updates->setState([
            'status' => $result->successful() ? 'completed' : 'failed',
            'exit_code' => $result->exitCode(),
            'finished_at' => now()->toIso8601String(),
            'message' => $result->successful() ? null : "update.sh exited with code {$result->exitCode()}",
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $updates = app(SystemUpdateService::class);
        $updates->appendLog("\nError: ".($exception?->getMessage() ?? 'job failed')."\n");
        $updates->setState([
            'status' => 'failed',
            'finished_at' => now()->toIso8601String(),
            'message' => $exception?->getMessage() ?? 'The update job failed',
        ]);
    }
}
