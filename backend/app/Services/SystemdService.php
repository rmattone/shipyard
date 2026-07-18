<?php

namespace App\Services;

use App\Models\Daemon;
use App\Services\Concerns\RunsRemoteScripts;
use App\Support\RemoteSudo;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manages daemons on target servers as systemd templated units. The user
 * command never enters the unit file: it lives verbatim in a root-owned
 * wrapper script that ExecStart points at, so no systemd escaping is
 * needed and a hostile command cannot inject unit directives. The only
 * user-influenced unit values (User=, WorkingDirectory=) are re-validated
 * here against strict allowlists as defense in depth.
 */
class SystemdService
{
    use RunsRemoteScripts;

    private const USER_PATTERN = '/^[a-z_][a-z0-9_-]*$/';

    private const DIRECTORY_PATTERN = '/^\/[A-Za-z0-9._\-\/]*$/';

    private const NO_ENTRIES_MARKER = '-- No entries --';

    public function __construct(
        protected SSHService $sshService,
    ) {}

    public function installDaemon(Daemon $daemon): void
    {
        $wrapperDelimiter = 'SHIPYARD_EOF_'.Str::random(32);
        $unitDelimiter = 'SHIPYARD_EOF_'.Str::random(32);
        $instances = implode(' ', $daemon->instanceUnits());

        $script = implode("\n", [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            '$SUDO install -d -m 0755 /etc/shipyard/daemons',
            '',
            "\$SUDO tee {$daemon->wrapperPath()} > /dev/null <<'{$wrapperDelimiter}'",
            rtrim($this->renderWrapperScript($daemon), "\n"),
            $wrapperDelimiter,
            "\$SUDO chmod 0755 {$daemon->wrapperPath()}",
            '',
            "\$SUDO tee {$daemon->unitFilePath()} > /dev/null <<'{$unitDelimiter}'",
            rtrim($this->renderUnitFile($daemon), "\n"),
            $unitDelimiter,
            "\$SUDO chmod 0644 {$daemon->unitFilePath()}",
            '',
            '$SUDO systemctl daemon-reload',
            "\$SUDO systemctl enable --now {$instances}",
        ]);

        $result = $this->runRemoteScript($daemon->server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to install daemon: '.$result['output']);
        }
    }

    public function removeDaemon(Daemon $daemon): void
    {
        $instances = implode(' ', $daemon->instanceUnits());
        $glob = "'{$daemon->unitName()}@*'";

        $script = implode("\n", [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            "\$SUDO systemctl disable --now {$instances} 2>/dev/null || true",
            "\$SUDO systemctl stop {$glob} 2>/dev/null || true",
            "\$SUDO rm -f {$daemon->unitFilePath()} {$daemon->wrapperPath()}",
            '$SUDO systemctl daemon-reload',
            "\$SUDO systemctl reset-failed {$glob} 2>/dev/null || true",
        ]);

        $result = $this->runRemoteScript($daemon->server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to remove daemon: '.$result['output']);
        }
    }

    public function restartDaemon(Daemon $daemon): void
    {
        $instances = implode(' ', $daemon->instanceUnits());
        $command = RemoteSudo::wrap($daemon->server, "systemctl restart {$instances} 2>&1");

        try {
            $this->sshService->connect($daemon->server);
            $result = $this->sshService->execute($command, 90);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to restart daemon: '.$result['output']);
        }
    }

    /**
     * Live systemd state; never persisted (systemd restarts crashed
     * processes on its own, so stored state would be instantly stale).
     *
     * @return array{state: string, instances: array<int, string>}
     */
    public function getStatus(Daemon $daemon): array
    {
        $instances = implode(' ', $daemon->instanceUnits());
        // is-active exits non-zero when any unit is inactive but still
        // prints one state per line, hence the || true.
        $command = "systemctl is-active {$instances} 2>&1 || true";

        try {
            $this->sshService->connect($daemon->server);
            $result = $this->sshService->execute($command, 30);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to read daemon status: '.$result['output']);
        }

        $states = array_values(array_filter(array_map('trim', explode("\n", $result['output'])), fn ($l) => $l !== ''));

        $instanceStates = [];
        foreach (range(1, max(1, $daemon->processes)) as $index => $instance) {
            $instanceStates[$instance] = $states[$index] ?? 'unknown';
        }

        $activeCount = count(array_filter($instanceStates, fn ($s) => $s === 'active'));

        return [
            'state' => match (true) {
                $activeCount === count($instanceStates) => 'running',
                $activeCount > 0 => 'degraded',
                default => 'stopped',
            },
            'instances' => $instanceStates,
        ];
    }

    /**
     * @return array{output: string, exists: bool}
     */
    public function readLogs(Daemon $daemon, int $lines = 200): array
    {
        $lines = max(1, min($lines, 2000));
        $command = RemoteSudo::wrap(
            $daemon->server,
            "journalctl -u '{$daemon->unitName()}@*' -n {$lines} --no-pager --output=short-iso 2>&1"
        );

        try {
            $this->sshService->connect($daemon->server);
            $result = $this->sshService->execute($command, 30);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to read daemon logs: '.$result['output']);
        }

        $hasEntries = trim($result['output']) !== ''
            && ! str_contains($result['output'], self::NO_ENTRIES_MARKER);

        return [
            'output' => $hasEntries ? $result['output'] : '',
            'exists' => $hasEntries,
        ];
    }

    public function renderUnitFile(Daemon $daemon): string
    {
        if (! preg_match(self::USER_PATTERN, $daemon->user)) {
            throw new InvalidArgumentException('Invalid daemon user.');
        }

        if (! preg_match(self::DIRECTORY_PATTERN, $daemon->directory) || str_contains($daemon->directory, '..')) {
            throw new InvalidArgumentException('Invalid daemon directory.');
        }

        return implode("\n", [
            '[Unit]',
            "Description=ShipYard daemon {$daemon->id} (instance %i)",
            'StartLimitIntervalSec=0',
            '',
            '[Service]',
            'Type=simple',
            "User={$daemon->user}",
            "WorkingDirectory={$daemon->directory}",
            "ExecStart={$daemon->wrapperPath()}",
            'Restart=always',
            'RestartSec=5',
            'TimeoutStopSec=30',
            'KillMode=control-group',
            "SyslogIdentifier={$daemon->unitName()}",
            'StandardOutput=journal',
            'StandardError=journal',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    public function renderWrapperScript(Daemon $daemon): string
    {
        return implode("\n", [
            '#!/bin/bash -l',
            "# ShipYard daemon {$daemon->id} - managed file, do not edit.",
            $daemon->command,
            '',
        ]);
    }
}
