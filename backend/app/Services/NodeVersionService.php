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
}
