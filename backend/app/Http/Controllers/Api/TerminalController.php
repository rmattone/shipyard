<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\Terminal\TerminalMessage;
use App\Services\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class TerminalController extends Controller
{
    private const INPUT_KEY_TTL = 120;

    public function __construct(
        protected TerminalService $terminalService,
    ) {}

    public function open(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'cols' => ['required', 'integer', 'min:20', 'max:500'],
            'rows' => ['required', 'integer', 'min:5', 'max:300'],
        ]);

        if ($server->isLocal()) {
            return response()->json([
                'message' => 'The web terminal is not available for the local server.',
            ], 422);
        }

        $this->terminalService->reapStale();

        $live = TerminalSession::query()
            ->whereIn('status', ['pending', 'active'])
            ->count();

        if ($live >= TerminalService::MAX_CONCURRENT) {
            return response()->json([
                'message' => 'Too many open terminal sessions. Close one and try again.',
            ], 429);
        }

        $session = TerminalSession::create([
            'user_id' => $request->user()->id,
            'server_id' => $server->id,
            'status' => 'pending',
            'cols' => $validated['cols'],
            'rows' => $validated['rows'],
        ]);

        return response()->json(['id' => $session->id], 201);
    }

    public function input(Request $request, TerminalSession $terminalSession): JsonResponse
    {
        if ($response = $this->guard($request, $terminalSession)) {
            return $response;
        }

        $validated = $request->validate([
            // ~8KB of base64: ample for pastes, bounds Redis memory.
            'd' => ['required', 'string', 'max:8192'],
        ]);

        if (base64_decode($validated['d'], true) === false) {
            return response()->json(['message' => 'Input must be base64.'], 422);
        }

        $this->push($terminalSession, TerminalMessage::input($validated['d']));

        return response()->json(['message' => 'Queued.']);
    }

    public function resize(Request $request, TerminalSession $terminalSession): JsonResponse
    {
        if ($response = $this->guard($request, $terminalSession)) {
            return $response;
        }

        $validated = $request->validate([
            'cols' => ['required', 'integer', 'min:20', 'max:500'],
            'rows' => ['required', 'integer', 'min:5', 'max:300'],
        ]);

        $this->push($terminalSession, TerminalMessage::resize($validated['cols'], $validated['rows']));

        return response()->json(['message' => 'Queued.']);
    }

    public function close(Request $request, TerminalSession $terminalSession): JsonResponse
    {
        if ($terminalSession->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($terminalSession->status === 'active') {
            // The stream loop performs the actual teardown and bookkeeping.
            $this->push($terminalSession, TerminalMessage::close());
        } elseif ($terminalSession->status === 'pending') {
            $terminalSession->markEnded('closed_by_user');
        }

        return response()->json(['message' => 'Close requested.']);
    }

    /**
     * Ownership and liveness checks shared by input and resize. The
     * session binding is unscoped (no org context on the model), so the
     * user check doubles as the tenancy check; mismatches 404 to avoid
     * leaking session existence.
     */
    private function guard(Request $request, TerminalSession $session): ?JsonResponse
    {
        if ($session->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($session->status !== 'active') {
            return response()->json(['message' => 'Session is not active.'], 409);
        }

        return null;
    }

    private function push(TerminalSession $session, string $payload): void
    {
        $key = $this->terminalService->inputKey($session->id);

        Redis::lpush($key, $payload);
        Redis::expire($key, self::INPUT_KEY_TTL);
    }
}
