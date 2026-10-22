<?php

namespace App\Services;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Support\RemoteSudo;
use InvalidArgumentException;
use RuntimeException;

class PhpUpdateService
{
    public const BRANCH_PATTERN = '/\A[0-9]+\.[0-9]+\z/';

    public function __construct(private SSHService $sshService) {}

    public function installedBranches(Server $server): array
    {
        try {
            $this->sshService->connect($server);
            $this->execute('bash -c '.escapeshellarg('. /etc/os-release; case "$ID" in ubuntu|debian) ;; *) echo "PHP updates require Ubuntu or Debian."; exit 1;; esac'));
            $output = $this->execute("dpkg-query -W -f='\${binary:Package}\t\${Version}\t\${db:Status-Status}\n'");
            $branches = [];

            foreach (explode("\n", trim($output)) as $line) {
                $fields = explode("\t", $line);
                if (count($fields) !== 3 || $fields[2] !== 'installed') {
                    continue;
                }
                [$name, $version] = $fields;
                if (! preg_match('/\A(?:php([0-9]+\.[0-9]+)(?:-[a-z0-9.+-]+)?|libapache2-mod-php([0-9]+\.[0-9]+))(?::[a-z0-9]+)?\z/', $name, $match)) {
                    continue;
                }
                $branch = $match[1] ?: $match[2];
                $branches[$branch] ??= ['branch' => $branch, 'packages' => []];
                $branches[$branch]['packages'][] = ['name' => $name, 'version' => $version];
            }

            uksort($branches, fn ($a, $b) => version_compare($b, $a));

            return array_values($branches);
        } finally {
            $this->sshService->disconnect();
        }
    }

    public function update(DatabaseInstallation $installation): void
    {
        $branch = $installation->version_requested ?? '';
        if (! preg_match(self::BRANCH_PATTERN, $branch)) {
            throw new InvalidArgumentException('Select an installed PHP branch, such as 8.4.');
        }

        $installation->markAsRunning();
        $installation->appendLog("Updating PHP {$branch} packages from the configured repositories...");

        try {
            $this->sshService->connect($installation->server);
            $script = file_get_contents(resource_path('scripts/update-php.sh'));
            $command = RemoteSudo::wrap($installation->server, 'bash -c '.escapeshellarg($script).' -- '.escapeshellarg($branch));
            $installation->appendLog('Refreshing package lists, checking the upgrade plan, and restarting active PHP services. Package output will appear when this step finishes.');
            $output = $this->execute($command, 1500, $installation);

            if (! preg_match('/^SHIPYARD_PHP_UPDATED=(.+)$/m', $output, $match)) {
                throw new RuntimeException('The remote update did not return a verified package version. Check the log and server state before retrying.');
            }

            $installation->update(['version_installed' => trim($match[1])]);
            $installation->appendLog('PHP package update and service verification completed. Check the package vendor advisory to confirm a specific security fix.');
            $installation->markAsSuccess();
        } finally {
            $this->sshService->disconnect();
        }
    }

    private function execute(string $command, int $timeout = 30, ?DatabaseInstallation $installation = null): string
    {
        $result = $this->sshService->execute($command, $timeout);
        if ($installation && trim($result['output']) !== '') {
            $installation->appendLog($result['output']);
        }
        if (! $result['success']) {
            throw new RuntimeException($installation
                ? "PHP update failed (exit code {$result['exit_code']}). See the package/service output above; packages may already have changed."
                : 'Could not inspect PHP packages: '.trim($result['output']));
        }

        return $result['output'];
    }
}
