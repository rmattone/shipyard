<?php

namespace App\Services\Concerns;

use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Uploads and runs a script on a managed server. Scripts frequently embed
 * git credentials, so the file gets an unpredictable name, is created with
 * owner-only permissions BEFORE the content is written (SFTP put reuses the
 * existing inode, keeping its mode), and is removed even when execution
 * fails. Requires an $sshService property (SSHService) on the using class.
 */
trait RunsRemoteScripts
{
    private function runRemoteScript(Server $server, string $script, int $timeout = 300): array
    {
        $scriptPath = '/tmp/shipyard-script-'.Str::random(32).'.sh';
        $quoted = escapeshellarg($scriptPath);

        $this->sshService->connect($server);
        $this->sshService->execute("touch {$quoted} && chmod 600 {$quoted}");

        $this->sshService->connectSftp($server);
        $this->sshService->uploadContent($script, $scriptPath);

        $this->sshService->connect($server);

        try {
            return $this->sshService->execute("bash {$quoted} 2>&1", $timeout);
        } finally {
            $this->sshService->execute("rm -f {$quoted}");
        }
    }
}
