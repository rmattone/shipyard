<?php

namespace App\Services;

use App\Models\Application;

/**
 * Checks an application's environment for settings that are wrong in
 * production, plus queue and scheduler settings that have nothing running
 * them. Only keys and verdicts leave this class, never values.
 *
 * Where ShipYard can fix the problem itself, the warning carries a `fix`:
 * the exact payload for the existing daemon or scheduled task endpoint, so
 * the panel creates it through the same validated path as a manual entry.
 */
class EnvHealthService
{
    public const LEVEL_DANGER = 'danger';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    private const IDLE_QUEUE_CONNECTIONS = ['sync', 'null', ''];

    /**
     * @return array<int, array{code: string, level: string, message: string, fix?: array<string, mixed>}>
     */
    public function check(Application $application): array
    {
        if (! $application->isLaravel()) {
            return [];
        }

        $env = $application->environmentVariables()->get()
            ->mapWithKeys(fn ($var) => [$var->key => (string) $var->value])
            ->all();

        if ($env === []) {
            return [[
                'code' => 'env_empty',
                'level' => self::LEVEL_INFO,
                'message' => 'No environment variables are set, so deployments do not write a .env file. Load the template to start from production defaults.',
            ]];
        }

        $warnings = [];

        if ($this->isTruthy($env['APP_DEBUG'] ?? null)) {
            $warnings[] = [
                'code' => 'app_debug',
                'level' => self::LEVEL_DANGER,
                'message' => 'APP_DEBUG is on. Error pages show stack traces and configuration, including secrets, to every visitor.',
            ];
        }

        if (empty($env['APP_KEY'])) {
            $warnings[] = [
                'code' => 'app_key_missing',
                'level' => self::LEVEL_DANGER,
                'message' => 'APP_KEY is empty. Sessions, cookies and encrypted values do not work without it.',
            ];
        }

        if (($env['APP_ENV'] ?? '') !== 'production') {
            $warnings[] = [
                'code' => 'app_env',
                'level' => self::LEVEL_WARNING,
                'message' => isset($env['APP_ENV'])
                    ? "APP_ENV is \"{$env['APP_ENV']}\". Use \"production\" on a live server."
                    : 'APP_ENV is not set. Use "production" on a live server.',
            ];
        }

        if (strtolower($env['LOG_LEVEL'] ?? '') === 'debug') {
            $warnings[] = [
                'code' => 'log_level_debug',
                'level' => self::LEVEL_INFO,
                'message' => 'LOG_LEVEL is debug. Logs grow quickly and may record request data; warning or error is usual in production.',
            ];
        }

        $queue = strtolower(trim($env['QUEUE_CONNECTION'] ?? 'sync'));
        if (! in_array($queue, self::IDLE_QUEUE_CONNECTIONS, true) && ! $this->hasQueueWorker($application)) {
            $warnings[] = [
                'code' => 'queue_worker_missing',
                'level' => self::LEVEL_WARNING,
                'message' => "QUEUE_CONNECTION is \"{$queue}\" but no queue worker daemon runs for this app, so queued jobs never execute.",
                'fix' => $this->queueWorkerFix($application, $queue),
            ];
        }

        if (! $this->hasScheduler($application)) {
            $warnings[] = [
                'code' => 'scheduler_missing',
                'level' => self::LEVEL_INFO,
                'message' => 'No cron entry runs schedule:run for this app. If it defines scheduled commands, they never run.',
                'fix' => $this->schedulerFix($application),
            ];
        }

        return $warnings;
    }

    private function isTruthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value, " \t\"'()")), ['true', '1', 'on', 'yes'], true);
    }

    private function hasQueueWorker(Application $application): bool
    {
        return $application->daemons()
            ->where('status', '!=', 'removing')
            ->where(fn ($q) => $q->where('command', 'like', '%queue:work%')->orWhere('command', 'like', '%horizon%'))
            ->exists();
    }

    private function hasScheduler(Application $application): bool
    {
        return $application->scheduledTasks()
            ->where('status', '!=', 'removing')
            ->where('command', 'like', '%schedule:run%')
            ->exists();
    }

    /**
     * Same command the daemon panel's queue worker preset builds.
     *
     * @return array<string, mixed>
     */
    private function queueWorkerFix(Application $application, string $connection): array
    {
        // The value comes from the env; only a plain name goes into the
        // command line, anything else falls back to the default connection.
        $connectionArg = preg_match('/^[a-z0-9_-]+$/', $connection) ? " {$connection}" : '';

        return [
            'type' => 'daemon',
            'server_id' => $application->server_id,
            'data' => [
                'command' => "php{$application->getPhpVersion()} artisan queue:work{$connectionArg} --sleep=3 --tries=3 --timeout=60",
                'user' => $application->server->phpRuntimeUser(),
                'directory' => $this->workingDir($application),
                'processes' => 1,
                'application_id' => $application->id,
            ],
        ];
    }

    /**
     * Same command the scheduler panel's Laravel preset builds.
     *
     * @return array<string, mixed>
     */
    private function schedulerFix(Application $application): array
    {
        return [
            'type' => 'scheduled_task',
            'server_id' => $application->server_id,
            'data' => [
                'command' => "php{$application->getPhpVersion()} {$this->workingDir($application)}/artisan schedule:run",
                'user' => $application->server->phpRuntimeUser(),
                'frequency' => 'minutely',
                'application_id' => $application->id,
            ],
        ];
    }

    private function workingDir(Application $application): string
    {
        return $application->usesAtomicDeployments() ? $application->getCurrentPath() : $application->deploy_path;
    }
}
