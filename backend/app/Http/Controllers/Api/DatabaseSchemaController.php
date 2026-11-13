<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Database;
use App\Models\Server;
use App\Services\DatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class DatabaseSchemaController extends Controller
{
    public function __construct(private DatabaseService $databaseService) {}

    public function index(Request $request, Server $server, Database $database): JsonResponse
    {
        $this->validateConnection($server, $database);
        $data = $request->validate(['database_name' => $this->databaseRules()]);

        try {
            return response()->json(['schemas' => $this->databaseService->listSchemas($database, $data['database_name'])]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        $this->validateConnection($server, $database);
        $data = $request->validate([
            'database_name' => $this->databaseRules(),
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/', 'not_regex:/^pg_/i', Rule::notIn(['public', 'information_schema'])],
            'owner' => ['required', 'string', 'max:63', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/'],
        ]);

        try {
            $this->databaseService->createSchema($database, $data['database_name'], $data['name'], $data['owner']);

            return response()->json(['message' => "Schema '{$data['name']}' created successfully."], 201);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function validateConnection(Server $server, Database $database): void
    {
        abort_unless($database->server_id === $server->id, 404);
        abort_unless($database->isPostgreSQL(), 422, 'Schemas are only available for PostgreSQL connections.');
    }

    private function databaseRules(): array
    {
        return ['required', 'string', 'max:63', 'regex:/^[a-zA-Z_][a-zA-Z0-9_-]*$/', Rule::notIn(['postgres', 'template0', 'template1'])];
    }
}
