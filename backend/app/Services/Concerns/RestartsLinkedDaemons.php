<?php

namespace App\Services\Concerns;

use App\Models\Application;
use App\Models\Deployment;
use App\Support\RemoteSudo;

/**
 * Restarts the systemd daemons linked to an application after a deploy or
 * rollback so workers pick up the newly activated code. Failures are logged
 * as warnings and never thrown: by the time this runs the release is
 * already live (symlink swapped), so failing the deployment would leave the
 * DB contradicting the server. Requires an $sshService property with an
 * open connection.
 */
trait RestartsLinkedDaemons
{
    private function restartLinkedDaemons(Application $app, Deployment $deployment): void
    {
        $daemons = $app->daemons()->where('status', 'installed')->get();

        if ($daemons->isEmpty()) {
            return;
        }

        $units = $daemons->flatMap->instanceUnits()->implode(' ');
        $deployment->appendLog("Restarting {$daemons->count()} linked daemon(s)...");

        try {
            $result = $this->sshService->execute(
                RemoteSudo::wrap($app->server, "systemctl restart {$units} 2>&1"),
                120
            );

            if (! $result['success']) {
                $deployment->appendLog("WARNING: daemon restart failed: {$result['output']}");

                return;
            }

            $deployment->appendLog('Linked daemons restarted.');
        } catch (\Exception $e) {
            $deployment->appendLog("WARNING: daemon restart failed: {$e->getMessage()}");
        }
    }
}
