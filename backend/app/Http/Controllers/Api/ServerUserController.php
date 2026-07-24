<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ConnectionVerificationException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ServerUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ServerUserController extends Controller
{
    private const USERNAME_RULES = ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'];

    public function index(Server $server, ServerUserService $serverUserService): JsonResponse
    {
        try {
            return response()->json(['users' => $serverUserService->listUsers($server)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request, Server $server, ServerUserService $serverUserService): JsonResponse
    {
        $validated = $request->validate([
            'username' => self::USERNAME_RULES,
            'sudo' => ['nullable', 'boolean'],
        ]);

        $sudo = $request->boolean('sudo', true);

        try {
            $serverUserService->createDeployUser($server, $validated['username'], $sudo);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json([
            'message' => "User '{$validated['username']}' created.",
            'username' => $validated['username'],
            'sudo' => $sudo,
        ], 201);
    }

    public function switchUser(Request $request, Server $server, ServerUserService $serverUserService): JsonResponse
    {
        $validated = $request->validate([
            'username' => self::USERNAME_RULES,
            'fix_ownership' => ['nullable', 'boolean'],
        ]);

        $fixOwnership = $request->boolean('fix_ownership', false);

        try {
            $server = $serverUserService->switchConnectionUser($server, $validated['username'], $fixOwnership);
        } catch (ConnectionVerificationException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($server);
    }
}
