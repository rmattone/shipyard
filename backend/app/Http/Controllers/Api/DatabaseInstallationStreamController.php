<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DatabaseInstallation;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseInstallationStreamController extends Controller
{
    public function stream(Request $request, DatabaseInstallation $installation): StreamedResponse
    {
        // Authenticate via query param token (EventSource doesn't support headers)
        $token = $request->query('token');
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $isValid = ! $accessToken->expires_at || $accessToken->expires_at->isFuture();
                if ($isValid) {
                    $request->setUserResolver(fn () => $accessToken->tokenable);
                }
            }
        }

        // Check authentication
        if (! $request->user()) {
            return new StreamedResponse(function () {
                $this->sendEvent('error', ['message' => 'Unauthorized']);
            }, 401, ['Content-Type' => 'text/event-stream']);
        }

        // Route is outside auth:sanctum: the binding resolved unscoped,
        // so enforce organization membership explicitly (see
        // DeploymentStreamController for rationale).
        //
        // Null-safe: the server can be soft-deleted (trashed) while this
        // DatabaseInstallation row survives, since cascade deletion only
        // fires on force-delete and trashing only guards against a server
        // that still has applications, not a database-only one. ->server
        // then resolves null for the whole trash retention window, and
        // belongsToOrganization() is non-nullable, so passing null through
        // it would throw a TypeError (a 500) instead of the 403 every other
        // failure on this route produces.
        $ownerOrganizationId = $installation->server?->organization_id;
        if ($ownerOrganizationId === null || ! $request->user()->belongsToOrganization($ownerOrganizationId)) {
            return new StreamedResponse(function () {
                $this->sendEvent('error', ['message' => 'Unauthorized']);
            }, 403, ['Content-Type' => 'text/event-stream']);
        }

        $installationId = $installation->id;

        $response = new StreamedResponse(function () use ($installationId) {
            // Disable output buffering for real-time streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Set script execution time limit (10 minutes max)
            set_time_limit(600);

            $installation = DatabaseInstallation::find($installationId);
            if (! $installation) {
                $this->sendEvent('error', ['message' => 'Installation not found']);

                return;
            }

            $lastLogLength = strlen($installation->log ?? '');

            // Send initial connection event with current log state
            $this->sendEvent('connected', [
                'installation_id' => $installation->id,
                'status' => $installation->status,
                'log' => $installation->log,
            ]);

            // If installation is already complete, send completion and close
            if (in_array($installation->status, ['success', 'failed'])) {
                $this->sendEvent('complete', [
                    'status' => $installation->status,
                    'is_complete' => true,
                ]);

                return;
            }

            // Poll for updates every 500ms
            $startTime = time();
            $timeout = 300; // 5 minutes timeout
            $lastHeartbeat = time();

            while (true) {
                if (time() - $startTime > $timeout) {
                    $this->sendEvent('timeout', ['message' => 'Connection timed out']);
                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                $installation = DatabaseInstallation::find($installationId);
                if (! $installation) {
                    $this->sendEvent('error', ['message' => 'Installation not found']);
                    break;
                }

                $currentLog = $installation->log ?? '';
                $currentLogLength = strlen($currentLog);

                if ($currentLogLength > $lastLogLength) {
                    $newContent = substr($currentLog, $lastLogLength);
                    $this->sendEvent('log', [
                        'chunk' => $newContent,
                        'timestamp' => now()->toIso8601String(),
                        'is_complete' => false,
                    ]);
                    $lastLogLength = $currentLogLength;
                }

                if (in_array($installation->status, ['success', 'failed'])) {
                    $this->sendEvent('complete', [
                        'status' => $installation->status,
                        'is_complete' => true,
                    ]);
                    break;
                }

                if (time() - $lastHeartbeat >= 15) {
                    $this->sendEvent('heartbeat', ['time' => time()]);
                    $lastHeartbeat = time();
                }

                usleep(500000);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

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
