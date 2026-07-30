<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\Organization;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mirrors DatabaseInstallationStreamController. This route sits outside
 * auth:sanctum because EventSource cannot send headers, so the token, the
 * organization membership, and the admin role are all checked by hand.
 *
 * The cap is 30 minutes rather than the installation stream's 5, because a
 * restore can run for up to BackupRestoreService::MAX_RESTORE_SECONDS (3000s),
 * which outlives any one stream. That is intended: each SSE stream pins one
 * php-fpm worker for its whole life (see the web terminal notes in
 * CLAUDE.md), so the cap stays well under the job's own timeout rather than
 * matching it. The client reconnects on timeout and resumes from its log
 * offset, which works because BackupRun::log is persisted on the row: a
 * fresh connection always replays the full log via the "connected" event, so
 * the frontend must replace its buffer on "connected", not append to it.
 */
class BackupRunStreamController extends Controller
{
    private const TIMEOUT_SECONDS = 1800;

    public function stream(Request $request, BackupRun $backupRun): StreamedResponse
    {
        $token = $request->query('token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && (! $accessToken->expires_at || $accessToken->expires_at->isFuture())) {
                $request->setUserResolver(fn () => $accessToken->tokenable);
            }
        }

        if (! $request->user()) {
            return $this->refuse(401);
        }

        // Unscoped binding (this route is outside auth:sanctum, so no
        // organization context is bound), so membership and the admin role
        // are enforced by hand. One identical refusal body for every
        // failure, matching TerminalStreamController: differing bodies
        // would leak whether the run exists and whether the caller is a
        // member.
        $user = $request->user();
        $organizationId = $backupRun->database->server->organization_id;
        $role = $user->roleIn($organizationId);

        if (! $user->belongsToOrganization($organizationId)
            || ! in_array($role, [Organization::ROLE_ADMIN, Organization::ROLE_OWNER], true)) {
            return $this->refuse(403);
        }

        $runId = $backupRun->id;

        $response = new StreamedResponse(function () use ($runId) {
            set_time_limit(self::TIMEOUT_SECONDS + 60);

            $run = BackupRun::withoutGlobalScopes()->find($runId);

            if (! $run) {
                $this->sendEvent('error', ['message' => 'Run not found']);

                return;
            }

            $lastLength = strlen($run->log ?? '');

            $this->sendEvent('connected', [
                'run_id' => $run->id,
                'status' => $run->status,
                'log' => $run->log,
            ]);

            if ($run->isComplete()) {
                $this->sendEvent('complete', ['status' => $run->status, 'is_complete' => true]);

                return;
            }

            // Deferred until here, right before the long-lived polling loop,
            // rather than at the very top of the closure (where the naive
            // sibling controllers, e.g. DatabaseInstallationStreamController,
            // do it): the "connected"/"complete" events above already reach
            // the client via sendEvent()'s own ob_flush()+flush(), and a run
            // that is already finished (the common reconnect-after-done case)
            // returns before this ever runs. Draining only matters for the
            // loop below, which can run for up to TIMEOUT_SECONDS and needs
            // every write to reach the client in real time rather than sit in
            // an output buffer (php.ini output_buffering, zlib compression)
            // until the script ends. Stops at the first buffer that refuses
            // to close rather than warning: draining unconditionally with a
            // bare `while (ob_get_level()) { ob_end_clean(); }`, as the naive
            // sibling controllers do, silently destroys whatever buffer
            // PHPUnit's TestResponse::streamedContent() sets up to capture
            // this exact output, before a single byte is written into it,
            // which is what a finished-run test would otherwise be exercising.
            while (ob_get_level() > 0 && @ob_end_clean()) {
                // keep draining
            }

            $startedAt = time();
            $lastHeartbeat = time();

            while (true) {
                if (time() - $startedAt > self::TIMEOUT_SECONDS) {
                    $this->sendEvent('timeout', ['message' => 'Connection timed out']);
                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                $run = BackupRun::withoutGlobalScopes()->find($runId);

                if (! $run) {
                    $this->sendEvent('error', ['message' => 'Run not found']);
                    break;
                }

                $log = $run->log ?? '';

                if (strlen($log) > $lastLength) {
                    $this->sendEvent('log', [
                        'chunk' => substr($log, $lastLength),
                        'timestamp' => now()->toIso8601String(),
                        'is_complete' => false,
                    ]);
                    $lastLength = strlen($log);
                }

                if ($run->isComplete()) {
                    $this->sendEvent('complete', ['status' => $run->status, 'is_complete' => true]);
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

    private function refuse(int $status): StreamedResponse
    {
        return new StreamedResponse(function () {
            $this->sendEvent('error', ['message' => 'Unauthorized']);
        }, $status, ['Content-Type' => 'text/event-stream']);
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
