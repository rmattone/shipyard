<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class PurgeTrashedServers extends Command
{
    protected $signature = 'servers:purge-trashed';

    protected $description = 'Permanently delete servers that have been in the trash past the retention window';

    public function handle(): int
    {
        // Intentionally cross-organization: artisan never binds an
        // organization context, so this sweeps every tenant's trash.
        $expired = Server::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(Server::TRASH_RETENTION_DAYS))
            ->get();

        // Deleted one at a time rather than as a mass delete so the database
        // cascades and any model events fire per server.
        foreach ($expired as $server) {
            $server->forceDelete();

            $this->info("Purged server {$server->name} (#{$server->id}), trashed {$server->deleted_at->diffForHumans()}.");
        }

        $this->info("Purged {$expired->count()} trashed server(s).");

        return self::SUCCESS;
    }
}
