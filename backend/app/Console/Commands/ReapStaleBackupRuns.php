<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\BackupRestoreService;
use Illuminate\Console\Command;

class ReapStaleBackupRuns extends Command
{
    protected $signature = 'backup-runs:reap-stale';

    protected $description = 'Mark backup runs as failed when their worker died or the job timed out';

    /**
     * Running runs older than the job's own timeout ceiling
     * (BackupRestoreService::MAX_RESTORE_SECONDS) plus a buffer can no longer
     * be alive; their worker was killed without running the job's failed()
     * handler at all (a hard kill bypasses the SIGALRM-based timeout that
     * normally calls it). Derived from the service's constant rather than a
     * separately chosen number, in the same spirit as the job's own $timeout,
     * so the two cannot drift apart. 300s buffer matches the established
     * margin ReapStaleDeployments uses (2100 for a 1800s job).
     */
    private const RUNNING_STALE_SECONDS = BackupRestoreService::MAX_RESTORE_SECONDS + 300;

    /**
     * Pending runs this old were never picked up (discarded job, dead
     * queue). Comfortably exceeds any lock-release retry window.
     */
    private const PENDING_STALE_HOURS = 2;

    public function handle(): int
    {
        $reaped = 0;

        // Intentionally cross-organization: artisan never binds an
        // organization context, so this sweeps every tenant's backup runs.
        $stale = BackupRun::query()
            ->where(function ($query) {
                $query->where('status', 'running')
                    ->where('started_at', '<', now()->subSeconds(self::RUNNING_STALE_SECONDS));
            })
            ->orWhere(function ($query) {
                $query->where('status', 'pending')
                    ->where('created_at', '<', now()->subHours(self::PENDING_STALE_HOURS));
            })
            ->get();

        foreach ($stale as $run) {
            $previousStatus = $run->status;

            $run->appendLog('ERROR: Marked as failed by the stale-backup-run reaper (worker died or job timed out).');
            $run->appendUnknownStateNote();

            // Not a specific step: the reaper only ever sees a row whose
            // worker is already gone, and (like ProcessDatabaseRestore::failed())
            // has no way to know which step it died on. A pending row never
            // started at all, so there is no step to name there either. null
            // is the "unknown" this column already supports, not a guess.
            $run->markAsFailed(null);

            $this->info("Reaped {$previousStatus} backup run #{$run->id} for database #{$run->database_id}.");
            $reaped++;
        }

        $this->info("Reaped {$reaped} stale backup run(s).");

        return self::SUCCESS;
    }
}
