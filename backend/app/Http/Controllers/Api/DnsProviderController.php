<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsProvider;
use App\Services\DomainDnsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class DnsProviderController extends Controller
{
    public function __construct(
        private DomainDnsService $dnsService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            DnsProvider::withCount('domains')->orderBy('name')->get()
        );
    }

    /**
     * The token is checked against the provider before it is saved, so a
     * typo or a token without zone access fails here instead of at the
     * first domain.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(DnsProvider::TYPES)],
            'api_token' => 'required|string|max:255',
        ]);

        $provider = new DnsProvider($validated);

        try {
            $check = $this->dnsService->client($provider)->verify();
        } catch (RuntimeException $e) {
            return $this->tokenRejected($e);
        }

        $provider->save();

        return response()->json([
            ...$provider->toArray(),
            'domains_count' => 0,
            'zone_count' => $check['zone_count'],
        ], 201);
    }

    public function update(Request $request, DnsProvider $dnsProvider): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'api_token' => 'nullable|string|max:255',
        ]);

        // An empty token field keeps the stored one
        if (empty($validated['api_token'])) {
            unset($validated['api_token']);
        }

        $dnsProvider->fill($validated);

        if ($dnsProvider->isDirty('api_token')) {
            try {
                $this->dnsService->client($dnsProvider)->verify();
            } catch (RuntimeException $e) {
                return $this->tokenRejected($e);
            }
        }

        $dnsProvider->save();

        return response()->json($dnsProvider->loadCount('domains'));
    }

    public function destroy(DnsProvider $dnsProvider): JsonResponse
    {
        if ($dnsProvider->domains()->exists()) {
            return response()->json([
                'message' => 'This provider still manages DNS for some domains. Stop managing DNS for them first.',
            ], 422);
        }

        $dnsProvider->delete();

        return response()->json(null, 204);
    }

    public function test(DnsProvider $dnsProvider): JsonResponse
    {
        try {
            $check = $this->dnsService->client($dnsProvider)->verify();
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $count = $check['zone_count'];

        return response()->json([
            'success' => true,
            'message' => $count === 1 ? 'Token works. 1 zone is accessible.' : "Token works. {$count} zones are accessible.",
            'zones' => $check['zones'],
        ]);
    }

    private function tokenRejected(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => ['api_token' => [$e->getMessage()]],
        ], 422);
    }
}
