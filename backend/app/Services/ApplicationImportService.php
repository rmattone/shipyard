<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Support\EnvFile;

class ApplicationImportService
{
    private const SCAN_BASES = ['/var/www', '/var/www/shipyard'];

    private const EXCLUDED_PATHS = ['/var/www/html', '/var/www/shipyard'];

    public function __construct(
        private SSHService $sshService
    ) {}

    /**
     * Scan the server for existing projects and create Application records
     * for them. Imported apps keep git_provider_id null (flagged in the UI)
     * and default to in-place deployment unless a releases/current layout
     * is found.
     *
     * @return array{imported: array<int, Application>, skipped: array<int, array{path: string, reason: string}>}
     */
    public function import(Server $server): array
    {
        $imported = [];
        $skipped = [];

        $this->sshService->connect($server);

        try {
            $sites = $this->parseNginxSites();

            foreach ($this->listCandidateDirs() as $dir) {
                if (in_array($dir, self::EXCLUDED_PATHS, true)) {
                    continue;
                }

                $existing = $server->applications()->where('deploy_path', $dir)->first();
                if ($existing !== null) {
                    // Backfill env for apps imported before their variables
                    // were synced; never touch an app that already has some
                    if (! $existing->environmentVariables()->exists()) {
                        $existingRoot = $existing->deployment_strategy === 'atomic' ? "{$dir}/current" : $dir;
                        $this->importEnvironmentVariables($existing, $dir, $existingRoot, $existing->deployment_strategy);
                    }

                    $skipped[] = ['path' => $dir, 'reason' => 'already managed'];

                    continue;
                }

                $strategy = $this->detectStrategy($dir);
                $root = $strategy === 'atomic' ? "{$dir}/current" : $dir;

                $type = $this->detectType($root);
                if ($type === 'unknown') {
                    $skipped[] = ['path' => $dir, 'reason' => 'no recognizable project'];

                    continue;
                }

                $site = $this->matchSite($sites, $dir);

                $application = Application::create([
                    'server_id' => $server->id,
                    'git_provider_id' => null,
                    'name' => basename($dir),
                    'type' => $type,
                    'deploy_path' => $dir,
                    'deployment_strategy' => $strategy,
                    'repository_url' => $this->gitRemote($root),
                    'branch' => $this->gitBranch($root),
                    'domain' => $site['domain'] ?? null,
                    'ssl_enabled' => $site['ssl'] ?? false,
                    'port' => $type === 'nodejs' ? ($site['proxy_port'] ?? null) : null,
                    'php_version' => $type === 'laravel' ? $server->php_version : null,
                    'status' => 'active',
                ]);

                $this->importEnvironmentVariables($application, $dir, $root, $strategy);

                $imported[] = $application;
            }
        } finally {
            $this->sshService->disconnect();
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Pull the project's .env into encrypted EnvironmentVariable records.
     * Atomic layouts keep .env in shared/ (that is where EnvSyncService
     * writes it); everything else has it at the project root.
     */
    private function importEnvironmentVariables(Application $application, string $dir, string $root, string $strategy): void
    {
        $candidates = $strategy === 'atomic'
            ? ["{$dir}/shared/.env", "{$root}/.env"]
            : ["{$root}/.env"];

        foreach ($candidates as $path) {
            $result = $this->sshService->execute("cat \"{$path}\" 2>/dev/null", 15);

            if (! $result['success'] || trim($result['output'] ?? '') === '') {
                continue;
            }

            foreach (EnvFile::parse($result['output']) as $key => $value) {
                EnvironmentVariable::create([
                    'application_id' => $application->id,
                    'key' => $key,
                    'value' => $value,
                ]);
            }

            return;
        }
    }

    /** @return array<int, string> */
    private function listCandidateDirs(): array
    {
        $bases = implode(' ', self::SCAN_BASES);
        $result = $this->sshService->execute("find {$bases} -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort -u", 30);

        return array_values(array_filter(array_map('trim', explode("\n", $result['output'] ?? ''))));
    }

    private function detectStrategy(string $dir): string
    {
        $result = $this->sshService->execute(
            "test -L \"{$dir}/current\" && test -d \"{$dir}/releases\" && echo atomic || echo in_place",
            15
        );

        return trim($result['output']) === 'atomic' ? 'atomic' : 'in_place';
    }

    private function detectType(string $root): string
    {
        $result = $this->sshService->execute(
            "if [ -f \"{$root}/artisan\" ]; then echo laravel; ".
            "elif [ -f \"{$root}/package.json\" ]; then echo nodejs; ".
            "elif [ -e \"{$root}/index.html\" ]; then echo static; ".
            'else echo unknown; fi',
            15
        );

        return trim($result['output']) ?: 'unknown';
    }

    private function gitRemote(string $root): ?string
    {
        $result = $this->sshService->execute("git -C \"{$root}\" config --get remote.origin.url 2>/dev/null", 15);
        $remote = trim($result['output'] ?? '');

        return $remote !== '' ? $remote : null;
    }

    private function gitBranch(string $root): string
    {
        $result = $this->sshService->execute("git -C \"{$root}\" rev-parse --abbrev-ref HEAD 2>/dev/null", 15);
        $branch = trim($result['output'] ?? '');

        return $branch !== '' ? $branch : 'main';
    }

    /**
     * @return array<int, array{domain: ?string, root: ?string, ssl: bool, proxy_port: ?int}>
     */
    private function parseNginxSites(): array
    {
        $result = $this->sshService->execute('cat /etc/nginx/sites-enabled/* 2>/dev/null', 15);
        $config = $result['output'] ?? '';

        $blocks = [];
        $offset = 0;
        $len = strlen($config);

        while (preg_match('/\bserver\s*\{/', $config, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $i = $start;
            while ($i < $len && $depth > 0) {
                if ($config[$i] === '{') {
                    $depth++;
                } elseif ($config[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            $body = substr($config, $start, $i - $start - 1);

            $domain = null;
            if (preg_match('/^\s*server_name\s+([^;]+);/m', $body, $mm)) {
                $names = array_filter(preg_split('/\s+/', trim($mm[1])), fn ($n) => $n !== '_');
                $domain = $names !== [] ? reset($names) : null;
            }

            $blocks[] = [
                'domain' => $domain,
                'root' => preg_match('/^\s*root\s+([^;]+);/m', $body, $mm) ? trim($mm[1]) : null,
                'ssl' => (bool) preg_match('/^\s*listen\s+[^;]*443/m', $body),
                'proxy_port' => preg_match('#proxy_pass\s+http://(?:127\.0\.0\.1|localhost):(\d+)#', $body, $mm) ? (int) $mm[1] : null,
            ];

            $offset = $i;
        }

        return $blocks;
    }

    /**
     * @param  array<int, array{domain: ?string, root: ?string, ssl: bool, proxy_port: ?int}>  $sites
     * @return array{domain: ?string, root: ?string, ssl: bool, proxy_port: ?int}|null
     */
    private function matchSite(array $sites, string $dir): ?array
    {
        foreach ($sites as $site) {
            $root = $site['root'];
            if ($root !== null && ($root === $dir || str_starts_with($root, $dir.'/'))) {
                return $site;
            }
        }

        return null;
    }
}
