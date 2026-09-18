<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Host-level operations on the ShipYard installation itself. Owner-only via
 * the route group. Version detection and the update run live in
 * SystemUpdateService and RunSystemUpdate.
 */
class SystemController extends Controller
{
    public function __construct(private SystemUpdateService $updates) {}

    public function environment(): JsonResponse
    {
        return response()->json([
            'is_docker' => $this->isRunningInDocker(),
            'docker_host_ip' => $this->isRunningInDocker() ? '172.17.0.1' : null,
        ]);
    }

    private function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv')
            || str_contains((string) @file_get_contents('/proc/1/cgroup'), 'docker');
    }

    public function version(Request $request): JsonResponse
    {
        return response()->json($this->updates->versionInfo($request->boolean('refresh')));
    }

    public function update(): JsonResponse
    {
        $this->updates->markStaleIfNeeded();

        if ($this->updates->isRunning()) {
            return response()->json([
                'success' => false,
                'message' => 'An update is already in progress',
            ], 409);
        }

        if ($this->updates->scriptPath() === null) {
            return response()->json([
                'success' => false,
                'message' => 'update.sh is not reachable from the containers. Mount the checkout at /var/www/shipyard (see docker-compose.yml) and recreate the containers.',
            ], 404);
        }

        $this->updates->start();

        return response()->json([
            'success' => true,
            'message' => 'Update queued',
            'state' => $this->updates->state(),
        ], 202);
    }

    public function updateStatus(): JsonResponse
    {
        $this->updates->markStaleIfNeeded();
        $state = $this->updates->state();

        return response()->json([
            'running' => $state['status'] === 'running',
            'status' => $state['status'],
            'log' => $this->updates->logTail(),
            'exit_code' => $state['exit_code'],
            'started_at' => $state['started_at'],
            'finished_at' => $state['finished_at'],
            'message' => $state['message'],
        ]);
    }
}
