<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\BackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessDatabaseRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A restore drops and recreates a database. Retrying one automatically
     * would destroy the target a second time, so it never retries.
     */
    public int $tries = 1;

    /**
     * Derived from BackupRestoreService's own per-command budgets rather than
     * a separately chosen number, so the two cannot drift apart again: an
     * earlier version hardcoded 1800 here while the service's safety dump
     * (900s) and load (1500s) run sequentially on the overwrite path, whose
     * sum alone already exceeded 1800s. A large, well-behaved restore could
     * therefore be killed mid-load without either command ever hitting its
     * own timeout. At MAX_RESTORE_SECONDS (3000s at last count) this stays
     * under the queue worker's --max-time=3600, so the worker does not exit
     * mid-restore; note --max-time only stops the worker from accepting new
     * jobs, it would not have prevented that mid-restore kill on its own.
     */
    public int $timeout = BackupRestoreService::MAX_RESTORE_SECONDS;

    /**
     * Takes the id and looks the run up with withoutGlobalScopes(), rather
     * than injecting the BackupRun model directly the way
     * ProcessDatabaseInstallation does. Model injection relies on
     * SerializesModels re-querying the model when the job wakes up, and that
     * query throws before failed() ever runs if the row is gone, so a vanished
     * run's failure would go unrecorded. Taking the id lets both handle() and
     * failed() look the run up themselves and return quietly when it is
     * missing.
     */
    public function __construct(
        private int $backupRunId,
        private bool $overwrite,
    ) {}

    public function handle(BackupRestoreService $service): void
    {
        // Queue workers run unscoped by design, so resolve without the
        // organization scope; authorization already happened in the controller.
        $run = BackupRun::withoutGlobalScopes()->find($this->backupRunId);

        if (! $run) {
            return;
        }

        $service->restoreFromUpload($run, $this->overwrite);
    }

    public function failed(\Throwable $exception): void
    {
        $run = BackupRun::withoutGlobalScopes()->find($this->backupRunId);

        if (! $run || $run->isComplete()) {
            return;
        }

        $run->appendLog('ERROR: '.$exception->getMessage());

        // The safety dump pointer matters here more than anywhere else: this
        // handler runs precisely when the service's own catch block did NOT
        // (a hard-killed worker, or the job's own $timeout firing via SIGALRM
        // mid-restore), and by the time either of those can happen on the
        // overwrite path, the safety dump has very likely already completed
        // and been persisted to safety_dump_path. Losing that pointer here
        // would be the single worst place to lose it.
        $run->appendUnknownStateNote();

        // Not 'restore': this handler cannot know which step the job was on.
        // The SIGALRM timeout can land mid-upload, mid-describe, mid-dump, or
        // mid-load, and a hard-killed worker (OOM, `kill -9`) carries no step
        // information at all; that state only ever lived in the service's
        // local $step variable and dies with the process. Asserting 'restore'
        // unconditionally was a guess dressed up as data. null is the "step
        // unknown" this column already supports as nullable, not a fabricated
        // answer.
        $run->markAsFailed(null);

        // The service's own finally block did not get to run if the worker was
        // killed, so make sure the upload is not left behind.
        if ($run->upload_path) {
            Storage::disk(BackupRun::UPLOAD_DISK)->delete($run->upload_path);
        }
    }
}
