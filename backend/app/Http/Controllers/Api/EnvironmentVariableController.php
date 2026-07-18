<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Services\EnvSyncService;
use App\Support\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnvironmentVariableController extends Controller
{
    public function index(Application $application): JsonResponse
    {
        $variables = $application->environmentVariables()->get(['id', 'key', 'created_at', 'updated_at']);

        return response()->json($variables);
    }

    public function store(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'key' => 'required|string|max:255|regex:/^[A-Z][A-Z0-9_]*$/',
            'value' => 'required|string',
        ]);

        // Check for duplicate key
        $exists = $application->environmentVariables()
            ->where('key', $validated['key'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Environment variable already exists',
            ], 422);
        }

        $variable = $application->environmentVariables()->create($validated);

        return response()->json([
            'id' => $variable->id,
            'key' => $variable->key,
            'created_at' => $variable->created_at,
            'updated_at' => $variable->updated_at,
        ], 201);
    }

    public function update(Request $request, Application $application, EnvironmentVariable $environmentVariable): JsonResponse
    {
        if ($environmentVariable->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'key' => 'sometimes|required|string|max:255|regex:/^[A-Z][A-Z0-9_]*$/',
            'value' => 'sometimes|required|string',
        ]);

        // Check for duplicate key if changing
        if (isset($validated['key']) && $validated['key'] !== $environmentVariable->key) {
            $exists = $application->environmentVariables()
                ->where('key', $validated['key'])
                ->where('id', '!=', $environmentVariable->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Environment variable key already exists',
                ], 422);
            }
        }

        $environmentVariable->update($validated);

        return response()->json([
            'id' => $environmentVariable->id,
            'key' => $environmentVariable->key,
            'created_at' => $environmentVariable->created_at,
            'updated_at' => $environmentVariable->updated_at,
        ]);
    }

    public function destroy(Application $application, EnvironmentVariable $environmentVariable): JsonResponse
    {
        if ($environmentVariable->application_id !== $application->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $environmentVariable->delete();

        return response()->json(null, 204);
    }

    /**
     * Get the full .env file content
     */
    public function getEnvFile(Application $application): JsonResponse
    {
        $content = EnvFile::render(
            $application->env_layout,
            $application->environmentVariables()->orderBy('id')->get()
        );

        return response()->json(['content' => $content]);
    }

    /**
     * Update the full .env file content
     */
    public function updateEnvFile(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'present|string',
        ]);

        $document = EnvFile::parseDocument($validated['content']);

        // Replace the set atomically: a mid-loop encryption/DB error must not
        // leave the application with its secrets half-deleted.
        DB::transaction(function () use ($application, $document) {
            $application->environmentVariables()->delete();

            foreach ($document['variables'] as $key => $value) {
                $application->environmentVariables()->create([
                    'key' => $key,
                    'value' => $value,
                ]);
            }

            // Keep the file's structure (comments, blank lines, key order)
            // so later renders don't compact what the user wrote
            $application->update(['env_layout' => $document['layout']]);
        });

        // Auto-sync to server if the app has been deployed
        $syncResult = null;
        if ($application->deployments()->exists()) {
            $syncService = app(EnvSyncService::class);
            $syncResult = $syncService->syncToServer($application);
        } else {
            $syncResult = [
                'synced' => false,
                'message' => 'App not deployed yet',
            ];
        }

        return response()->json([
            'message' => 'Environment variables updated',
            'sync_result' => $syncResult,
        ]);
    }
}
