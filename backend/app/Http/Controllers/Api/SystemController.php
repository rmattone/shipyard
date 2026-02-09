<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class SystemController extends Controller
{
    public function environment(): JsonResponse
    {
        return response()->json([
            'is_docker' => $this->isRunningInDocker(),
            'docker_host_ip' => $this->isRunningInDocker() ? $this->getDockerHostIp() : null,
        ]);
    }

    private function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv') || str_contains(file_get_contents('/proc/1/cgroup') ?? '', 'docker');
    }

    private function getDockerHostIp(): string
    {
        // Default Docker bridge gateway
        return '172.17.0.1';
    }

    public function version(): JsonResponse
    {
        $currentVersion = $this->getCurrentVersion();
        $latestVersion = $this->getLatestVersion();

        return response()->json([
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'update_available' => version_compare($latestVersion, $currentVersion, '>'),
        ]);
    }

    public function update(): JsonResponse
    {
        // Check if update is already running
        if (Cache::get('system_update_running')) {
            return response()->json([
                'success' => false,
                'message' => 'An update is already in progress',
            ], 409);
        }

        // Mark update as running
        Cache::put('system_update_running', true, 600); // 10 minutes max
        Cache::put('system_update_log', "Starting update...\n", 600);
        Cache::put('system_update_status', 'running', 600);

        // Run update in background - try multiple paths
        $possiblePaths = [
            '/var/www/update.sh',  // Docker mount path
            base_path('../update.sh'),
            '/var/www/shipyard/update.sh',
            dirname(base_path()) . '/update.sh',
        ];

        $scriptPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $scriptPath = $path;
                break;
            }
        }

        if (!$scriptPath) {
            Cache::forget('system_update_running');
            Cache::put('system_update_status', 'failed', 600);
            return response()->json([
                'success' => false,
                'message' => 'Update script not found. Checked: ' . implode(', ', $possiblePaths),
            ], 404);
        }

        // Execute update script in background
        Process::timeout(600)->start("bash {$scriptPath} 2>&1", function (string $type, string $output) {
            $log = Cache::get('system_update_log', '');
            Cache::put('system_update_log', $log . $output, 600);
        });

        return response()->json([
            'success' => true,
            'message' => 'Update started',
        ]);
    }

    public function updateStatus(): JsonResponse
    {
        $running = Cache::get('system_update_running', false);
        $status = Cache::get('system_update_status', 'idle');
        $log = Cache::get('system_update_log', '');

        // Check if update completed (look for completion message in log)
        if ($running && str_contains($log, 'Update complete!')) {
            Cache::forget('system_update_running');
            Cache::put('system_update_status', 'completed', 600);
            $status = 'completed';
            $running = false;
        }

        // Check for errors
        if ($running && str_contains($log, 'Error:')) {
            Cache::forget('system_update_running');
            Cache::put('system_update_status', 'failed', 600);
            $status = 'failed';
            $running = false;
        }

        return response()->json([
            'running' => $running,
            'status' => $status,
            'log' => $log,
        ]);
    }

    private function getCurrentVersion(): string
    {
        $versionFile = base_path('../VERSION');
        if (file_exists($versionFile)) {
            return trim(file_get_contents($versionFile));
        }

        // Try to get version from git
        $result = Process::run('git describe --tags --abbrev=0 2>/dev/null || git rev-parse --short HEAD');
        if ($result->successful()) {
            return trim($result->output());
        }

        return '1.0.0';
    }

    private function getLatestVersion(): string
    {
        // Cache for 1 hour to avoid hitting GitHub too often
        return Cache::remember('latest_shipyard_version', 3600, function () {
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'header' => 'User-Agent: ShipYard',
                    ],
                ]);

                $response = @file_get_contents(
                    'https://api.github.com/repos/rmattone/shipyard/releases/latest',
                    false,
                    $context
                );

                if ($response) {
                    $data = json_decode($response, true);
                    if (isset($data['tag_name'])) {
                        return ltrim($data['tag_name'], 'v');
                    }
                }

                // Fallback: check VERSION file on main branch
                $version = @file_get_contents(
                    'https://raw.githubusercontent.com/rmattone/shipyard/main/VERSION',
                    false,
                    $context
                );

                if ($version) {
                    return trim($version);
                }
            } catch (\Exception $e) {
                // Ignore errors
            }

            return $this->getCurrentVersion();
        });
    }
}
