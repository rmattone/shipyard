<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessRollback;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\RollbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RollbackController extends Controller
{
    public function __construct(
        private RollbackService $rollbackService
    ) {}

    /**
     * List available releases for rollback.
     */
    public function releases(Application $application): JsonResponse
    {
        if (!$application->usesAtomicDeployments()) {
            return response()->json([
                'message' => 'Rollback is only available for atomic deployments.',
                'releases' => [],
            ]);
        }

        $releases = $this->rollbackService->getAvailableReleases($application);

        return response()->json([
            'releases' => $releases,
            'current_deployment_id' => $application->activeDeployment()?->id,
        ]);
    }

    /**
     * Rollback to a specific deployment.
     */
    public function rollback(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'deployment_id' => 'required|exists:deployments,id',
        ]);

        if (!$application->usesAtomicDeployments()) {
            return response()->json([
                'message' => 'Rollback is only available for atomic deployments.',
            ], 422);
        }

        $targetDeployment = Deployment::findOrFail($validated['deployment_id']);

        // Verify target deployment belongs to this application
        if ($targetDeployment->application_id !== $application->id) {
            return response()->json([
                'message' => 'Target deployment does not belong to this application.',
            ], 422);
        }

        // Verify target deployment has a release path
        if (!$targetDeployment->release_path) {
            return response()->json([
                'message' => 'Target deployment does not have a release path.',
            ], 422);
        }

        // Verify target deployment is not already active
        if ($targetDeployment->is_active) {
            return response()->json([
                'message' => 'Target deployment is already active.',
            ], 422);
        }

        // Create rollback deployment record
        $rollbackDeployment = Deployment::create([
            'application_id' => $application->id,
            'status' => 'pending',
            'type' => 'rollback',
            'rollback_target_id' => $targetDeployment->id,
            'commit_hash' => $targetDeployment->commit_hash,
            'commit_message' => "Rollback to {$targetDeployment->release_id}",
        ]);

        ProcessRollback::dispatch($rollbackDeployment, $targetDeployment);

        return response()->json([
            'message' => 'Rollback queued successfully',
            'deployment_id' => $rollbackDeployment->id,
            'target_release_id' => $targetDeployment->release_id,
        ]);
    }

    /**
     * Quick rollback to the previous deployment.
     */
    public function rollbackToPrevious(Application $application): JsonResponse
    {
        if (!$application->usesAtomicDeployments()) {
            return response()->json([
                'message' => 'Rollback is only available for atomic deployments.',
            ], 422);
        }

        $currentDeployment = $application->activeDeployment();

        if (!$currentDeployment) {
            return response()->json([
                'message' => 'No active deployment found.',
            ], 422);
        }

        // Find the previous successful deployment
        $previousDeployment = $application->deployments()
            ->where('status', 'success')
            ->where('id', '!=', $currentDeployment->id)
            ->whereNotNull('release_path')
            ->orderByDesc('created_at')
            ->first();

        if (!$previousDeployment) {
            return response()->json([
                'message' => 'No previous deployment available for rollback.',
            ], 422);
        }

        // Create rollback deployment record
        $rollbackDeployment = Deployment::create([
            'application_id' => $application->id,
            'status' => 'pending',
            'type' => 'rollback',
            'rollback_target_id' => $previousDeployment->id,
            'commit_hash' => $previousDeployment->commit_hash,
            'commit_message' => "Rollback to previous: {$previousDeployment->release_id}",
        ]);

        ProcessRollback::dispatch($rollbackDeployment, $previousDeployment);

        return response()->json([
            'message' => 'Rollback to previous deployment queued successfully',
            'deployment_id' => $rollbackDeployment->id,
            'target_release_id' => $previousDeployment->release_id,
        ]);
    }
}
