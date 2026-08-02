<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Support\QueryTokenAuth;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeploymentStreamController extends Controller
{
    public function stream(Request $request, Deployment $deployment): StreamedResponse
    {
        // Authenticate via query param token (EventSource doesn't support headers)
        if (! QueryTokenAuth::resolveUser($request)) {
            return new StreamedResponse(function () {
                $this->sendEvent('error', ['message' => 'Unauthorized']);
            }, 401, ['Content-Type' => 'text/event-stream']);
        }

        // This route sits outside auth:sanctum, so the {deployment}
        // binding resolved without organization scoping. Require
        // membership in the owning org (any membership, not the current
        // one, so open streams survive an org switch). Same body as the
        // auth failure: no existence leak.
        //
        // Null-safe: a server with applications can never be trashed
        // (ServerController::destroy's applications guard), so this
        // particular chain is not reachable via the trash window today, but
        // the guard belongs here rather than resting on a rule enforced in
        // a different controller. ->server resolving null would otherwise
        // pass null into the non-nullable belongsToOrganization() and throw
        // a TypeError (a 500) instead of the 403 every other failure here
        // produces.
        $ownerOrganizationId = $deployment->application?->server?->organization_id;
        if ($ownerOrganizationId === null || ! $request->user()->belongsToOrganization($ownerOrganizationId)) {
            return new StreamedResponse(function () {
                $this->sendEvent('error', ['message' => 'Unauthorized']);
            }, 403, ['Content-Type' => 'text/event-stream']);
        }

        $deploymentId = $deployment->id;

        $response = new StreamedResponse(function () use ($deploymentId) {
            // Set script execution time limit (10 minutes max)
            set_time_limit(600);

            // Get fresh deployment data
            $deployment = Deployment::find($deploymentId);
            if (! $deployment) {
                $this->sendEvent('error', ['message' => 'Deployment not found']);

                return;
            }

            // Track the last known log length to detect new content
            $lastLogLength = strlen($deployment->log ?? '');

            // Send initial connection event with current log state
            $this->sendEvent('connected', [
                'deployment_id' => $deployment->id,
                'status' => $deployment->status,
                'log' => $deployment->log,
            ]);

            // If deployment is already complete, send completion and close
            if (in_array($deployment->status, ['success', 'failed'])) {
                $this->sendEvent('complete', [
                    'status' => $deployment->status,
                    'is_complete' => true,
                ]);

                return;
            }

            // Disable output buffering for the long-lived polling loop.
            // Deferred until after the early-complete return and guarded so
            // it stops at the first buffer that refuses to close: draining
            // unconditionally at the top destroys the buffer PHPUnit's
            // TestResponse::streamedContent() sets up to capture this output
            // (see BackupRunStreamController for the full rationale).
            while (ob_get_level() > 0 && @ob_end_clean()) {
                // keep draining
            }

            // Poll for updates every 500ms (much faster than 2s frontend polling)
            $startTime = time();
            $timeout = 300; // 5 minutes timeout
            $lastHeartbeat = time();

            while (true) {
                // Check for timeout
                if (time() - $startTime > $timeout) {
                    $this->sendEvent('timeout', ['message' => 'Connection timed out']);
                    break;
                }

                // Check if client disconnected
                if (connection_aborted()) {
                    break;
                }

                // Refresh deployment from database
                $deployment = Deployment::find($deploymentId);
                if (! $deployment) {
                    $this->sendEvent('error', ['message' => 'Deployment not found']);
                    break;
                }

                $currentLog = $deployment->log ?? '';
                $currentLogLength = strlen($currentLog);

                // Check if there's new log content
                if ($currentLogLength > $lastLogLength) {
                    $newContent = substr($currentLog, $lastLogLength);
                    $this->sendEvent('log', [
                        'chunk' => $newContent,
                        'timestamp' => now()->toIso8601String(),
                        'is_complete' => false,
                    ]);
                    $lastLogLength = $currentLogLength;
                }

                // Check if deployment is complete
                if (in_array($deployment->status, ['success', 'failed'])) {
                    $this->sendEvent('complete', [
                        'status' => $deployment->status,
                        'is_complete' => true,
                    ]);
                    break;
                }

                // Send heartbeat every 15 seconds
                if (time() - $lastHeartbeat >= 15) {
                    $this->sendEvent('heartbeat', ['time' => time()]);
                    $lastHeartbeat = time();
                }

                // Sleep for 500ms before next poll
                usleep(500000);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering

        return $response;
    }

    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
