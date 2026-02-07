<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\CertbotService;
use App\Services\NginxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function __construct(
        private NginxService $nginxService,
        private CertbotService $certbotService
    ) {}

    public function index(): JsonResponse
    {
        $applications = Application::with(['server', 'gitProvider', 'domains', 'tags'])
            ->withCount('deployments')
            ->get();

        return response()->json($applications);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'git_provider_id' => 'nullable|exists:git_providers,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:laravel,nodejs,static',
            'node_version' => 'nullable|string|max:50',
            'domain' => 'nullable|string|max:255',
            'repository_url' => 'required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'deploy_path' => 'nullable|string|max:255',
            'deploy_script' => 'nullable|string',
            'ssl_enabled' => 'nullable|boolean',
            'deployment_strategy' => 'nullable|in:in_place,atomic',
            'releases_to_keep' => 'nullable|integer|min:1|max:50',
            'shared_paths' => 'nullable|array',
            'shared_paths.*' => 'string',
            'writable_paths' => 'nullable|array',
            'writable_paths.*' => 'string',
        ]);

        $validated['branch'] = $validated['branch'] ?? 'main';

        // deploy_path and deploy_script will be auto-generated if not provided (in model)

        $application = Application::create($validated);

        // Create primary domain for the application (if domain provided)
        if (!empty($validated['domain'])) {
            $application->domains()->create([
                'domain' => $validated['domain'],
                'is_primary' => true,
                'ssl_enabled' => $validated['ssl_enabled'] ?? false,
            ]);

            // Generate and deploy nginx config
            try {
                $this->nginxService->deploy($application);
            } catch (\Exception $e) {
                // Log but don't fail - nginx config can be deployed later
            }
        }

        $application->load(['server', 'gitProvider', 'domains']);

        return response()->json([
            'application' => $application,
            'webhook_url' => $application->getWebhookUrl(),
            'webhook_secret' => $application->webhook_secret,
        ], 201);
    }

    public function show(Application $application): JsonResponse
    {
        $application->load(['server', 'gitProvider', 'domains', 'tags', 'deployments' => function ($query) {
            $query->latest()->limit(10);
        }]);

        // Include deploy_script in the response
        $response = $application->toArray();
        $response['deploy_script'] = $application->deploy_script;

        return response()->json($response);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'server_id' => 'sometimes|exists:servers,id',
            'git_provider_id' => 'nullable|exists:git_providers,id',
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:laravel,nodejs,static',
            'node_version' => 'nullable|string|max:50',
            'domain' => 'sometimes|required|string|max:255',
            'repository_url' => 'sometimes|required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'deploy_path' => 'sometimes|required|string|max:255',
            'deploy_script' => 'nullable|string',
            'ssl_enabled' => 'nullable|boolean',
            'deployment_strategy' => 'nullable|in:in_place,atomic',
            'releases_to_keep' => 'nullable|integer|min:1|max:50',
            'shared_paths' => 'nullable|array',
            'shared_paths.*' => 'string',
            'writable_paths' => 'nullable|array',
            'writable_paths.*' => 'string',
        ]);

        $oldDomain = $application->domain;
        $application->update($validated);

        // Update nginx config if domain changed
        if (isset($validated['domain']) && $oldDomain !== $validated['domain']) {
            try {
                $this->nginxService->remove($application);
                $application->domain = $validated['domain'];
                $this->nginxService->deploy($application);
            } catch (\Exception $e) {
                // Log error
            }
        }

        $application->load('gitProvider');
        return response()->json($application);
    }

    public function destroy(Application $application): JsonResponse
    {
        try {
            $this->nginxService->remove($application);
        } catch (\Exception $e) {
            // Log but continue with deletion
        }

        $application->delete();

        return response()->json(null, 204);
    }

    public function deploy(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'commit_hash' => 'nullable|string|max:40',
        ]);

        // For atomic deployments, generate release_id and release_path
        $releaseId = null;
        $releasePath = null;
        if ($application->usesAtomicDeployments()) {
            $releaseId = Deployment::generateReleaseId();
            $releasePath = "{$application->getReleasesPath()}/{$releaseId}";
        }

        // Create deployment record immediately so we can return its ID
        $deployment = Deployment::create([
            'application_id' => $application->id,
            'commit_hash' => $request->input('commit_hash'),
            'commit_message' => $request->input('commit_message'),
            'status' => 'pending',
            'type' => 'deploy',
            'release_id' => $releaseId,
            'release_path' => $releasePath,
        ]);

        ProcessDeployment::dispatch($deployment);

        return response()->json([
            'message' => 'Deployment queued successfully',
            'deployment_id' => $deployment->id,
        ]);
    }

    public function setupSsl(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $result = $this->certbotService->obtainCertificate(
                $application,
                $request->input('email')
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function getDeployScript(Application $application): JsonResponse
    {
        return response()->json([
            'deploy_script' => $application->deploy_script ?? Application::getDefaultDeployScript($application->type),
        ]);
    }

    public function updateDeployScript(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'deploy_script' => 'required|string',
        ]);

        $application->update(['deploy_script' => $validated['deploy_script']]);

        return response()->json([
            'message' => 'Deploy script updated successfully',
            'deploy_script' => $application->deploy_script,
        ]);
    }

    public function getDefaultScript(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:laravel,nodejs,static',
            'deployment_strategy' => 'nullable|in:in_place,atomic',
        ]);

        return response()->json([
            'deploy_script' => Application::getDefaultDeployScript(
                $request->input('type'),
                $request->input('deployment_strategy', 'atomic')
            ),
        ]);
    }

    public function generateDeployPath(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        return response()->json([
            'deploy_path' => Application::generateDeployPath($request->input('name')),
        ]);
    }

    public function syncTags(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'tag_ids' => 'present|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        // Verify all tags belong to the same server as the application
        $tagIds = $validated['tag_ids'];
        if (!empty($tagIds)) {
            $validTagCount = $application->server->tags()
                ->whereIn('id', $tagIds)
                ->count();

            if ($validTagCount !== count($tagIds)) {
                return response()->json([
                    'message' => 'One or more tags do not belong to this server.',
                    'errors' => ['tag_ids' => ['All tags must belong to the same server as the application.']],
                ], 422);
            }
        }

        $application->tags()->sync($tagIds);
        $application->load('tags');

        return response()->json($application->tags);
    }
}
