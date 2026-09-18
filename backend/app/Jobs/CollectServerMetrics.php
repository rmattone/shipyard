<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\ServerMetricsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Take one resource sample of a server and store it. Dispatched per server by
 * servers:collect-metrics so an unreachable host (30s SSH login timeout) never
 * delays the other servers' samples.
 */
class CollectServerMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A missed sample is not worth retrying: the next tick takes a fresh one.
    public int $tries = 1;

    public int $timeout = 90;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Server $server
    ) {}

    public function middleware(): array
    {
        // If the previous sample is still running (hung SSH), drop this one
        // rather than queueing a backlog of samples for the same server.
        return [
            (new WithoutOverlapping("server-metrics:{$this->server->id}"))
                ->expireAfter(120)
                ->dontRelease(),
        ];
    }

    public function handle(ServerMetricsService $metricsService): void
    {
        $snapshot = $metricsService->getMetrics($this->server);

        ServerMetric::fromSnapshot($this->server, $snapshot)->save();
    }
}
