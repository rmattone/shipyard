<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TerminalSession;
use App\Services\Terminal\TerminalMessage;
use App\Services\TerminalService;
use App\Support\QueryTokenAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use phpseclib3\Net\SSH2;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SSE half of the web terminal. This request owns the SSH PTY for
 * the whole session: output flows out as SSE events while keystrokes
 * arrive through a Redis list fed by TerminalController::input. The
 * 50ms SSH read timeout is the loop's clock.
 *
 * Like the other stream routes this sits outside auth:sanctum
 * (EventSource cannot send headers), so token auth, membership, the
 * admin role, and session ownership are all enforced by hand here.
 */
class TerminalStreamController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
    ) {}

    public function stream(Request $request, TerminalSession $terminalSession): StreamedResponse
    {
        // Authenticate via query param token (EventSource doesn't support headers)
        if (! QueryTokenAuth::resolveUser($request)) {
            return $this->refuse(401);
        }

        // Unscoped binding: enforce ownership, membership in the server's
        // organization, and the admin role manually. Same body for every
        // failure: no existence leak. The role re-check matters because a
        // user demoted after opening a session must not attach.
        //
        // Null-safe: the server can be soft-deleted (trashed) while this
        // TerminalSession row survives, since cascade deletion only fires on
        // force-delete and trashing only guards against a server that still
        // has applications, not a database-only one. ->server then resolves
        // null for the whole trash retention window, and roleIn() is
        // non-nullable, so passing null through it would throw a TypeError
        // (a 500) instead of the 403 every other failure here produces.
        $user = $request->user();
        $organizationId = $terminalSession->server?->organization_id;

        if ($organizationId === null) {
            return $this->refuse(403);
        }

        $role = $user->roleIn($organizationId);

        if ($terminalSession->user_id !== $user->id
            || ! in_array($role, [Organization::ROLE_ADMIN, Organization::ROLE_OWNER], true)) {
            return $this->refuse(403);
        }

        // Single-attach mutex: one conditional UPDATE flips pending to
        // active. A second stream, an expired pending row, or an already
        // ended session all fail the WHERE and get a 409.
        $attached = TerminalSession::query()
            ->whereKey($terminalSession->id)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subSeconds(TerminalService::PENDING_TTL))
            ->update([
                'status' => 'active',
                'started_at' => now(),
                'last_seen_at' => now(),
            ]);

        if ($attached !== 1) {
            return $this->refuse(409, 'Session is not attachable.');
        }

        $sessionId = $terminalSession->id;

        $response = new StreamedResponse(function () use ($sessionId) {
            // Drain output buffers so events reach the client immediately.
            // Stops at the first buffer that refuses to close (zlib
            // compression, the test harness) rather than spinning.
            while (ob_get_level() > 0 && @ob_end_clean()) {
                // keep draining
            }

            // The finally block must run even after the browser goes away,
            // otherwise rows leak until the reaper. Abort is detected via
            // connection_aborted() after each heartbeat flush instead.
            ignore_user_abort(true);
            set_time_limit(TerminalService::MAX_DURATION + 120);

            $session = TerminalSession::with('server')->find($sessionId);
            if (! $session) {
                $this->sendEvent('end', ['reason' => 'connection_error']);

                return;
            }

            $inputKey = $this->terminalService->inputKey($sessionId);

            try {
                $ssh = $this->terminalService->open($session->server, $session->cols, $session->rows);
            } catch (\Throwable $e) {
                $this->sendEvent('end', ['reason' => 'ssh_failed', 'message' => $e->getMessage()]);
                $session->markEnded('ssh_failed');
                $this->forgetInput($inputKey);

                return;
            }

            $this->sendEvent('ready', ['session_id' => $sessionId]);

            $start = time();
            $lastActivity = time();
            $lastPing = time();
            $lastTouch = time();
            $reason = 'connection_error';

            try {
                while (true) {
                    if (connection_aborted()) {
                        $reason = 'client_disconnected';
                        break;
                    }
                    if (time() - $start > TerminalService::MAX_DURATION) {
                        $reason = 'max_duration';
                        break;
                    }
                    if (time() - $lastActivity > TerminalService::IDLE_TIMEOUT) {
                        $reason = 'idle_timeout';
                        break;
                    }

                    // Drain queued input (FIFO: LPUSH producer, RPOP here).
                    $raw = Redis::rpop($inputKey);
                    while (is_string($raw)) {
                        $msg = TerminalMessage::decode($raw);
                        $type = $msg['t'] ?? null;

                        if ($type === 'i') {
                            $bytes = base64_decode($msg['d'] ?? '', true);
                            if (is_string($bytes) && $bytes !== '') {
                                $ssh->write($bytes);
                                $lastActivity = time();
                            }
                        } elseif ($type === 'r') {
                            $ssh->sendWindowChange((int) ($msg['c'] ?? 80), (int) ($msg['r'] ?? 24));
                        } elseif ($type === 'c') {
                            $reason = 'closed_by_user';
                            break 2;
                        }

                        $raw = Redis::rpop($inputKey);
                    }

                    // Poll SSH output; the 50ms read timeout is the sleep.
                    $chunk = $ssh->read('', SSH2::READ_SIMPLE);
                    if (is_string($chunk) && $chunk !== '') {
                        $this->sendEvent('o', ['d' => base64_encode($chunk)]);
                        $lastActivity = time();
                    }

                    // isTimeout() false after a read means the channel
                    // closed: the shell exited.
                    if (! $ssh->isTimeout()) {
                        $reason = 'shell_exited';
                        break;
                    }

                    // Heartbeat: makes connection_aborted() observable and
                    // keeps nginx's read timeout from firing while idle.
                    if (time() - $lastPing >= 15) {
                        $this->sendEvent('ping', ['t' => time()]);
                        $lastPing = time();
                    }

                    // Liveness touch for the stale-session reaper.
                    if (time() - $lastTouch >= 15) {
                        TerminalSession::whereKey($sessionId)->update(['last_seen_at' => now()]);
                        $lastTouch = time();
                    }
                }
            } catch (\Throwable) {
                $reason = 'connection_error';
            } finally {
                try {
                    $this->sendEvent('end', ['reason' => $reason]);
                } catch (\Throwable) {
                    // Client already gone; nothing to tell it.
                }

                try {
                    $ssh->disconnect();
                } catch (\Throwable) {
                    // Connection already dead.
                }

                $this->forgetInput($inputKey);

                TerminalSession::whereKey($sessionId)->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'ended_reason' => $reason,
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Disable nginx buffering

        return $response;
    }

    /**
     * Drop any queued keystrokes (they can contain typed passwords).
     * Never allowed to throw: a Redis outage must not stop the session
     * row from being marked ended, or the row leaks until the reaper.
     * The key carries a TTL as the backstop.
     */
    private function forgetInput(string $inputKey): void
    {
        try {
            Redis::del($inputKey);
        } catch (\Throwable) {
            // TTL will expire the key.
        }
    }

    private function refuse(int $status, string $message = 'Unauthorized'): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendEvent('error', ['message' => $message]);
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
