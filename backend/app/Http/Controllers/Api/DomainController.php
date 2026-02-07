<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Domain;
use App\Services\CertbotService;
use App\Services\DomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DomainController extends Controller
{
    public function __construct(
        private DomainService $domainService,
        private CertbotService $certbotService
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
        ]);

        // Trim and lowercase the domain
        $domain = strtolower(trim($validated['domain']));

        // Validate domain format
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            return response()->json([
                'message' => 'The domain format is invalid.',
                'errors' => ['domain' => ['Please enter a valid domain (e.g., example.com or sub.example.com)']],
            ], 422);
        }

        $validated['domain'] = $domain;

        try {
            $domain = $this->domainService->addDomain(
                $application,
                $validated['domain']
            );

            return response()->json($domain, 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
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

            return response()->json([
                'message' => $result['message'],
                'domain' => $result['domain'],
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
