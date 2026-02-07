<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\NodeVersionService;
use App\Services\ServerMetricsService;
use App\Services\SSHService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        private SSHService $sshService,
        private NodeVersionService $nodeVersionService,
        private ServerMetricsService $serverMetricsService
    ) {}

    public function index(): JsonResponse
    {
        $servers = Server::withCount('applications')->get();

        return response()->json($servers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'private_key' => 'required|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['port'] = $validated['port'] ?? 22;
        $validated['status'] = $validated['status'] ?? 'active';

        $server = Server::create($validated);

        return response()->json($server, 201);
    }

    public function show(Server $server): JsonResponse
    {
        $server->load('applications');

        return response()->json($server);
    }

    public function update(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'host' => 'sometimes|required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'sometimes|required|string|max:255',
            'private_key' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        // Only update private_key if provided
        if (empty($validated['private_key'])) {
            unset($validated['private_key']);
        }

        $server->update($validated);

        return response()->json($server);
    }

    public function destroy(Server $server): JsonResponse
    {
        if ($server->applications()->exists()) {
            return response()->json([
                'message' => 'Cannot delete server with associated applications',
            ], 422);
        }

        $server->delete();

        return response()->json(null, 204);
    }

    public function testConnection(Server $server): JsonResponse
    {
        $result = $this->sshService->testConnection($server);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function getNodeVersions(Server $server): JsonResponse
    {
        $versions = $this->nodeVersionService->getInstalledVersions($server);

        return response()->json([
            'versions' => $versions,
        ]);
    }

    public function checkSoftware(Server $server): JsonResponse
    {
        try {
            $this->sshService->connect($server);

            $software = [];

            // nvm source prefix for finding nvm-managed binaries (node, npm, pm2)
            $nvmPrefix = 'export NVM_DIR="$HOME/.nvm"; [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"; [ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh" 2>/dev/null; ';

            // Check nvm
            $nvm = $this->sshService->execute($nvmPrefix . 'command -v nvm 2>/dev/null');
            if ($nvm['success'] && !empty(trim($nvm['output']))) {
                $version = $this->sshService->execute($nvmPrefix . 'nvm --version 2>/dev/null');
                $software['nvm'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['nvm'] = ['installed' => false, 'version' => null];
            }

            // Check pm2
            $pm2 = $this->sshService->execute($nvmPrefix . 'which pm2 2>/dev/null || command -v pm2 2>/dev/null');
            if ($pm2['success'] && !empty(trim($pm2['output']))) {
                $version = $this->sshService->execute($nvmPrefix . 'pm2 --version 2>/dev/null');
                $software['pm2'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['pm2'] = ['installed' => false, 'version' => null];
            }

            // Check node
            $node = $this->sshService->execute($nvmPrefix . 'which node 2>/dev/null || command -v node 2>/dev/null');
            if ($node['success'] && !empty(trim($node['output']))) {
                $version = $this->sshService->execute($nvmPrefix . 'node --version 2>/dev/null');
                $software['node'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['node'] = ['installed' => false, 'version' => null];
            }

            // Check npm
            $npm = $this->sshService->execute($nvmPrefix . 'which npm 2>/dev/null || command -v npm 2>/dev/null');
            if ($npm['success'] && !empty(trim($npm['output']))) {
                $version = $this->sshService->execute($nvmPrefix . 'npm --version 2>/dev/null');
                $software['npm'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['npm'] = ['installed' => false, 'version' => null];
            }

            // Check git
            $git = $this->sshService->execute('which git 2>/dev/null || command -v git 2>/dev/null');
            if ($git['success'] && !empty(trim($git['output']))) {
                $version = $this->sshService->execute('git --version 2>/dev/null');
                $software['git'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['git'] = ['installed' => false, 'version' => null];
            }

            // Check nginx
            $nginx = $this->sshService->execute('which nginx 2>/dev/null || command -v nginx 2>/dev/null');
            if ($nginx['success'] && !empty(trim($nginx['output']))) {
                $version = $this->sshService->execute('nginx -v 2>&1');
                $software['nginx'] = [
                    'installed' => true,
                    'version' => trim($version['output'] ?? ''),
                ];
            } else {
                $software['nginx'] = ['installed' => false, 'version' => null];
            }

            $this->sshService->disconnect();

            return response()->json($software);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to check software',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function getMetrics(Server $server): JsonResponse
    {
        try {
            $metrics = $this->serverMetricsService->getMetrics($server);

            return response()->json($metrics);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to collect metrics',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
