<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DomainService
{
    public function __construct(
        private NginxService $nginxService
    ) {}

    /**
     * Add a new domain to an application.
     */
    public function addDomain(Application $application, string $domain, bool $isPrimary = false): Domain
    {
        // Check if domain already exists globally
        $existingDomain = Domain::where('domain', $domain)->first();
        if ($existingDomain) {
            throw new RuntimeException("Domain '{$domain}' is already in use");
        }

        return DB::transaction(function () use ($application, $domain, $isPrimary) {
            // If this is set as primary, unset any existing primary
            if ($isPrimary) {
                $application->domains()->where('is_primary', true)->update(['is_primary' => false]);
            }

            // If this is the first domain, make it primary automatically
            if ($application->domains()->count() === 0) {
                $isPrimary = true;
            }

            $newDomain = $application->domains()->create([
                'domain' => $domain,
                'is_primary' => $isPrimary,
                'ssl_enabled' => false,
            ]);

            // Refresh the application to include the new domain
            $application->load('domains');

            // Sync nginx configuration with all domains
            $this->syncNginxConfig($application);

            return $newDomain;
        });
    }

    /**
     * Remove a domain from an application.
     */
    public function removeDomain(Domain $domain): bool
    {
        $application = $domain->application;

        // Cannot remove the only domain
        if ($application->domains()->count() === 1) {
            throw new RuntimeException('Cannot remove the only domain. Add another domain first.');
        }

        // If removing primary, assign primary to another domain
        $wasPrimary = $domain->is_primary;

        $domain->delete();

        if ($wasPrimary) {
            $newPrimary = $application->domains()->first();
            if ($newPrimary) {
                $newPrimary->update(['is_primary' => true]);
            }
        }

        // Sync nginx configuration
        $this->syncNginxConfig($application->fresh());

        return true;
    }

    /**
     * Set a domain as the primary domain.
     */
    public function setPrimary(Domain $domain): Domain
    {
        $application = $domain->application;

        return DB::transaction(function () use ($application, $domain) {
            // Unset all primary flags
            $application->domains()->update(['is_primary' => false]);

            // Set this domain as primary
            $domain->update(['is_primary' => true]);

            // Update application's legacy domain field for backwards compatibility
            $application->update(['domain' => $domain->domain]);

            return $domain->fresh();
        });
    }

    /**
     * Regenerate nginx configuration with all domains.
     */
    public function syncNginxConfig(Application $application): bool
    {
        return $this->nginxService->deploy($application);
    }
}
