<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Webhooks\WebhookHandlerFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private WebhookHandlerFactory $handlerFactory
    ) {}

    public function handle(Request $request, Application $application): JsonResponse
    {
        // Pick the handler for this application's git provider (GitLab token,
        // GitHub HMAC, or Bitbucket); defaults to GitLab for SSH-only apps.
        $handler = $this->handlerFactory->for($application);

        if (! $handler->validate($request, $application)) {
            return response()->json(['message' => 'Invalid webhook signature'], 401);
        }

        try {
            $webhookData = $handler->parse($request);
        } catch (\RuntimeException $e) {
            // Unsupported event (or a provider ping): acknowledge with 200 so
            // the provider does not flag the webhook as failing.
            return response()->json(['message' => $e->getMessage()]);
        }

        // Check if we should deploy for this branch
        if ($webhookData['branch'] !== $application->branch) {
            return response()->json([
                'message' => 'Branch does not match, skipping deployment',
                'branch' => $webhookData['branch'],
                'expected' => $application->branch,
            ]);
        }

        // Skip (with 200, so the git provider does not retry) when a
        // deployment is already in flight for this application
        if ($application->hasDeploymentInProgress()) {
            return response()->json([
                'message' => 'A deployment is already pending or running, skipping this push.',
            ]);
        }

        // For atomic deployments, generate release_id and release_path
        $releaseId = null;
        $releasePath = null;
        if ($application->usesAtomicDeployments()) {
            $releaseId = Deployment::generateReleaseId();
            $releasePath = "{$application->getReleasesPath()}/{$releaseId}";
        }

        $deployment = Deployment::create([
            'application_id' => $application->id,
            'commit_hash' => $webhookData['commit_hash'],
            'commit_message' => $webhookData['commit_message'] ? substr($webhookData['commit_message'], 0, 255) : null,
            'status' => 'pending',
            'type' => 'deploy',
            'release_id' => $releaseId,
            'release_path' => $releasePath,
        ]);

        ProcessDeployment::dispatch($deployment);

        return response()->json([
            'message' => 'Deployment queued',
            'commit' => $webhookData['commit_hash'],
            'deployment_id' => $deployment->id,
        ]);
    }
}
