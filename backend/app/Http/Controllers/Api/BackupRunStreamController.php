<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use App\Support\QueryTokenAuth;
use Illuminate\Http\Request;
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
 *
 * Events sent: connected, log, heartbeat, complete, timeout, stream_error.
 * Deliberately "stream_error" rather than "error": a browser EventSource
 * fires its OWN "error" event (via the onerror handler) for transport-level
 * failures (dropped connection, non-200 response), and that firing is not a
 * separate channel from a same-named event we send ourselves, it is the same
 * DOM event type. Naming our fatal, per-run failures (missing run, denied
 * auth) "error" would make every one of them also trigger the client's
 * generic transport-error handling, and vice versa: a plain network blip
 * would spuriously look like one of these named failures. The three sibling
 * stream controllers (DeploymentStreamController,
 * DatabaseInstallationStreamController, TerminalStreamController) still use
 * "error" and share this same latent collision, but their frontends are
 * shipped, so changing their wire contract is a separate, deliberately
 * deferred assessment.
 */
class BackupRunStreamController extends Controller
{
    private const TIMEOUT_SECONDS = 1800;

    public function stream(Request $request, BackupRun $backupRun): StreamedResponse
    {
        $user = $this->resolveUser($request);

        if (! $user) {
            return $this->refuse(401);
        }

        // Unscoped binding (this route is outside auth:sanctum, so no
        // organization context is bound), so membership and the admin role
        // are enforced by hand. One identical refusal body for every
        // failure, matching TerminalStreamController: differing bodies
        // would leak whether the run exists and whether the caller is a
        // member.
        //
        // The chain is null-safe because the server can be soft-deleted
        // (trashed) while its Database and BackupRun rows survive: cascade
        // deletion only fires on force-delete, and ServerController::destroy
        // only guards against trashing a server that still has applications,
        // not one with only databases. A trashed server therefore resolves
        // ->server as null through the soft-delete scope for the whole trash
        // retention window, and roleIn()/belongsToOrganization() are both
        // non-nullable, so passing null through them would throw a TypeError
        // (a 500, and a stack trace leak wherever APP_DEBUG is on) instead of
        // the refusal every other failure on this route produces.
        $organizationId = $backupRun->database?->server?->organization_id;

        if ($organizationId === null) {
            return $this->refuse(403);
        }

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
                $this->sendEvent('stream_error', ['message' => 'Run not found']);

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
                    $this->sendEvent('stream_error', ['message' => 'Run not found']);
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

    /**
     * Registered as this route's ->missing() callback in routes/api.php.
     * Implicit route model binding resolves (and can fail) before stream()
     * ever runs, so without this a request for a nonexistent run id skipped
     * straight to Laravel's 404 while a real id went through the 401/403
     * checks below: the status code alone let anyone probe which ids exist,
     * across every organization, without a credential. Resolving the token
     * the same way stream() does and mapping to the same two refusal
     * outcomes (no valid user, or a valid user who simply isn't allowed to
     * see this stream) makes a missing run indistinguishable from one that
     * exists but belongs to someone else.
     */
    public function refuseMissing(Request $request): StreamedResponse
    {
        return $this->resolveUser($request) ? $this->refuse(403) : $this->refuse(401);
    }

    private function resolveUser(Request $request): ?User
    {
        return QueryTokenAuth::resolveUser($request);
    }

    private function refuse(int $status): StreamedResponse
    {
        return new StreamedResponse(function () {
            $this->sendEvent('stream_error', ['message' => 'Unauthorized']);
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
