<?php

namespace App\Jobs;

use App\Models\DatabaseInstallation;
use App\Services\PhpUpdateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPhpUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public DatabaseInstallation $installation) {}

    public function handle(PhpUpdateService $service): void
    {
        $service->update($this->installation);
    }

    public function failed(\Throwable $exception): void
    {
        $installation = $this->installation->fresh();
        if ($installation && ! $installation->isSuccess()) {
            $installation->appendLog("ERROR: {$exception->getMessage()}");
            $installation->markAsFailed();
        }
    }
}
