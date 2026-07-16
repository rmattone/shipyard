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
            'port' => 'nullable|integer|min:1|max:65535',
            'php_version' => ['nullable', 'string', 'regex:/^\d+\.\d+$/'],
            'domain' => 'nullable|string|max:255',
            'repository_url' => 'required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'deploy_path' => ['nullable', 'string', 'max:255', $this->deployPathRule()],
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

        // Resolve the effective deploy path (the model would otherwise
        // auto-generate it) so we can reject collisions before creating.
        $validated['deploy_path'] = $validated['deploy_path']
            ?? Application::generateDeployPath($validated['name']);

        if ($this->deployPathTaken($validated['server_id'], $validated['deploy_path'])) {
            return response()->json([
                'message' => 'Another application on this server already uses this deploy path. Choose a different name or deploy path.',
            ], 422);
        }

        $application = Application::create($validated);

        // Create primary domain for the application (if domain provided)
        if (! empty($validated['domain'])) {
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

        // Include deploy_script and webhook info in the response
        $response = $application->toArray();
        $response['deploy_script'] = $application->deploy_script;
        $response['webhook_url'] = $application->getWebhookUrl();
        $response['webhook_secret'] = $application->webhook_secret;

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
            'port' => 'nullable|integer|min:1|max:65535',
            'php_version' => ['nullable', 'string', 'regex:/^\d+\.\d+$/'],
            'domain' => 'sometimes|required|string|max:255',
            'repository_url' => 'sometimes|required|string|max:255',
            'branch' => 'nullable|string|max:255',
            'deploy_path' => ['sometimes', 'required', 'string', 'max:255', $this->deployPathRule()],
            'deploy_script' => 'nullable|string',
            'ssl_enabled' => 'nullable|boolean',
            'deployment_strategy' => 'nullable|in:in_place,atomic',
            'releases_to_keep' => 'nullable|integer|min:1|max:50',
            'shared_paths' => 'nullable|array',
            'shared_paths.*' => 'string',
            'writable_paths' => 'nullable|array',
            'writable_paths.*' => 'string',
        ]);

        $serverId = $validated['server_id'] ?? $application->server_id;
        if (isset($validated['deploy_path'])
            && $this->deployPathTaken($serverId, $validated['deploy_path'], $application->id)) {
            return response()->json([
                'message' => 'Another application on this server already uses this deploy path.',
            ], 422);
        }

        $oldDomain = $application->primaryDomain()?->domain ?? $application->domain;
        $application->update($validated);

        // Update the primary domain and nginx config when the domain changes.
        // NginxService reads from the domains table, so updating only the
        // legacy `domain` column would be a no-op on the served config.
        if (isset($validated['domain']) && $oldDomain !== $validated['domain']) {
            $primary = $application->primaryDomain();
            if ($primary) {
                $primary->update(['domain' => $validated['domain']]);
            } else {
                $application->domains()->create([
                    'domain' => $validated['domain'],
                    'is_primary' => true,
                    'ssl_enabled' => $application->ssl_enabled,
                ]);
            }

            try {
                // Remove the config named after the old domain, then deploy
                // under the new one (config files are named per primary domain).
                $this->nginxService->remove($application, $oldDomain);
                $this->nginxService->deploy($application->fresh(['domains']));
            } catch (\Exception $e) {
                report($e);
            }
        }

        $application->load(['gitProvider', 'domains']);

        return response()->json($application);
    }

    public function destroy(Request $request, Application $application): JsonResponse
    {
        $deleteFiles = $request->boolean('delete_files', false);

        // Remove nginx config
        try {
            $this->nginxService->remove($application);
        } catch (\Exception $e) {
            // Log but continue with deletion
        }

        // Delete files from server if requested
        if ($deleteFiles) {
            try {
                $this->deleteServerFiles($application);
            } catch (\Exception $e) {
                // Log but continue with deletion
            }
        }

        $application->delete();

        return response()->json(null, 204);
    }

    /**
     * Delete application files from the server.
     */
    private function deleteServerFiles(Application $application): void
    {
        $sshService = app(\App\Services\SSHService::class);
        $sshService->connect($application->server);

        $deployPath = $application->deploy_path;

        // Stop PM2 process for Node.js apps
        if ($application->type === 'nodejs') {
            $appName = $application->name;
            $sshService->execute("pm2 delete {$appName} 2>/dev/null || true");
            $sshService->execute('pm2 save 2>/dev/null || true');
        }

        // Remove the deployment directory, but only if it is a safe,
        // sufficiently deep path (never bare prefixes like /home/ or /var/www).
        if ($this->isSafeDeployPath($deployPath)) {
            $sshService->execute('rm -rf '.escapeshellarg($deployPath));
        }

        $sshService->disconnect();
    }

    /**
     * A deploy path validation rule: absolute, at least three levels deep,
     * no traversal. Prevents both collisions and dangerous deletions such as
     * rm -rf /home/.
     */
    private function deployPathRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value) || ! $this->isSafeDeployPath($value)) {
                $fail('The deploy path must be an absolute path at least three levels deep (for example /var/www/shipyard/app) and must not contain "..".');
            }
        };
    }

    private function isSafeDeployPath(?string $path): bool
    {
        if (empty($path) || str_contains($path, '..')) {
            return false;
        }

        // At least three path segments, e.g. /var/www/app or /home/user/app
        return (bool) preg_match('#^(/[A-Za-z0-9._-]+){3,}$#', $path);
    }

    private function deployPathTaken(int $serverId, string $deployPath, ?int $exceptId = null): bool
    {
        return Application::where('server_id', $serverId)
            ->where('deploy_path', $deployPath)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    public function deploy(Request $request, Application $application): JsonResponse
    {
        $request->validate([
            'commit_hash' => 'nullable|string|max:40',
        ]);

        if ($application->hasDeploymentInProgress()) {
            return response()->json([
                'message' => 'A deployment is already pending or running for this application.',
            ], 409);
        }

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
        if (! empty($tagIds)) {
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
