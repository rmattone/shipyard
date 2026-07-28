<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Support\EnvFile;

class ApplicationImportService
{
    private const SCAN_BASES = ['/var/www', Server::LEGACY_DEPLOY_BASE];

    private const EXCLUDED_PATHS = ['/var/www/html', Server::LEGACY_DEPLOY_BASE];

    public function __construct(
        private SSHService $sshService
    ) {}

    /**
     * Scan the server for existing projects and create Application records
     * for them. Imported apps keep git_provider_id null (flagged in the UI)
     * and default to in-place deployment unless a releases/current layout
     * is found.
     *
     * @return array{imported: array<int, Application>, skipped: array<int, array{path: string, reason: string}>, warnings: array<int, array{path: string, warning: string}>}
     */
    public function import(Server $server): array
    {
        $imported = [];
        $skipped = [];
        $warnings = [];

        $this->sshService->connect($server);

        try {
            $sites = $this->parseNginxSites();

            foreach ($this->listCandidateDirs($server) as $dir) {
                // Home directories contain dotfile trees (.nvm is a git
                // checkout with a package.json at its root) that must never
                // become import candidates.
                if (str_starts_with(basename($dir), '.')) {
                    continue;
                }

                if (in_array($dir, self::EXCLUDED_PATHS, true)) {
                    continue;
                }

                // Deliberately does not call
                // ServerUserService::assertNoApplicationsOutsideHome here:
                // refusing to import apps that live outside the deploy
                // user's home would leave those real, already-running apps
                // permanently unmanageable through the panel on a
                // provisioned server, which is worse than the mixed layout
                // it would be guarding against. Surface it as a warning
                // instead so the caller can decide what to do. This runs
                // before the already-managed check so the warning keeps
                // firing on every re-import pass, not just the first one.
                if (filled($server->deploy_user) && ! str_starts_with($dir, $server->default_deploy_base.'/')) {
                    $warnings[] = [
                        'path' => $dir,
                        'warning' => 'This application lives outside the deploy user home; deploys may hit permission issues and the server cannot change its deploy user while it exists.',
                    ];
                }

                $existing = $server->applications()->where('deploy_path', $dir)->first();
                if ($existing !== null) {
                    // Backfill env for apps imported before their variables
                    // were synced; never touch an app that already has some
                    if (! $existing->environmentVariables()->exists()) {
                        $existingRoot = $existing->deployment_strategy === 'atomic' ? "{$dir}/current" : $dir;
                        $this->importEnvironmentVariables($existing, $dir, $existingRoot, $existing->deployment_strategy);
                    }

                    // Same for domain records: apps imported before domains
                    // were synced get them from the current nginx config
                    if (! $existing->domains()->exists()) {
                        $this->importDomains($existing, $this->matchSite($sites, $dir)['domains'] ?? []);
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
                $domains = $site['domains'] ?? [];
                $primary = array_key_first($domains);

                $application = Application::create([
                    'server_id' => $server->id,
                    'git_provider_id' => null,
                    'name' => basename($dir),
                    'type' => $type,
                    'deploy_path' => $dir,
                    'deployment_strategy' => $strategy,
                    'repository_url' => $this->gitRemote($root),
                    'branch' => $this->gitBranch($root),
                    'domain' => $primary,
                    'ssl_enabled' => $primary !== null && $domains[$primary],
                    'port' => $type === 'nodejs' ? ($site['proxy_port'] ?? null) : null,
                    'php_version' => $type === 'laravel' ? $server->php_version : null,
                    'status' => 'active',
                ]);

                $this->importDomains($application, $domains);
                $this->importEnvironmentVariables($application, $dir, $root, $strategy);

                $imported[] = $application;
            }
        } finally {
            $this->sshService->disconnect();
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'warnings' => $warnings];
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

            $document = EnvFile::parseDocument($result['output']);

            foreach ($document['variables'] as $key => $value) {
                EnvironmentVariable::create([
                    'application_id' => $application->id,
                    'key' => $key,
                    'value' => $value,
                ]);
            }

            // Keep the imported file's structure so panel edits don't
            // compact what already lives on the server
            $application->update(['env_layout' => $document['layout']]);

            return;
        }
    }

    /** @return array<int, string> */
    private function scanBases(Server $server): array
    {
        $bases = self::SCAN_BASES;

        if (filled($server->deploy_user)) {
            $bases[] = $server->default_deploy_base;
        }

        return $bases;
    }

    /** @return array<int, string> */
    private function listCandidateDirs(Server $server): array
    {
        $bases = implode(' ', $this->scanBases($server));

        // The deploy user's home is mode 711, so listing it needs sudo
        // unless the connection user IS the deploy user; the fallback keeps
        // import working for non-sudo users on the legacy /var/www bases.
        $find = "find {$bases} -mindepth 1 -maxdepth 1 -type d 2>/dev/null";
        $result = $this->sshService->execute("{ sudo -n {$find} || {$find}; } | sort -u", 30);

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
     * Create Domain records from an nginx domain map (name => has ssl).
     * The first name is the primary domain, the rest are aliases.
     *
     * @param  array<string, bool>  $domains
     */
    private function importDomains(Application $application, array $domains): void
    {
        $isPrimary = true;

        foreach ($domains as $name => $ssl) {
            $application->domains()->create([
                'domain' => $name,
                'is_primary' => $isPrimary,
                'ssl_enabled' => $ssl,
            ]);

            $isPrimary = false;
        }
    }

    /**
     * @return array<int, array{domains: array<int, string>, root: ?string, ssl: bool, proxy_port: ?int}>
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

            $names = [];
            if (preg_match('/^\s*server_name\s+([^;]+);/m', $body, $mm)) {
                $names = array_values(array_filter(preg_split('/\s+/', trim($mm[1])), fn ($n) => $n !== '_'));
            }

            $blocks[] = [
                'domains' => $names,
                'root' => preg_match('/^\s*root\s+([^;]+);/m', $body, $mm) ? trim($mm[1]) : null,
                'ssl' => (bool) preg_match('/^\s*listen\s+[^;]*443/m', $body),
                'proxy_port' => preg_match('#proxy_pass\s+http://(?:127\.0\.0\.1|localhost):(\d+)#', $body, $mm) ? (int) $mm[1] : null,
            ];

            $offset = $i;
        }

        return $blocks;
    }

    /**
     * Merge every server block whose root lives under the project dir
     * (sites usually split into separate 80 and 443 blocks) into a single
     * ordered domain map (name => appears in an ssl block) plus the first
     * proxy_pass port found.
     *
     * @param  array<int, array{domains: array<int, string>, root: ?string, ssl: bool, proxy_port: ?int}>  $sites
     * @return array{domains: array<string, bool>, proxy_port: ?int}|null
     */
    private function matchSite(array $sites, string $dir): ?array
    {
        $domains = [];
        $proxyPort = null;

        foreach ($sites as $site) {
            $root = $site['root'];
            if ($root === null || ($root !== $dir && ! str_starts_with($root, $dir.'/'))) {
                continue;
            }

            foreach ($site['domains'] as $name) {
                $domains[$name] = ($domains[$name] ?? false) || $site['ssl'];
            }

            $proxyPort ??= $site['proxy_port'];
        }

        if ($domains === [] && $proxyPort === null) {
            return null;
        }

        return ['domains' => $domains, 'proxy_port' => $proxyPort];
    }
}
