<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Referenced by DeploymentService to keep the remote script timeout
    // aligned with (below) the job timeout.
    public const TIMEOUT_SECONDS = 1800; // 30 minutes

    // High tries with maxExceptions = 1: lock-blocked releases from
    // WithoutOverlapping count as attempts, but a real exception still fails
    // the job immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        public Deployment $deployment
    ) {}

    /**
     * Serialize deploys and rollbacks per application. The shared() key makes
     * ProcessDeployment and ProcessRollback contend for the same lock.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("app-pipeline:{$this->deployment->application_id}"))
                ->shared()
                ->expireAfter(1830)
                ->releaseAfter(30),
        ];
    }

    public function handle(DeploymentService $deploymentService): void
    {
        $deploymentService->runDeployment($this->deployment);
    }

    public function failed(\Throwable $exception): void
    {
        // The service catch block already logs and marks the failure; this
        // hook only covers cases where it never ran (worker killed, timeout).
        $deployment = $this->deployment->fresh();
        if ($deployment === null || $deployment->status === 'failed') {
            return;
        }

        $deployment->appendLog("ERROR: {$exception->getMessage()}");
        $deployment->markAsFailed();
        $deployment->application->update(['status' => 'failed']);
    }
}
