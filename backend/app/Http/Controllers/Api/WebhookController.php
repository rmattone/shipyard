<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\GitLabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private GitLabService $gitLabService
    ) {}

    public function handle(Request $request, Application $application): JsonResponse
    {
        // Validate webhook token
        if (!$this->gitLabService->validateWebhook($request, $application)) {
            return response()->json(['message' => 'Invalid webhook token'], 401);
        }

        try {
            $webhookData = $this->gitLabService->parseWebhookPayload($request);

            // Check if we should deploy for this branch
            if (!$this->gitLabService->shouldTriggerDeploy($application, $webhookData)) {
                return response()->json([
                    'message' => 'Branch does not match, skipping deployment',
                    'branch' => $webhookData['branch'],
                    'expected' => $application->branch,
                ]);
            }

            // Create deployment record
            $deployment = Deployment::create([
                'application_id' => $application->id,
                'commit_hash' => $webhookData['commit_hash'],
                'commit_message' => $webhookData['commit_message'],
                'status' => 'pending',
            ]);

            // Queue deployment
            ProcessDeployment::dispatch($deployment);

            return response()->json([
                'message' => 'Deployment queued',
                'commit' => $webhookData['commit_hash'],
                'deployment_id' => $deployment->id,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to process webhook',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
