<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPhpUpdate;
use App\Models\Server;
use App\Services\PhpUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PhpUpdateController extends Controller
{
    public function index(Server $server, PhpUpdateService $service): JsonResponse
    {
        try {
            return response()->json(['branches' => $service->installedBranches($server)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            'branch' => ['required', 'string', 'max:10', 'regex:'.PhpUpdateService::BRANCH_PATTERN],
        ]);

        return DB::transaction(function () use ($server, $validated) {
            Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
            abort_if($server->databaseInstallations()->whereIn('status', ['pending', 'running'])->exists(), 409,
                'A software installation or update is already in progress on this server.');

            $installation = $server->databaseInstallations()->create([
                'engine' => 'php',
                'version_requested' => $validated['branch'],
                'status' => 'pending',
                'log' => "PHP {$validated['branch']} patch update queued.\n",
            ]);
            ProcessPhpUpdate::dispatch($installation)->afterCommit();

            return response()->json($installation, 202);
        });
    }
}
