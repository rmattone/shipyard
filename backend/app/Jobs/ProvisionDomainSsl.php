<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\CertbotService;
use App\Services\DomainDnsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProvisionDomainSsl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(public int $domainId, public string $email) {}

    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function handle(CertbotService $certbot, DomainDnsService $dns): void
    {
        $domain = Domain::find($this->domainId);
        if (! $domain || ! $domain->dns_record_id) {
            $domain?->update(['ssl_status' => null, 'ssl_error' => null]);
            return;
        }

        if (! $domain->ssl_enabled) {
            try {
                $domain = $certbot->obtainCertificateForDomain($domain, $this->email)['domain'];
            } catch (Throwable $e) {
                $domain->update(['ssl_error' => $e->getMessage()]);
                throw $e;
            }
        }

        $domain->update(['ssl_status' => 'active', 'ssl_error' => null]);
        $dns->activatePendingProxy($domain);
    }

    public function failed(Throwable $exception): void
    {
        $domain = Domain::find($this->domainId);
        if ($domain && ! $domain->ssl_enabled) {
            $domain->update(['ssl_status' => 'failed', 'ssl_error' => $exception->getMessage()]);
        }
    }
}
