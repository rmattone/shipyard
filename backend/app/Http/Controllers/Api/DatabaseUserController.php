<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Server;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DatabaseUserController extends Controller
{
    public function __construct(
        private DatabaseService $databaseService
    ) {}

    /**
     * List all tracked database users.
     */
    public function index(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $users = $database->users()
            ->orderBy('username')
            ->get();

        return response()->json($users);
    }

    /**
     * List users from the remote database server.
     */
    public function listRemoteUsers(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $users = $this->databaseService->listRemoteUsers($database);
            return response()->json(['users' => $users]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new database user.
     */
    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'username' => 'required|string|max:255|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'password' => 'required|string|min:8',
            'host' => 'sometimes|string|max:255',
        ]);

        // Set default host based on database type
        $defaultHost = $database->isMySQL() ? '%' : '*';
        $validated['host'] = $validated['host'] ?? $defaultHost;

        // Check for duplicate
        $exists = $database->users()
            ->where('username', $validated['username'])
            ->where('host', $validated['host'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A user with this username and host already exists.',
            ], 422);
        }

        try {
            // Create user on remote server
            $this->databaseService->createUser(
                $database,
                $validated['username'],
                $validated['password'],
                $validated['host']
            );

            // Track user locally
            $user = $database->users()->create([
                'username' => $validated['username'],
                'password' => $validated['password'],
                'host' => $validated['host'],
            ]);

            return response()->json($user, 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a database user.
     */
    public function show(Server $server, Database $database, DatabaseUser $user): JsonResponse
    {
        if ($database->server_id !== $server->id || $user->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Fetch current privileges from the server
        try {
            $privileges = $this->databaseService->getUserPrivileges(
                $database,
                $user->username,
                $user->host
            );
            $user->current_privileges = $privileges;
        } catch (RuntimeException $e) {
            $user->current_privileges = [];
        }

        return response()->json($user);
    }

    /**
     * Delete a database user.
     */
    public function destroy(Server $server, Database $database, DatabaseUser $user): JsonResponse
    {
        if ($database->server_id !== $server->id || $user->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            // Drop user from remote server
            $this->databaseService->dropUser(
                $database,
                $user->username,
                $user->host
            );

            // Remove local tracking
            $user->delete();

            return response()->json(null, 204);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Grant privileges to a user.
     */
    public function grantPrivileges(Request $request, Server $server, Database $database, DatabaseUser $user): JsonResponse
    {
        if ($database->server_id !== $server->id || $user->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'database' => 'required|string|max:255',
            'privileges' => 'required|array|min:1',
            'privileges.*' => 'string',
        ]);

        try {
            $this->databaseService->grantPrivileges(
                $database,
                $user->username,
                $user->host,
                $validated['database'],
                $validated['privileges']
            );

            // Update local privileges record
            $currentPrivileges = $user->privileges ?? [];
            $currentPrivileges[$validated['database']] = array_unique(
                array_merge(
                    $currentPrivileges[$validated['database']] ?? [],
                    $validated['privileges']
                )
            );
            $user->update(['privileges' => $currentPrivileges]);

            return response()->json([
                'message' => 'Privileges granted successfully.',
                'user' => $user->fresh(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke privileges from a user.
     */
    public function revokePrivileges(Request $request, Server $server, Database $database, DatabaseUser $user): JsonResponse
    {
        if ($database->server_id !== $server->id || $user->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'database' => 'required|string|max:255',
            'privileges' => 'required|array|min:1',
            'privileges.*' => 'string',
        ]);

        try {
            $this->databaseService->revokePrivileges(
                $database,
                $user->username,
                $user->host,
                $validated['database'],
                $validated['privileges']
            );

            // Update local privileges record
            $currentPrivileges = $user->privileges ?? [];
            if (isset($currentPrivileges[$validated['database']])) {
                $currentPrivileges[$validated['database']] = array_values(
                    array_diff(
                        $currentPrivileges[$validated['database']],
                        $validated['privileges']
                    )
                );
                if (empty($currentPrivileges[$validated['database']])) {
                    unset($currentPrivileges[$validated['database']]);
                }
            }
            $user->update(['privileges' => $currentPrivileges ?: null]);

            return response()->json([
                'message' => 'Privileges revoked successfully.',
                'user' => $user->fresh(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
