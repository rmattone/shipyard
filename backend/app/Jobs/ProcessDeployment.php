<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDeployment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // 30 minutes

    public function __construct(
        public Deployment $deployment
    ) {}

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
