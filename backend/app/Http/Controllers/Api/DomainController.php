<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\DnsProvider;
use App\Models\Domain;
use App\Services\CertbotService;
use App\Services\Dns\DnsRecordConflict;
use App\Services\DomainDnsService;
use App\Services\DomainService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private CertbotService $certbotService,
        private DomainDnsService $dnsService
    ) {}

    /**
     * List all domains for an application.
     */
    public function index(Application $application): JsonResponse
    {
        $domains = $application->domains()
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get();

        return response()->json($domains);
    }

    /**
     * Add a new domain to an application.
     */
    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'domain' => [
                'required',
                'string',
                'max:255',
            ],
            ...$this->dnsRules(),
        ]);

        // Trim and lowercase the domain
        $domain = strtolower(trim($validated['domain']));

        // Validate domain format
        if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return response()->json([
                'message' => 'The domain format is invalid.',
                'errors' => ['domain' => ['Please enter a valid domain (e.g., example.com or sub.example.com)']],
            ], 422);
        }

        $validated['domain'] = $domain;

        // Checked here as well as in addDomain: DNS is written before the
        // domain row exists, and must not be touched for a taken name.
        if (Domain::where('domain', $domain)->exists()) {
            return response()->json(['message' => "Domain '{$domain}' is not available"], 422);
        }

        // DNS first, so a conflict can be confirmed before anything else
        // changes. If saving the domain then fails, a record ShipYard just
        // created is removed again.
        $provider = null;
        $dns = null;
        if (! empty($validated['dns_provider_id'])) {
            $provider = DnsProvider::findOrFail($validated['dns_provider_id']);

            try {
                $dns = $this->dnsService->ensureRecord(
                    $provider,
                    $domain,
                    $application->server,
                    false,
                    (bool) ($validated['dns_overwrite'] ?? false)
                );
            } catch (DnsRecordConflict $e) {
                return $this->conflict($e);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        try {
            $newDomain = $this->domainService->addDomain($application, $domain);
        } catch (\Throwable $e) {
            if ($dns) {
                $this->dnsService->discard($provider, $dns);
            }

            if ($e instanceof RuntimeException) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            throw $e;
        }

        if ($dns) {
            $newDomain = $this->dnsService->link($newDomain, $provider, $dns, (bool) ($validated['dns_proxied'] ?? false));
        }

        return response()->json($newDomain, 201);
    }

    /**
     * Start managing DNS for an existing domain (for example the primary
     * domain created with the application).
     */
    public function linkDns(Request $request, Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate($this->dnsRules(providerRequired: true));

        $provider = DnsProvider::findOrFail($validated['dns_provider_id']);

        try {
            $domain = $this->dnsService->attach(
                $domain,
                $provider,
                (bool) ($validated['dns_proxied'] ?? false),
                (bool) ($validated['dns_overwrite'] ?? false)
            );
        } catch (DnsRecordConflict $e) {
            return $this->conflict($e);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($domain);
    }

    /**
     * Turn the Cloudflare proxy on or off for a managed domain.
     */
    public function updateDns(Request $request, Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'dns_proxied' => 'required|boolean',
        ]);

        try {
            $domain = $this->dnsService->setProxied($domain, (bool) $validated['dns_proxied']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($domain);
    }

    /**
     * Stop managing DNS. The record is deleted only if ShipYard created it
     * and delete_record is not false.
     */
    public function unlinkDns(Request $request, Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $domain = $this->dnsService->detach($domain, $request->boolean('delete_record', true));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($domain);
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    private function dnsRules(bool $providerRequired = false): array
    {
        return [
            'dns_provider_id' => [
                $providerRequired ? 'required' : 'nullable',
                'integer',
                // exists: bypasses global scopes, so constrain to the
                // current organization explicitly.
                Rule::exists('dns_providers', 'id')->where('organization_id', CurrentOrganization::id()),
            ],
            'dns_proxied' => 'nullable|boolean',
            'dns_overwrite' => 'nullable|boolean',
        ];
    }

    private function conflict(DnsRecordConflict $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'conflict' => true,
            'records' => $e->records,
        ], 409);
    }

    /**
     * Remove a domain from an application.
     */
    public function destroy(Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->domainService->removeDomain($domain);

            return response()->json(null, 204);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Set a domain as the primary domain.
     */
    public function setPrimary(Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $domain = $this->domainService->setPrimary($domain);

        return response()->json($domain);
    }

    /**
     * Request SSL certificate for a domain.
     */
    public function requestSsl(Request $request, Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $result = $this->certbotService->obtainCertificateForDomain(
                $domain,
                $validated['email']
            );

            // A proxied Cloudflare record waits for the certificate; now
            // that it exists the proxy can go on.
            $issued = $this->dnsService->activatePendingProxy($result['domain']);

            return response()->json([
                'message' => $result['message'],
                'domain' => $issued,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get SSL certificate status for a domain.
     */
    public function getSslStatus(Application $application, Domain $domain): JsonResponse
    {
        if ($domain->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $status = $this->certbotService->checkDomainStatus($domain);

            return response()->json($status);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
