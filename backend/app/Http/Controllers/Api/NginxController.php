<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\NginxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NginxController extends Controller
{
    public function __construct(
        private NginxService $nginxService
    ) {}

    /**
     * Get the current nginx configuration for an application.
     */
    public function show(Application $application): JsonResponse
    {
        try {
            $content = $this->nginxService->getConfigContent($application);

            return response()->json([
                'content' => $content,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the nginx configuration for an application.
     */
    public function update(Request $request, Application $application): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        try {
            $this->nginxService->updateConfigContent(
                $application,
                $validated['content']
            );

            return response()->json([
                'message' => 'Nginx configuration updated successfully',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
