<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\RollbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRollback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300; // 5 minutes - rollbacks are fast (symlink swap)

    public function __construct(
        public Deployment $rollbackDeployment,
        public ?Deployment $targetDeployment = null
    ) {}

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
