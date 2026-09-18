<?php

namespace App\Services;

use App\Jobs\RunSystemUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Version detection and self-update orchestration for the ShipYard
 * installation itself.
 *
 * The checkout is mounted at /var/www/shipyard in every container (see
 * docker-compose.yml). Installed version = VERSION file plus the git commit;
 * "update available" = origin/<branch> has a different commit, since that is
 * exactly what update.sh installs. The update runs as a queued job in the
 * queue container (root, same image) because php-fpm runs as www-data and
 * cannot write to the checkout. State lives in the cache (Redis, shared
 * between containers) and the log in storage/app so php-fpm can read it.
 */
class SystemUpdateService
{
    public const STATE_KEY = 'system_update:state';

    public const LATEST_KEY = 'system_update:latest';

    public const LOG_FILE = 'system-update.log';

    public const LATEST_TTL_SECONDS = 900;

    /** A "running" state older than this with no result is treated as failed. */
    public const STALE_AFTER_MINUTES = 45;

    public function installDir(): ?string
    {
        $candidates = array_filter([
            config('shipyard.install_dir'),
            '/var/www/shipyard',
            dirname(base_path()),
        ]);

        foreach ($candidates as $dir) {
            if (is_file($dir.'/docker-compose.yml') && is_dir($dir.'/backend')) {
                return rtrim($dir, '/');
            }
        }

        return null;
    }

    public function scriptPath(): ?string
    {
        $dir = $this->installDir();

        return $dir !== null && is_file($dir.'/update.sh') ? $dir.'/update.sh' : null;
    }

    /**
     * @return array{version: string, version_source: string, commit: ?string, branch: ?string}
     */
    public function current(): array
    {
        $dir = $this->installDir();
        $version = null;

        if ($dir !== null && is_file($dir.'/VERSION')) {
            $version = trim((string) file_get_contents($dir.'/VERSION')) ?: null;
        }

        return [
            'version' => $version ?? config('shipyard.fallback_version', '1.0.0'),
            'version_source' => $version !== null ? 'file' : 'fallback',
            'commit' => $this->git($dir, 'rev-parse HEAD'),
            'branch' => $this->git($dir, 'rev-parse --abbrev-ref HEAD'),
        ];
    }

    /** owner/repo, taken from the origin remote so forks update from themselves. */
    public function repo(): string
    {
        $url = $this->git($this->installDir(), 'remote get-url origin');

        if ($url !== null && preg_match('~github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?$~', $url, $m)) {
            return $m[1];
        }

        return config('shipyard.repo') ?: 'rmattone/shipyard';
    }

