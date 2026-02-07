<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $variables = $application->environmentVariables()->get();

        $content = $variables->map(function ($var) {
            $value = $var->value;
            // Quote values that contain spaces, special characters, or are empty
            if (preg_match('/[\s#"\'\\\\]/', $value) || $value === '') {
                $value = '"' . addslashes($value) . '"';
            }
            return $var->key . '=' . $value;
        })->implode("\n");

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

        $content = $validated['content'];
        $lines = explode("\n", $content);
        $variables = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE format
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
                $key = strtoupper($matches[1]);
                $value = $matches[2];

                // Handle quoted values
                if (preg_match('/^"(.*)"$/', $value, $quotedMatch)) {
                    $value = stripslashes($quotedMatch[1]);
                } elseif (preg_match("/^'(.*)'$/", $value, $quotedMatch)) {
                    $value = $quotedMatch[1];
                }

                $variables[$key] = $value;
            }
        }

        // Delete all existing variables
        $application->environmentVariables()->delete();

        // Create new variables
        foreach ($variables as $key => $value) {
            $application->environmentVariables()->create([
                'key' => $key,
                'value' => $value,
            ]);
        }

        return response()->json(['message' => 'Environment variables updated']);
    }
}
