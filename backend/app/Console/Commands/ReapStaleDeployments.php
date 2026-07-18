<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use Illuminate\Console\Command;

class ReapStaleDeployments extends Command
{
    protected $signature = 'deployments:reap-stale';

    protected $description = 'Mark deployments as failed when their worker died or the job timed out';

    /**
     * Running deployments older than the longest job timeout (1800s deploy)
     * plus a buffer can no longer be alive; their worker was killed without
     * running the failed() handler.
     */
    private const RUNNING_STALE_SECONDS = 2100;

    /**
     * Pending deployments this old were never picked up (discarded job,
     * dead queue). Comfortably exceeds any lock-release retry window.
     */
    private const PENDING_STALE_HOURS = 2;

    public function handle(): int
    {
        $reaped = 0;

        $stale = Deployment::query()
            ->where(function ($query) {
                $query->where('status', 'running')
                    ->where('started_at', '<', now()->subSeconds(self::RUNNING_STALE_SECONDS));
            })
            ->orWhere(function ($query) {
                $query->where('status', 'pending')
                    ->where('created_at', '<', now()->subHours(self::PENDING_STALE_HOURS));
            })
            ->get();

        foreach ($stale as $deployment) {
            $previousStatus = $deployment->status;

            $deployment->appendLog('ERROR: Marked as failed by the stale-deployment reaper (worker died or job timed out).');
            $deployment->markAsFailed();

            // Only touch the app status if this deployment is the one holding
            // it in "deploying"; a stale row must not clobber an app that has
            // deployed successfully since.
            $application = $deployment->application;
            if ($application && $application->status === 'deploying') {
                $application->update(['status' => 'failed']);
            }

            $this->info("Reaped {$previousStatus} deployment #{$deployment->id} for application #{$deployment->application_id}.");
            $reaped++;
        }

        $this->info("Reaped {$reaped} stale deployment(s).");

        return self::SUCCESS;
    }
}
