<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\SshdConfigService;
use App\Services\SSHService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class SshdSettingsController extends Controller
{
    public function show(Server $server, SshdConfigService $sshdConfigService): JsonResponse
    {
        try {
            return response()->json($sshdConfigService->getSettings($server));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Server $server, SshdConfigService $sshdConfigService, SSHService $sshService): JsonResponse
    {
        $validated = $request->validate([
            'password_authentication' => ['required', Rule::in(['yes', 'no'])],
            'permit_root_login' => ['required', Rule::in(['yes', 'no', 'prohibit-password'])],
        ]);

        // Root lockout guard: if this server is only reachable as root and
        // we set PermitRootLogin to "no", the very next connection attempt
        // (including ours) would be locked out. "prohibit-password" still
        // disables root password login while keeping key-based root access.
        if ($server->username === 'root' && $validated['permit_root_login'] === 'no') {
            return response()->json([
                'message' => 'Refusing to set PermitRootLogin to "no" on a server accessed as the root user: this would '
                    .'lock out the only configured SSH user. Use "prohibit-password" instead to keep key-based root '
                    .'login while disabling root password login.',
            ], 422);
        }

        // Re-verify the connection is actually alive right before making a
        // change that could sever it, rather than trusting a stale status.
        $connectionCheck = $sshService->testConnection($server);

        if (! $connectionCheck['success']) {
            return response()->json([
                'message' => 'Connection check failed, refusing to change sshd settings.',
            ], 409);
        }

        try {
            $settings = $sshdConfigService->applySettings(
                $server,
                $validated['password_authentication'],
                $validated['permit_root_login'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($settings);
    }
}
