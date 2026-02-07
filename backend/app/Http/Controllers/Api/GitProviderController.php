<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GitProvider;
use App\Services\GitProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitProviderController extends Controller
{
    public function __construct(
        private GitProviderService $gitProviderService
    ) {}

    public function index(): JsonResponse
    {
        $providers = GitProvider::withCount('applications')->get();

        return response()->json($providers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:gitlab,github,bitbucket',
            'host' => 'nullable|string|max:255',
            'access_token' => 'nullable|string',
            'private_key' => 'nullable|string',
            'username' => 'required_if:type,bitbucket|nullable|string|max:255',
            'is_default' => 'nullable|boolean',
        ]);

        // Validate that at least one auth method is provided
        if (empty($validated['access_token']) && empty($validated['private_key'])) {
            return response()->json([
                'message' => 'Either access_token or private_key must be provided.',
                'errors' => [
                    'access_token' => ['Either access_token or private_key is required.'],
                    'private_key' => ['Either access_token or private_key is required.'],
                ],
            ], 422);
        }

        $validated['is_default'] = $validated['is_default'] ?? false;

        $provider = GitProvider::create($validated);

        return response()->json($provider, 201);
    }

    public function show(GitProvider $gitProvider): JsonResponse
    {
        $gitProvider->load('applications');

        return response()->json($gitProvider);
    }

    public function update(Request $request, GitProvider $gitProvider): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:gitlab,github,bitbucket',
            'host' => 'nullable|string|max:255',
            'access_token' => 'nullable|string',
            'private_key' => 'nullable|string',
            'username' => 'nullable|string|max:255',
            'is_default' => 'nullable|boolean',
        ]);

        // Only update access_token if provided (avoid overwrite with empty value)
        if (empty($validated['access_token'])) {
            unset($validated['access_token']);
        }

        // Only update private_key if provided (avoid overwrite with empty value)
        if (empty($validated['private_key'])) {
            unset($validated['private_key']);
        }

        // Handle is_default being explicitly set to false
        if (!isset($validated['is_default'])) {
            unset($validated['is_default']);
        }

        $gitProvider->update($validated);

        return response()->json($gitProvider);
    }

    public function destroy(GitProvider $gitProvider): JsonResponse
    {
        if ($gitProvider->applications()->exists()) {
            return response()->json([
                'message' => 'Cannot delete provider with associated applications. Reassign applications first.',
            ], 422);
        }

        $gitProvider->delete();

        return response()->json(null, 204);
    }

    public function testConnection(GitProvider $gitProvider): JsonResponse
    {
        try {
            $result = $this->gitProviderService->testConnection($gitProvider);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function repositories(Request $request, GitProvider $gitProvider): JsonResponse
    {
        $search = $request->query('search', '');
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);

        try {
            $result = $this->gitProviderService->listRepositories($gitProvider, $search, $page, $perPage);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function branches(Request $request, GitProvider $gitProvider): JsonResponse
    {
        $request->validate([
            'repository' => 'required|string',
        ]);

        try {
            $result = $this->gitProviderService->listBranches($gitProvider, $request->query('repository'));
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
