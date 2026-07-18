<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessScheduledTaskInstall;
use App\Jobs\ProcessScheduledTaskRemoval;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Services\CrontabService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduledTaskController extends Controller
{
    public function index(Request $request, Server $server): JsonResponse
    {
        $query = $server->scheduledTasks()->orderByDesc('created_at');

        if ($request->filled('application_id')) {
            $query->where('application_id', $request->integer('application_id'));
        }

        return response()->json($query->get());
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $cronFieldRules = [
            'required_if:frequency,custom',
            'nullable',
            'string',
            'max:30',
            'regex:/^[0-9*,\/\-]+$/',
        ];

        $validated = $request->validate([
            // No CR/LF/NUL: the command becomes one line inside a
            // root-executed crontab sync script.
            'command' => ['required', 'string', 'max:1000', 'regex:/^[^\r\n\x00]+$/'],
            'user' => ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'frequency' => ['required', 'in:minutely,hourly,nightly,weekly,monthly,reboot,custom'],
            'application_id' => [
                'nullable',
                'integer',
                Rule::exists('applications', 'id')->where('server_id', $server->id),
            ],
            'minute' => $cronFieldRules,
            'hour' => $cronFieldRules,
            'day' => $cronFieldRules,
            'month' => $cronFieldRules,
            'weekday' => $cronFieldRules,
        ]);

        if ($validated['frequency'] !== 'custom') {
            $validated['minute'] = null;
            $validated['hour'] = null;
            $validated['day'] = null;
            $validated['month'] = null;
            $validated['weekday'] = null;
        }

        $task = $server->scheduledTasks()->create([
            ...$validated,
            'status' => 'installing',
        ]);

        ProcessScheduledTaskInstall::dispatch($task);

        return response()->json($task, 202);
    }

    public function show(Server $server, ScheduledTask $scheduledTask): JsonResponse
    {
        if ($scheduledTask->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($scheduledTask);
    }

    public function output(Request $request, Server $server, ScheduledTask $scheduledTask, CrontabService $crontabService): JsonResponse
    {
        if ($scheduledTask->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'lines' => ['integer', 'min:1', 'max:2000'],
        ]);

        try {
            $result = $crontabService->readTaskOutput($scheduledTask, (int) ($validated['lines'] ?? 200));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($result);
    }

    public function destroy(Server $server, ScheduledTask $scheduledTask): JsonResponse
    {
        if ($scheduledTask->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($scheduledTask->isRemoving()) {
            return response()->json(['message' => 'Task removal is already in progress.'], 409);
        }

        $scheduledTask->markAsRemoving();
        ProcessScheduledTaskRemoval::dispatch($scheduledTask);

        return response()->json([
            'message' => 'Task removal started.',
            'task' => $scheduledTask,
        ], 202);
    }
}