    /**
     * @return array{commit: ?string, version: ?string, error: ?string, repo: string, branch: string, checked_at: string}
     */
    public function latest(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::LATEST_KEY);
        }

        return Cache::remember(self::LATEST_KEY, self::LATEST_TTL_SECONDS, function () {
            $repo = $this->repo();
            $branch = config('shipyard.branch') ?: 'main';
            $commit = null;
            $version = null;
            $error = null;

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'ShipYard',
                    'Accept' => 'application/vnd.github+json',
                ])->timeout(5)->get("https://api.github.com/repos/{$repo}/commits/{$branch}");

                if ($response->successful()) {
                    $commit = $response->json('sha');
                } else {
                    $error = "GitHub API responded {$response->status()}";
                }

                $file = Http::withHeaders(['User-Agent' => 'ShipYard'])
                    ->timeout(5)
                    ->get("https://raw.githubusercontent.com/{$repo}/{$branch}/VERSION");

                if ($file->successful()) {
                    $version = trim($file->body()) ?: null;
                } elseif ($error === null) {
                    $error = "VERSION file on {$branch} responded {$file->status()}";
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            return [
                'commit' => $commit,
                'version' => $version,
                'error' => $error,
                'repo' => $repo,
                'branch' => $branch,
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }

    public function versionInfo(bool $refresh = false): array
    {
        $current = $this->current();
        $latest = $this->latest($refresh);

        if ($current['commit'] !== null && $latest['commit'] !== null) {
            $comparison = 'commit';
            $available = $current['commit'] !== $latest['commit'];
        } elseif ($latest['version'] !== null) {
            $comparison = 'version';
            $available = version_compare($latest['version'], $current['version'], '>');
        } else {
            $comparison = 'unknown';
            $available = false;
        }

        return [
            'current_version' => $current['version'],
            'current_commit' => $current['commit'],
            'branch' => $current['branch'],
            'version_source' => $current['version_source'],
            'latest_version' => $latest['version'] ?? $current['version'],
            'latest_commit' => $latest['commit'],
            'update_available' => $available,
            'comparison' => $comparison,
            'repo' => $latest['repo'],
            'target_branch' => $latest['branch'],
            'checked_at' => $latest['checked_at'],
            'check_error' => $latest['error'],
            'updater_available' => $this->scriptPath() !== null,
            'on_target_branch' => $current['branch'] === null || $current['branch'] === $latest['branch'],
        ];
    }

    /**
     * @return array{status: string, started_at: ?string, finished_at: ?string, exit_code: ?int, message: ?string}
     */
    public function state(): array
    {
        return array_merge([
            'status' => 'idle',
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'message' => null,
        ], Cache::get(self::STATE_KEY, []));
    }

    public function setState(array $patch): void
    {
        Cache::forever(self::STATE_KEY, array_merge($this->state(), $patch));
    }

    public function isRunning(): bool
    {
        return $this->state()['status'] === 'running';
    }

    /** Queue the update. Returns false when one is already running. */
    public function start(): bool
    {
        $this->markStaleIfNeeded();

        if ($this->isRunning()) {
            return false;
        }

        $this->resetLog();
        $this->setState([
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'exit_code' => null,
            'message' => null,
        ]);
        $this->appendLog("Update queued; waiting for the queue worker to pick it up...\n");

        RunSystemUpdate::dispatch();

        return true;
    }

    public function markStaleIfNeeded(): void
    {
        $state = $this->state();

        if ($state['status'] !== 'running' || $state['started_at'] === null) {
            return;
        }

        if (Carbon::parse($state['started_at'])->addMinutes(self::STALE_AFTER_MINUTES)->isPast()) {
            $this->setState([
                'status' => 'failed',
                'finished_at' => now()->toIso8601String(),
                'message' => 'No result after '.self::STALE_AFTER_MINUTES.' minutes. Check that the queue worker is running and read its logs.',
            ]);
        }
    }

    public function logPath(): string
    {
        return storage_path('app/'.self::LOG_FILE);
    }

    public function resetLog(): void
    {
        file_put_contents($this->logPath(), '');
        @chmod($this->logPath(), 0664);
    }

    public function appendLog(string $chunk): void
    {
        file_put_contents($this->logPath(), $chunk, FILE_APPEND | LOCK_EX);
    }

    public function logTail(int $bytes = 65536): string
    {
        $path = $this->logPath();

        if (! is_file($path)) {
            return '';
        }

        $size = filesize($path) ?: 0;
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return '';
        }

        try {
            if ($size > $bytes) {
                fseek($handle, $size - $bytes);
                $tail = (string) stream_get_contents($handle);
                // Drop the partial first line so the tail starts cleanly.
                $newline = strpos($tail, "\n");

                return "[... earlier output truncated ...]\n".($newline === false ? $tail : substr($tail, $newline + 1));
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    private function git(?string $dir, string $args): ?string
    {
        if ($dir === null || ! file_exists($dir.'/.git')) {
            return null;
        }

        // The checkout is a bind mount owned by another uid; git refuses to
        // read it without safe.directory.
        $result = Process::timeout(5)->run(
            "git -c safe.directory='*' -C ".escapeshellarg($dir).' '.$args
        );

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output()) ?: null;
    }
}
