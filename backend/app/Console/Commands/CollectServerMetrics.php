<?php

namespace App\Console\Commands;

use App\Jobs\CollectServerMetrics as CollectServerMetricsJob;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Console\Command;

class CollectServerMetrics extends Command
{
    protected $signature = 'servers:collect-metrics';

    protected $description = 'Queue a resource sample for every active server and prune samples past retention';

    public function handle(): int
    {
        // Intentionally cross-organization: artisan never binds an organization
        // context, so this samples every tenant's servers. Trashed servers are
        // excluded by the soft-delete scope.
        $servers = Server::where('status', 'active')->get();

        foreach ($servers as $server) {
            CollectServerMetricsJob::dispatch($server);
        }

        $this->info("Queued metrics collection for {$servers->count()} server(s).");

        $pruned = ServerMetric::where('collected_at', '<', now()->subDays(ServerMetric::RETENTION_DAYS))->delete();

        if ($pruned > 0) {
            $this->info("Pruned {$pruned} sample(s) older than ".ServerMetric::RETENTION_DAYS.' days.');
        }

        return self::SUCCESS;
    }
}
