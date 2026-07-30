<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnsupportedDumpException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use App\Support\DumpInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseRestoreController extends Controller
{
    /**
     * A run in either of these statuses is "in flight": pending means a job
     * is queued but has not yet claimed it, running means it has. Both are
     * dangerous to race against a second restore of the same database.
     */
    private const ACTIVE_STATUSES = ['pending', 'running'];

    public function __construct(private DumpInspector $inspector) {}

    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        // Database carries no organization scope of its own, so the parent
        // check is what keeps a scoped {server} from being paired with someone
        // else's {database}.
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'dump' => ['required', 'file', 'max:1228800'],
            'target_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_$]*$/'],
            'overwrite' => ['required', 'boolean'],
            'confirm_name' => ['nullable', 'string'],
        ]);

        $overwrite = (bool) $validated['overwrite'];
        $target = $validated['target_database'];

        if ($overwrite && ($validated['confirm_name'] ?? null) !== $target) {
            return response()->json([
                'message' => "Type the database name ({$target}) to confirm overwriting it.",
            ], 422);
        }

        $file = $request->file('dump');

        // Two copies exist transiently, the PHP upload temp file and the
        // stored one, so require headroom for both before committing.
        $required = $file->getSize() * 2;

        // storage_path('app') and the local disk root (storage/app/private)
        // are the same filesystem, so measuring the parent is sufficient.
        if (disk_free_space(storage_path('app')) < $required) {
            return response()->json([
                'message' => 'Not enough disk space on the ShipYard host to accept this dump.',
            ], 507);
        }

        try {
            $format = $this->inspector->detect($file->getRealPath());
        } catch (UnsupportedDumpException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $extension = $format === DumpInspector::FORMAT_SQL_GZ ? 'sql.gz' : 'sql';
        $path = $file->storeAs('restores', Str::uuid().'.'.$extension);

        // The concurrency refusal and the row creation must be evaluated
        // together, otherwise a second request can read "no active run" a
        // moment before the first request's insert commits. Wrapping both in
        // a transaction and taking the lock with lockForUpdate() before
        // reading is what closes that: under MySQL's default REPEATABLE READ
        // isolation, a locking SELECT against an indexed predicate (here,
        // database_id via the existing [database_id, created_at] index)
        // takes a gap lock, so a concurrent transaction's INSERT of a
        // conflicting row blocks until this one commits or rolls back rather
        // than racing it. A bare check-then-insert (two independent
        // statements, no lock) only narrows that window to however long the
        // gap between them takes, it does not close it. A unique partial
        // index (unique on database_id where status in pending/running) would
        // close it too and more cheaply, but MySQL has no partial/filtered
        // unique index, so emulating one needs a schema change (e.g. a
        // generated "active" marker column) that is out of scope for this
        // task's file list; flagged for a follow-up rather than added here.
        $inFlight = null;

        $run = DB::transaction(function () use ($database, $target, $format, $path, $file, $request, &$inFlight) {
            $existing = BackupRun::where('database_id', $database->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $inFlight = $existing;

                return null;
            }

            return BackupRun::create([
                'database_id' => $database->id,
                'user_id' => $request->user()->id,
                'kind' => BackupRun::KIND_RESTORE,
                'trigger' => 'manual',
                'source' => BackupRun::SOURCE_UPLOAD,
                'status' => 'pending',
                'database_name' => $target,
                'original_filename' => $file->getClientOriginalName(),
                'format' => $format,
                'upload_path' => $path,
                'size_bytes' => $file->getSize(),
            ]);
        });

        if ($inFlight) {
            // Nothing claimed this upload, so clean it up rather than leaving
            // an orphaned file with no BackupRun row pointing at it.
            Storage::disk('local')->delete($path);

            return response()->json([
                'message' => "A restore (run #{$inFlight->id}) is already {$inFlight->status} for this database. Wait for it to finish before starting another.",
            ], 409);
        }

        ProcessDatabaseRestore::dispatch($run->id, $overwrite);

        return response()->json(['data' => $run], 202);
    }

    public function index(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $runs = BackupRun::where('database_id', $database->id)
            ->where('kind', BackupRun::KIND_RESTORE)
            ->with('user:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        return response()->json(['data' => $backupRun->load('user:id,name')]);
    }
}
