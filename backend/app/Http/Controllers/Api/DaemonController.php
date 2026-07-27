<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDaemonInstall;
use App\Jobs\ProcessDaemonRemoval;
use App\Models\Daemon;
use App\Models\Server;
use App\Services\SystemdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DaemonController extends Controller
{
    public function index(Request $request, Server $server): JsonResponse
    {
        $query = $server->daemons()->orderByDesc('created_at');

        if ($request->filled('application_id')) {
            $query->where('application_id', $request->integer('application_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $validated = $request->validate([
            // Single line only: the command is written verbatim into the
            // root-owned wrapper script, one line per daemon.
            'command' => ['required', 'string', 'max:1000', 'regex:/^[^\r\n\x00]+$/'],
            'user' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            // user and directory are the only values that reach the systemd
            // unit file; this allowlist is the injection defense.
            'directory' => [
                'required_without:application_id',
                'nullable',
                'string',
                'max:255',
                'regex:/^\/[A-Za-z0-9._\-\/]*$/',
                'not_regex:/\.\./',
            ],
            'processes' => ['nullable', 'integer', 'min:1', 'max:10'],
            'application_id' => [
                'nullable',
                'integer',
                Rule::exists('applications', 'id')->where('server_id', $server->id),
            ],
        ]);

        if (empty($validated['directory']) && ! empty($validated['application_id'])) {
            $application = $server->applications()->find($validated['application_id']);
            $validated['directory'] = $application->usesAtomicDeployments()
                ? $application->getCurrentPath()
                : $application->deploy_path;
        }

        $daemon = $server->daemons()->create([
            ...$validated,
            'processes' => $validated['processes'] ?? 1,
            'status' => 'installing',
        ]);

        ProcessDaemonInstall::dispatch($daemon);

        return response()->json($daemon, 202);
    }

    public function show(Server $server, Daemon $daemon): JsonResponse
    {
        if ($daemon->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($daemon);
    }

    public function status(Server $server, Daemon $daemon, SystemdService $systemdService): JsonResponse
    {
        if ($daemon->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            return response()->json($systemdService->getStatus($daemon));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function output(Request $request, Server $server, Daemon $daemon, SystemdService $systemdService): JsonResponse
    {
        if ($daemon->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'lines' => ['integer', 'min:1', 'max:2000'],
        ]);

        try {
            return response()->json($systemdService->readLogs($daemon, (int) ($validated['lines'] ?? 200)));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function restart(Server $server, Daemon $daemon, SystemdService $systemdService): JsonResponse
    {
        if ($daemon->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $daemon->isInstalled()) {
            return response()->json(['message' => 'Only installed daemons can be restarted.'], 409);
        }

        try {
            $systemdService->restartDaemon($daemon);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Daemon restarted.']);
    }

    public function destroy(Server $server, Daemon $daemon): JsonResponse
    {
        if ($daemon->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($daemon->isRemoving()) {
            return response()->json(['message' => 'Daemon removal is already in progress.'], 409);
        }

        $daemon->markAsRemoving();
        ProcessDaemonRemoval::dispatch($daemon);

        return response()->json([
            'message' => 'Daemon removal started.',
            'daemon' => $daemon,
        ], 202);
    }
}
