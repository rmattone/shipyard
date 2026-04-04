<?php

namespace App\Services;

use App\Models\Server;

class NodeVersionService
{
    public function __construct(
        private SSHService $sshService
    ) {}

    /**
     * Get installed Node.js versions from a server via nvm.
     *
     * @return array<string> List of installed versions (e.g., ["20.10.0", "18.19.0"])
     */
    public function getInstalledVersions(Server $server): array
    {
        try {
            $this->sshService->connect($server);

            // Source nvm and list installed versions
            $command = <<<'BASH'
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"
nvm list --no-colors 2>/dev/null | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | sed 's/^v//' | sort -V -r | uniq
BASH;

            $result = $this->sshService->execute($command, 30);
            $this->sshService->disconnect();

            if (!$result['success'] || empty(trim($result['output']))) {
                return [];
            }

            // Parse the output - each line is a version
            $versions = array_filter(
                array_map('trim', explode("\n", trim($result['output']))),
                fn($v) => preg_match('/^\d+\.\d+\.\d+$/', $v)
            );

            return array_values($versions);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get available LTS Node.js versions from remote (via nvm ls-remote --lts).
     *
     * @return array<string> List of available LTS versions (e.g., ["22.14.0", "20.18.0", "18.20.4"])
     */
    public function getRemoteLtsVersions(Server $server): array
    {
        try {
            $this->sshService->connect($server);

            // Source nvm and list remote LTS versions
            $command = <<<'BASH'
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"
nvm ls-remote --lts 2>/dev/null | grep -oE 'v[0-9]+\.[0-9]+\.[0-9]+' | sed 's/^v//' | sort -V -r | uniq | head -20
BASH;

            $result = $this->sshService->execute($command, 60);
            $this->sshService->disconnect();

            if (!$result['success'] || empty(trim($result['output']))) {
                return [];
            }

            // Parse the output - each line is a version
            $versions = array_filter(
                array_map('trim', explode("\n", trim($result['output']))),
                fn($v) => preg_match('/^\d+\.\d+\.\d+$/', $v)
            );

            return array_values($versions);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Set the default Node.js version via nvm.
     *
     * @param Server $server
     * @param string $version The version to set as default (e.g., "20.18.0")
     * @return array{success: bool, message: string}
     */
    public function setDefaultVersion(Server $server, string $version): array
    {
        try {
            $this->sshService->connect($server);

            // Source nvm and set default version
            $command = <<<BASH
export NVM_DIR="\$HOME/.nvm"
[ -s "\$NVM_DIR/nvm.sh" ] && \. "\$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"
nvm alias default {$version} 2>&1
BASH;

            $result = $this->sshService->execute($command, 30);
            $this->sshService->disconnect();

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to set default Node.js version: ' . ($result['output'] ?? 'Unknown error'),
                ];
            }

            return [
                'success' => true,
                'message' => "Default Node.js version set to {$version}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to set default Node.js version: ' . $e->getMessage(),
            ];
        }
    }
}
