<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessServerSshKeyInstall;
use App\Jobs\ProcessServerSshKeyRemoval;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Services\AuthorizedKeysService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ServerSshKeyController extends Controller
{
    public function index(Server $server): JsonResponse
    {
        return response()->json($server->sshKeys()->orderByDesc('created_at')->get());
    }

    public function store(Request $request, Server $server, AuthorizedKeysService $authorizedKeysService): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n\x00]/'],
            'username' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'public_key' => ['required', 'string', 'max:8000'],
        ]);

        try {
            $normalized = $authorizedKeysService->validateAndNormalize($validated['public_key']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $duplicate = $server->sshKeys()
            ->where('username', $validated['username'])
            ->where('fingerprint', $normalized['fingerprint'])
            ->exists();

        if ($duplicate) {
            return response()->json(['message' => 'This key is already registered for this user on this server.'], 422);
        }

        try {
            $sshKey = $server->sshKeys()->create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'public_key' => $normalized['key'],
                'fingerprint' => $normalized['fingerprint'],
                'status' => 'installing',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent POST for the same server+username+fingerprint
            // won the race between the exists() check above and this
            // insert; the unique index is the actual guarantee, that
            // check is just a fast path for the common case.
            return response()->json(['message' => 'This key is already registered for this user on this server.'], 422);
        }

        ProcessServerSshKeyInstall::dispatch($sshKey);

        return response()->json($sshKey, 202);
    }

    public function destroy(Server $server, ServerSshKey $sshKey): JsonResponse
    {
        if ($sshKey->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($sshKey->isRemoving()) {
            return response()->json(['message' => 'Key removal is already in progress.'], 409);
        }

        $sshKey->markAsRemoving();
        ProcessServerSshKeyRemoval::dispatch($sshKey);

        return response()->json([
            'message' => 'SSH key removal started.',
            'ssh_key' => $sshKey,
        ], 202);
    }

    public function authorized(Request $request, Server $server, AuthorizedKeysService $authorizedKeysService): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
        ]);

        $username = $validated['username'] ?? $server->username;

        try {
            $keys = $authorizedKeysService->listAuthorizedKeys($server, $username);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['username' => $username, 'keys' => $keys]);
    }
}
