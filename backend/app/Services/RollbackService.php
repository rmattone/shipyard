<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use RuntimeException;

class RollbackService
{
    public function __construct(
        private SSHService $sshService,
        private AtomicDeploymentService $atomicDeploymentService
    ) {}

    /**
     * Rollback to a specific deployment.
     */
    public function rollback(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): Deployment
    {
        if (!$app->usesAtomicDeployments()) {
            throw new RuntimeException('Rollback is only supported for atomic deployments.');
        }

        if (!$targetDeployment->release_path) {
            throw new RuntimeException('Target deployment does not have a release path.');
        }

        try {
            $app->update(['status' => 'deploying']);
            $rollbackDeployment->markAsRunning();

            $this->sshService->connect($app->server);

            // Verify target release directory exists
            $this->verifyReleaseExists($app, $targetDeployment, $rollbackDeployment);

            // Perform the symlink swap
            $this->swapSymlink($app, $targetDeployment, $rollbackDeployment);

            // Run post-rollback tasks
            $this->runPostRollbackTasks($app, $targetDeployment, $rollbackDeployment);

            $this->sshService->disconnect();

            // Mark the rollback deployment as active
            $rollbackDeployment->update([
                'release_path' => $targetDeployment->release_path,
                'release_id' => $targetDeployment->release_id,
            ]);
            $rollbackDeployment->markAsActive();
            $rollbackDeployment->markAsSuccess();
            $app->update(['status' => 'active']);

            return $rollbackDeployment;

        } catch (\Exception $e) {
            $rollbackDeployment->appendLog("ERROR: {$e->getMessage()}");
            $rollbackDeployment->markAsFailed();
            $app->update(['status' => 'failed']);

            $this->sshService->disconnect();

            throw $e;
        }
    }

    /**
     * Rollback to the previous successful deployment.
     */
    public function rollbackToPrevious(Application $app, Deployment $rollbackDeployment): Deployment
    {
        $currentDeployment = $app->activeDeployment();

        if (!$currentDeployment) {
            throw new RuntimeException('No active deployment found.');
        }

        // Find the most recent successful deployment that is not the current one
        $targetDeployment = $app->deployments()
            ->where('status', 'success')
            ->where('id', '!=', $currentDeployment->id)
            ->whereNotNull('release_path')
            ->orderByDesc('created_at')
            ->first();

        if (!$targetDeployment) {
            throw new RuntimeException('No previous deployment available for rollback.');
        }

        return $this->rollback($app, $targetDeployment, $rollbackDeployment);
    }

    /**
     * Verify the target release directory exists.
     */
    private function verifyReleaseExists(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): void
    {
        $releasePath = $targetDeployment->release_path;

        $rollbackDeployment->appendLog("Verifying release directory exists: {$releasePath}");

        $result = $this->sshService->execute("test -d {$releasePath} && echo 'exists'");

        if (!str_contains($result['output'], 'exists')) {
            throw new RuntimeException("Release directory not found: {$releasePath}");
        }

        $rollbackDeployment->appendLog("Release directory verified.");
    }

    /**
     * Perform the atomic symlink swap for rollback.
     */
    private function swapSymlink(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): void
    {
        $currentPath = $app->getCurrentPath();
        $releasePath = $targetDeployment->release_path;

        $rollbackDeployment->appendLog("Rolling back to release: {$targetDeployment->release_id}");

        // Atomic symlink swap
        $result = $this->sshService->execute("ln -nfs {$releasePath} {$currentPath}");

        if (!$result['success']) {
            throw new RuntimeException("Failed to swap symlink: {$result['output']}");
        }

        $rollbackDeployment->appendLog("Symlink swapped successfully.");
    }

    /**
     * Run post-rollback tasks (cache clearing, queue restart, etc.).
     */
    private function runPostRollbackTasks(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): void
    {
        $rollbackDeployment->appendLog("Running post-rollback tasks...");

        $currentPath = $app->getCurrentPath();

        if ($app->isLaravel()) {
            $this->runLaravelPostRollbackTasks($currentPath, $rollbackDeployment);
        } elseif ($app->isNodejs()) {
            $this->runNodejsPostRollbackTasks($app, $rollbackDeployment);
        }

        $rollbackDeployment->appendLog("Post-rollback tasks completed.");
    }

    /**
     * Run Laravel-specific post-rollback tasks.
     */
    private function runLaravelPostRollbackTasks(string $currentPath, Deployment $rollbackDeployment): void
    {
        $rollbackDeployment->appendLog("Clearing Laravel caches...");

        // Clear and rebuild caches
        $commands = [
            "cd {$currentPath} && php artisan optimize:clear",
            "cd {$currentPath} && php artisan optimize",
            "cd {$currentPath} && php artisan queue:restart",
        ];

        foreach ($commands as $command) {
            $result = $this->sshService->execute($command . " 2>&1", 60);
            if (!empty($result['output'])) {
                $rollbackDeployment->appendLog($result['output']);
            }
        }
    }

    /**
     * Run Node.js-specific post-rollback tasks.
     */
    private function runNodejsPostRollbackTasks(Application $app, Deployment $rollbackDeployment): void
    {
        $appName = \Illuminate\Support\Str::slug($app->name);

        $rollbackDeployment->appendLog("Restarting PM2 process: {$appName}");

        $result = $this->sshService->execute("pm2 restart {$appName} 2>&1", 60);

        if (!empty($result['output'])) {
            $rollbackDeployment->appendLog($result['output']);
        }
    }

    /**
     * Get available releases for rollback.
     */
    public function getAvailableReleases(Application $app): array
    {
        if (!$app->usesAtomicDeployments()) {
            return [];
        }

        $this->sshService->connect($app->server);

        $releasesPath = $app->getReleasesPath();
        $result = $this->sshService->execute("ls -1 {$releasesPath} 2>/dev/null | sort -r");

        $this->sshService->disconnect();

        if (!$result['success'] || empty(trim($result['output']))) {
            return [];
        }

        $releaseIds = array_filter(explode("\n", trim($result['output'])));

        // Get deployments for these releases
        $deployments = $app->deployments()
            ->whereIn('release_id', $releaseIds)
            ->where('status', 'success')
            ->get()
            ->keyBy('release_id');

        $releases = [];
        foreach ($releaseIds as $releaseId) {
            $deployment = $deployments->get($releaseId);
            $releases[] = [
                'release_id' => $releaseId,
                'deployment_id' => $deployment?->id,
                'is_active' => $deployment?->is_active ?? false,
                'commit_hash' => $deployment?->commit_hash,
                'commit_message' => $deployment?->commit_message,
                'created_at' => $deployment?->created_at?->toIso8601String(),
            ];
        }

        return $releases;
    }
}
