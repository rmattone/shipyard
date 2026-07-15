<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\RollbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessRollback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // High tries with maxExceptions = 1: lock-blocked releases from
    // WithoutOverlapping count as attempts, but a real exception still fails
    // the job immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 300; // 5 minutes - rollbacks are fast (symlink swap)

    public function __construct(
        public Deployment $rollbackDeployment,
        public ?Deployment $targetDeployment = null
    ) {}

    /**
     * Serialize deploys and rollbacks per application. The shared() key makes
     * ProcessDeployment and ProcessRollback contend for the same lock.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("app-pipeline:{$this->rollbackDeployment->application_id}"))
                ->shared()
                ->expireAfter(330)
                ->releaseAfter(30),
        ];
    }

    public function handle(RollbackService $rollbackService): void
    {
        $app = $this->rollbackDeployment->application;

        if ($this->targetDeployment) {
            // Rollback to specific deployment
            $rollbackService->rollback($app, $this->targetDeployment, $this->rollbackDeployment);
        } else {
            // Rollback to previous deployment
            $rollbackService->rollbackToPrevious($app, $this->rollbackDeployment);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->rollbackDeployment->appendLog("ERROR: {$exception->getMessage()}");
        $this->rollbackDeployment->markAsFailed();
        $this->rollbackDeployment->application->update(['status' => 'failed']);
    }
}
