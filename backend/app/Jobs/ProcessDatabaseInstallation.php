<?php

namespace App\Jobs;

use App\Models\DatabaseInstallation;
use App\Services\DatabaseInstallationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDatabaseInstallation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800; // 30 minutes

    public function __construct(
        public DatabaseInstallation $installation
    ) {}

    public function handle(DatabaseInstallationService $service): void
    {
        $service->install($this->installation);
    }

    public function failed(\Throwable $exception): void
    {
        $this->installation->appendLog("ERROR: {$exception->getMessage()}");
        $this->installation->markAsFailed();
    }
}
