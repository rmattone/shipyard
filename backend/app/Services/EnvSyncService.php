<?php

namespace App\Services;

use App\Models\Application;
use Exception;

class EnvSyncService
{
    public function __construct(private SSHService $sshService) {}

    /**
     * Sync environment variables to the server.
     *
     * @return array{synced: bool, message: string, error?: string}
     */
    public function syncToServer(Application $application): array
    {
        try {
            $this->sshService->connect($application->server);

            // Upload .env file
            $this->uploadEnvFile($application);

            // Run php artisan optimize for Laravel apps
            $optimizeOutput = '';
            if ($application->isLaravel()) {
                $optimizeOutput = $this->runLaravelOptimize($application);
            }

            $this->sshService->disconnect();

            $message = $application->isLaravel()
                ? 'Synced to server and optimized'
                : 'Synced to server';

            return [
                'synced' => true,
                'message' => $message,
            ];
        } catch (Exception $e) {
            return [
                'synced' => false,
                'message' => 'Sync failed',
                'error' => $e->getMessage(),
            ];
        } finally {
            $this->sshService->disconnect();
        }
    }

    /**
     * Upload the .env file to the server.
     */
    private function uploadEnvFile(Application $application): void
    {
        $envContent = $this->buildEnvContent($application);
        $envPath = $this->getEnvPath($application);

        $result = $this->sshService->uploadContent($envContent, $envPath);

        if (!$result) {
            throw new Exception('Failed to upload .env file');
        }
    }

    /**
     * Build the .env file content from application environment variables.
     */
    private function buildEnvContent(Application $application): string
    {
        $variables = $application->environmentVariables()->get();

        return $variables->map(function ($var) {
            $value = $var->value;
            // Quote values that contain spaces, special characters, or are empty
            if (preg_match('/[\s#"\'\\\\]/', $value) || $value === '') {
                $value = '"' . addslashes($value) . '"';
            }
            return $var->key . '=' . $value;
        })->implode("\n");
    }

    /**
     * Get the path to the .env file based on deployment strategy.
     */
    private function getEnvPath(Application $application): string
    {
        if ($application->usesAtomicDeployments()) {
            // Atomic: .env lives in shared directory for Laravel, or current for others
            if ($application->isLaravel()) {
                return "{$application->getSharedPath()}/.env";
            }
            return "{$application->getCurrentPath()}/.env";
        }

        // In-place: .env lives directly in deploy_path
        return "{$application->deploy_path}/.env";
    }

    /**
     * Run php artisan optimize on Laravel applications.
     */
    private function runLaravelOptimize(Application $application): string
    {
        $workingDir = $this->getArtisanWorkingDir($application);

        $command = "cd {$workingDir} && php artisan optimize";
        $result = $this->sshService->execute($command);

        if (!$result['success']) {
            // Log the failure but don't throw - env was already synced
            \Log::warning("Laravel optimize failed for app {$application->id}: " . $result['output']);
        }

        return $result['output'];
    }

    /**
     * Get the working directory for artisan commands.
     */
    private function getArtisanWorkingDir(Application $application): string
    {
        if ($application->usesAtomicDeployments()) {
            return $application->getCurrentPath();
        }

        return $application->deploy_path;
    }
}
