<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LogController extends Controller
{
    public function __construct(
        private LogService $logService
    ) {}

    /**
     * List available log files for an application.
     */
    public function index(Application $application): JsonResponse
    {
        try {
            $files = $this->logService->getLogFiles($application);

            return response()->json([
                'files' => $files,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get log file content.
     */
    public function show(Request $request, Application $application, string $filename): JsonResponse
    {
        $validated = $request->validate([
            'lines' => 'integer|min:1|max:10000',
            'search' => 'nullable|string|max:255',
        ]);

        $lines = $validated['lines'] ?? 500;
        $search = $validated['search'] ?? null;

        try {
            $result = $this->logService->getLogContent(
                $application,
                $filename,
                $lines,
                $search
            );

            return response()->json($result);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
