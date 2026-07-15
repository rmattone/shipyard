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

    // High tries with maxExceptions = 1: lock-blocked releases from
    // WithoutOverlapping count as attempts, but a real exception still fails
    // the job immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 1800; // 30 minutes

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
        $this->deployment->appendLog("ERROR: {$exception->getMessage()}");
        $this->deployment->markAsFailed();
        $this->deployment->application->update(['status' => 'failed']);
    }
}
