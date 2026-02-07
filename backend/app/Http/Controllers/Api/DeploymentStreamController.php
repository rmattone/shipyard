<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeploymentStreamController extends Controller
{
    public function stream(Request $request, Deployment $deployment): StreamedResponse
    {
        // Authenticate via query param token (EventSource doesn't support headers)
        $token = $request->query('token');
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                // Check if token has expiration and is not expired
                $isValid = !$accessToken->expires_at || $accessToken->expires_at->isFuture();
                if ($isValid) {
                    $request->setUserResolver(fn() => $accessToken->tokenable);
                }
            }
        }

        // Check authentication
        if (!$request->user()) {
            return new StreamedResponse(function () {
                $this->sendEvent('error', ['message' => 'Unauthorized']);
            }, 401, ['Content-Type' => 'text/event-stream']);
        }

        $deploymentId = $deployment->id;

        $response = new StreamedResponse(function () use ($deploymentId) {
            // Disable output buffering for real-time streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set script execution time limit (10 minutes max)
            set_time_limit(600);

            // Get fresh deployment data
            $deployment = Deployment::find($deploymentId);
            if (!$deployment) {
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
                if (!$deployment) {
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
        echo "data: " . json_encode($data) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
