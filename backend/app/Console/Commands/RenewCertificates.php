<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\CertbotService;
use Illuminate\Console\Command;

class RenewCertificates extends Command
{
    protected $signature = 'certificates:renew';

    protected $description = 'Renew due SSL certificates on all servers and refresh domain expiry records';

    public function handle(CertbotService $certbotService): int
    {
        // Intentionally cross-organization: certificates renew for every
        // tenant (artisan runs without an organization context).
        $domains = Domain::where('ssl_enabled', true)
            ->with('application.server')
            ->get()
            ->filter(fn (Domain $domain) => $domain->application?->server !== null);

        if ($domains->isEmpty()) {
            $this->info('No SSL-enabled domains to renew.');

            return self::SUCCESS;
        }

        $failures = 0;

        // certbot renew handles every certificate on a server in one run, so
        // group by server and invoke it once per server.
        foreach ($domains->groupBy(fn (Domain $domain) => $domain->application->server_id) as $serverDomains) {
            $application = $serverDomains->first()->application;
            $serverName = $application->server->name;

            try {
                $result = $certbotService->renewCertificates($application);

                if (! $result['success']) {
                    $this->error("Renewal failed on {$serverName}: {$result['output']}");
                    $failures++;

                    continue;
                }

                $this->info("Renewal run completed on {$serverName}.");

                // Refresh stored expiry dates so the UI reflects renewed certs
                foreach ($serverDomains as $domain) {
                    try {
                        $certbotService->checkDomainStatus($domain);
                    } catch (\Exception $e) {
                        $this->warn("Could not refresh certificate status for {$domain->domain}: {$e->getMessage()}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Renewal failed on {$serverName}: {$e->getMessage()}");
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
