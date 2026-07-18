<?php

namespace App\Services;

use App\Models\ScheduledTask;
use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manages scheduled tasks on target servers through per-user crontabs.
 * Every write regenerates a marker-delimited block from the DB, so the
 * remote crontab converges to panel state while anything the user keeps
 * outside the block survives untouched. Writes for the same server+user
 * must be serialized by the caller (see the WithoutOverlapping key on the
 * scheduled-task jobs); the read-modify-write here is not atomic.
 */
class CrontabService
{
    use RunsRemoteScripts;

    private const BLOCK_BEGIN = '# BEGIN SHIPYARD MANAGED TASKS - DO NOT EDIT';

    private const BLOCK_END = '# END SHIPYARD MANAGED TASKS';

    private const NO_LOG_MARKER = '__SHIPYARD_NO_LOG__';

    public function __construct(
        protected SSHService $sshService,
    ) {}

    public function installTask(ScheduledTask $task): void
    {
        $logPath = escapeshellarg($task->getLogPath());
        $user = escapeshellarg($task->user);

        // The log file is pre-created owned by the task's user so the cron
        // job can append without a world-writable directory. A nonexistent
        // user fails here, which is the signal we want.
        $prelude = implode("\n", [
            '$SUDO install -d -m 0755 /var/log/shipyard',
            "\$SUDO touch {$logPath}",
            "\$SUDO chown {$user} {$logPath}",
            "\$SUDO chmod 0640 {$logPath}",
        ]);

        $this->syncCrontab($task->server, $task->user, $prelude);
    }

    public function removeTask(ScheduledTask $task): void
    {
        $logPath = escapeshellarg($task->getLogPath());

        $this->syncCrontab($task->server, $task->user, '', "\$SUDO rm -f {$logPath}");
    }

    /**
     * @return array{output: string, exists: bool}
     */
    public function readTaskOutput(ScheduledTask $task, int $lines = 200): array
    {
        $lines = max(1, min($lines, 2000));
        $path = escapeshellarg($task->getLogPath());
        $marker = self::NO_LOG_MARKER;
        $command = "if [ -f {$path} ]; then tail -n {$lines} {$path}; else echo '{$marker}'; fi";

        try {
            $this->sshService->connect($task->server);
            $result = $this->sshService->execute($command, 30);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to read task output: '.$result['output']);
        }

        if (str_contains($result['output'], $marker)) {
            return ['output' => '', 'exists' => false];
        }

        return ['output' => $result['output'], 'exists' => true];
    }

    public function renderCronLine(ScheduledTask $task): string
    {
        // Crontab treats a bare % as end-of-command / stdin separator.
        $command = str_replace('%', '\%', $task->command);

        return "{$task->cron_expression} {$command} >> {$task->getLogPath()} 2>&1";
    }

    public function buildManagedBlock(Server $server, string $user): string
    {
        $tasks = ScheduledTask::query()
            ->where('server_id', $server->id)
            ->where('user', $user)
            ->whereIn('status', ['installing', 'installed'])
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty()) {
            return '';
        }

        $lines = [self::BLOCK_BEGIN];

        foreach ($tasks as $task) {
            $lines[] = "# ShipYard task {$task->id}";
            $lines[] = $this->renderCronLine($task);
        }

        $lines[] = self::BLOCK_END;

        return implode("\n", $lines);
    }

    private function syncCrontab(Server $server, string $user, string $prelude = '', string $postlude = ''): void
    {
        $quotedUser = escapeshellarg($user);
        $block = $this->buildManagedBlock($server, $user);

        if ($block !== '') {
            // Randomized delimiter: a task command can never terminate the
            // heredoc and smuggle extra lines into the crontab.
            $delimiter = 'SHIPYARD_EOF_'.Str::random(32);
            $write = "NEW_BLOCK=\$(cat <<'{$delimiter}'\n"
                .$block."\n"
                .$delimiter."\n"
                .")\n"
                ."printf '%s\\n\\n%s\\n' \"\$CLEANED\" \"\$NEW_BLOCK\" | \$SUDO crontab -u {$quotedUser} -";
        } else {
            $write = "printf '%s\\n' \"\$CLEANED\" | \$SUDO crontab -u {$quotedUser} -";
        }

        $begin = self::BLOCK_BEGIN;
        $end = self::BLOCK_END;

        $script = <<<BASH
set -euo pipefail

if [ "\$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi

{$prelude}

EXISTING=\$(\$SUDO crontab -l -u {$quotedUser} 2>/dev/null || true)
CLEANED=\$(printf '%s\\n' "\$EXISTING" | sed '/^{$begin}\$/,/^{$end}\$/d')

{$write}

{$postlude}
BASH;

        $result = $this->runRemoteScript($server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to sync crontab: '.$result['output']);
        }
    }
}
