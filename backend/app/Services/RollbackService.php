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
        if (! $app->usesAtomicDeployments()) {
            throw new RuntimeException('Rollback is only supported for atomic deployments.');
        }

        if (! $targetDeployment->release_path) {
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

        if (! $currentDeployment) {
            throw new RuntimeException('No active deployment found.');
        }

        // Resolve the release the server is actually serving. "Previous" must
        // mean the newest successful release older than the live one; picking
        // "newest success that is not the active record" ping-pongs between
        // two releases (after R5->R4, it would re-activate R5).
        $currentReleasePath = $this->resolveCurrentReleasePath($app) ?? $currentDeployment->release_path;

        $targetDeployment = $this->findPreviousDeployment($app, $currentReleasePath);

        if (! $targetDeployment) {
            throw new RuntimeException('No previous deployment available for rollback.');
        }

        return $this->rollback($app, $targetDeployment, $rollbackDeployment);
    }

    /**
     * Resolve the release path the `current` symlink points to.
     */
    private function resolveCurrentReleasePath(Application $app): ?string
    {
        if (! $app->usesAtomicDeployments()) {
            return null;
        }

        $this->sshService->connect($app->server);
        $path = $this->atomicDeploymentService->getCurrentReleasePath($app);
        $this->sshService->disconnect();

        return $path;
    }

    /**
     * Find the newest successful deployment whose release is older than the
     * currently live release.
     */
    private function findPreviousDeployment(Application $app, ?string $currentReleasePath): ?Deployment
    {
        // The deployment that originally built the live release. Rollbacks
        // reuse an existing release_path, so the earliest record for that
        // path is the original deploy and marks the chronological cutoff.
        $reference = $currentReleasePath
            ? $app->deployments()
                ->where('release_path', $currentReleasePath)
                ->orderBy('id')
                ->first()
            : null;

        $query = $app->deployments()
            ->where('status', 'success')
            ->whereNotNull('release_path')
            ->orderByDesc('id');

        if ($currentReleasePath) {
            $query->where('release_path', '!=', $currentReleasePath);
        }

        if ($reference) {
            $query->where('id', '<', $reference->id);
        }

        return $query->first();
    }

    /**
     * Verify the target release directory exists.
     */
    private function verifyReleaseExists(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): void
    {
        $releasePath = $targetDeployment->release_path;

        $rollbackDeployment->appendLog("Verifying release directory exists: {$releasePath}");

        $result = $this->sshService->execute('test -d '.escapeshellarg($releasePath)." && echo 'exists'");

        if (! str_contains($result['output'], 'exists')) {
            throw new RuntimeException("Release directory not found: {$releasePath}");
        }

        $rollbackDeployment->appendLog('Release directory verified.');
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
        $result = $this->sshService->execute('ln -nfs '.escapeshellarg($releasePath).' '.escapeshellarg($currentPath));

        if (! $result['success']) {
            throw new RuntimeException("Failed to swap symlink: {$result['output']}");
        }

        $rollbackDeployment->appendLog('Symlink swapped successfully.');
    }

    /**
     * Run post-rollback tasks (cache clearing, queue restart, etc.).
     */
    private function runPostRollbackTasks(Application $app, Deployment $targetDeployment, Deployment $rollbackDeployment): void
    {
        $rollbackDeployment->appendLog('Running post-rollback tasks...');

        $currentPath = $app->getCurrentPath();

        if ($app->isLaravel()) {
            $this->runLaravelPostRollbackTasks($currentPath, $rollbackDeployment);
        } elseif ($app->isNodejs()) {
            $this->runNodejsPostRollbackTasks($app, $rollbackDeployment);
        }

        $rollbackDeployment->appendLog('Post-rollback tasks completed.');
    }

    /**
     * Run Laravel-specific post-rollback tasks.
     */
    private function runLaravelPostRollbackTasks(string $currentPath, Deployment $rollbackDeployment): void
    {
        $rollbackDeployment->appendLog('Clearing Laravel caches...');

        // Clear and rebuild caches
        $currentPath = escapeshellarg($currentPath);
        $commands = [
            "cd {$currentPath} && php artisan optimize:clear",
            "cd {$currentPath} && php artisan optimize",
            "cd {$currentPath} && php artisan queue:restart",
        ];

        foreach ($commands as $command) {
            $result = $this->sshService->execute($command.' 2>&1', 60);
            if (! empty($result['output'])) {
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

        $result = $this->sshService->execute($app->buildPm2RestartCommand().' 2>&1', 120);

        if (! empty($result['output'])) {
            $rollbackDeployment->appendLog($result['output']);
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to restart PM2 process after rollback.');
        }
    }

    /**
     * Get available releases for rollback.
     */
    public function getAvailableReleases(Application $app): array
    {
        if (! $app->usesAtomicDeployments()) {
            return [];
        }

        $this->sshService->connect($app->server);

        $releasesPath = $app->getReleasesPath();
        $result = $this->sshService->execute('ls -1 '.escapeshellarg($releasesPath).' 2>/dev/null | LC_ALL=C sort -r');

        $this->sshService->disconnect();

        if (! $result['success'] || empty(trim($result['output']))) {
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
