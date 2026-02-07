<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDatabaseInstallation;
use App\Models\Database;
use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DatabaseController extends Controller
{
    public function __construct(
        private DatabaseService $databaseService
    ) {}

    /**
     * Detect installed database servers on a server.
     */
    public function detect(Server $server): JsonResponse
    {
        try {
            $detected = $this->databaseService->detectDatabaseServers($server);
            return response()->json($detected);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Install a database engine on the server.
     */
    public function install(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'engine' => 'required|in:mysql,postgresql,pm2',
        ]);

        // Check for duplicate running installation
        $running = $server->databaseInstallations()
            ->where('engine', $validated['engine'])
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($running) {
            return response()->json([
                'message' => "A {$validated['engine']} installation is already in progress for this server.",
            ], 409);
        }

        $installation = $server->databaseInstallations()->create([
            'engine' => $validated['engine'],
            'status' => 'pending',
        ]);

        ProcessDatabaseInstallation::dispatch($installation);

        return response()->json($installation, 202);
    }

    /**
     * List database installations for a server.
     */
    public function installations(Server $server): JsonResponse
    {
        $installations = $server->databaseInstallations()
            ->orderByDesc('created_at')
            ->get();

        return response()->json($installations);
    }

    /**
     * Get a single database installation status.
     */
    public function installationStatus(DatabaseInstallation $installation): JsonResponse
    {
        return response()->json($installation);
    }

    /**
     * List all database connections for a server.
     */
    public function index(Server $server): JsonResponse
    {
        $databases = $server->databases()
            ->orderBy('name')
            ->get();

        return response()->json($databases);
    }

    /**
     * Create a new database connection.
     */
    public function store(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:mysql,postgresql',
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'admin_user' => 'required|string|max:255',
            'admin_password' => 'required|string',
            'charset' => 'sometimes|nullable|string|max:50',
            'collation' => 'sometimes|nullable|string|max:100',
        ]);

        // Set defaults
        $validated['host'] = $validated['host'] ?? 'localhost';
        $validated['port'] = $validated['port'] ?? ($validated['type'] === 'mysql' ? 3306 : 5432);

        // Check for duplicate name
        $exists = $server->databases()
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A database connection with this name already exists for this server.',
            ], 422);
        }

        $database = $server->databases()->create($validated);

        return response()->json($database, 201);
    }

    /**
     * Get a database connection.
     */
    public function show(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($database);
    }

    /**
     * Update a database connection.
     */
    public function update(Request $request, Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'admin_user' => 'sometimes|string|max:255',
            'admin_password' => 'sometimes|string',
            'status' => 'sometimes|in:active,inactive',
            'charset' => 'sometimes|nullable|string|max:50',
            'collation' => 'sometimes|nullable|string|max:100',
        ]);

        // Check for duplicate name if changing
        if (isset($validated['name']) && $validated['name'] !== $database->name) {
            $exists = $server->databases()
                ->where('name', $validated['name'])
                ->where('id', '!=', $database->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'A database connection with this name already exists for this server.',
                ], 422);
            }
        }

        $database->update($validated);

        return response()->json($database);
    }

    /**
     * Delete a database connection.
     */
    public function destroy(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $database->delete();

        return response()->json(null, 204);
    }

    /**
     * Test the database connection.
     */
    public function testConnection(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $result = $this->databaseService->testConnection($database);

        return response()->json($result);
    }

    /**
     * List databases on the remote server.
     */
    public function listRemoteDatabases(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $databases = $this->databaseService->listRemoteDatabases($database);
            return response()->json(['databases' => $databases]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a database on the remote server.
     */
    public function createRemoteDatabase(Request $request, Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'charset' => 'sometimes|nullable|string|max:50',
            'collation' => 'sometimes|nullable|string|max:100',
        ]);

        try {
            $this->databaseService->createDatabase(
                $database,
                $validated['name'],
                $validated['charset'] ?? null,
                $validated['collation'] ?? null
            );

            return response()->json([
                'message' => "Database '{$validated['name']}' created successfully.",
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Drop a database on the remote server.
     */
    public function dropRemoteDatabase(Request $request, Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $this->databaseService->dropDatabase($database, $validated['name']);

            return response()->json([
                'message' => "Database '{$validated['name']}' dropped successfully.",
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
