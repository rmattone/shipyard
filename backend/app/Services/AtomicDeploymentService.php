<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Concerns\RunsRemoteScripts;
use App\Support\EnvFile;
use RuntimeException;

class AtomicDeploymentService
{
    use RunsRemoteScripts;

    public function __construct(
        private SSHService $sshService,
        private GitProviderService $gitProviderService
    ) {}

    /**
     * Initialize the atomic deployment directory structure.
     * Creates releases/ and shared/ directories (shared only for Laravel).
     */
    public function initializeStructure(Application $app, Deployment $deployment): void
    {
        $this->ensureConnected($app);
        $releasesPath = $app->getReleasesPath();

        $deployment->appendLog('Initializing atomic deployment structure...');

        // Create base and releases directories
        $this->sshService->execute('mkdir -p '.escapeshellarg($releasesPath));

        // Create shared directory and full storage structure for Laravel apps
        if ($app->isLaravel()) {
            $sharedPath = $app->getSharedPath();
            $this->sshService->execute('mkdir -p '.escapeshellarg($sharedPath));

            // Create full Laravel storage structure in shared directory
            $storageDirs = [
                'storage/app/public',
                'storage/framework/cache',
                'storage/framework/sessions',
                'storage/framework/views',
                'storage/logs',
            ];

            foreach ($storageDirs as $dir) {
                $this->sshService->execute('mkdir -p '.escapeshellarg("{$sharedPath}/{$dir}"));
            }

            $deployment->appendLog('Created shared storage structure for Laravel app.');
        }

        $deployment->appendLog('Atomic deployment structure initialized.');
    }

    /**
     * Create a new release directory and clone the repository into it.
     */
    public function createRelease(Application $app, Deployment $deployment): string
    {
        $this->ensureConnected($app);
        $releaseId = $deployment->release_id;
        $releasePath = "{$app->getReleasesPath()}/{$releaseId}";

        $deployment->appendLog("Creating release: {$releaseId}");

        // Clone repository into release directory
        $this->cloneRepository($app, $deployment, $releasePath);

        return $releasePath;
    }

    /**
     * Clone the repository into the release directory.
     */
    private function cloneRepository(Application $app, Deployment $deployment, string $releasePath): void
    {
        $branch = $app->branch;
        $repo = $app->repository_url;

        $deployment->appendLog('Cloning repository into release directory...');

        if ($app->gitProvider) {
            $cloneScript = $this->gitProviderService->generateCloneCommand(
                $app->gitProvider,
                $repo,
                $branch,
                $releasePath
            );

            // The script embeds credentials; runRemoteScript handles the
            // owner-only permissions, random name, and guaranteed cleanup
            $result = $this->runRemoteScript($app->server, $cloneScript, 300);
        } else {
            // Fallback to direct clone (assumes SSH keys are configured on server)
            $result = $this->sshService->execute(
                sprintf('git clone -b %s %s %s 2>&1', escapeshellarg($branch), escapeshellarg($repo), escapeshellarg($releasePath)),
                300
            );
        }

        $deployment->appendLog($result['output']);

        if (! $result['success']) {
            throw new RuntimeException("Git clone failed: {$result['output']}");
        }

        $deployment->appendLog('Repository cloned successfully.');
    }

    /**
     * Create symlinks from the release to shared paths.
     * Only applies to Laravel applications.
     */
    public function linkSharedPaths(Application $app, Deployment $deployment, string $releasePath): void
    {
        if (! $app->isLaravel()) {
            return;
        }

        $this->ensureConnected($app);
        $sharedPath = $app->getSharedPath();
        $sharedPaths = $app->getEffectiveSharedPaths();

        $deployment->appendLog('Linking shared paths...');

        foreach ($sharedPaths as $path) {
            // An empty, absolute, or traversal entry would make the rm -rf
            // below hit the release or releases directory itself
            $this->assertSafeRelativePath($path, 'shared');

            $releaseTarget = "{$releasePath}/{$path}";
            $sharedSource = "{$sharedPath}/{$path}";

            // Remove existing directory/file in release if it exists
            $this->sshService->execute('rm -rf '.escapeshellarg($releaseTarget));

            // Create parent directory in release if needed
            $parentDir = dirname($releaseTarget);
            $this->sshService->execute('mkdir -p '.escapeshellarg($parentDir));

            // Create symlink from release to shared
            $this->sshService->execute('ln -nfs '.escapeshellarg($sharedSource).' '.escapeshellarg($releaseTarget));

            $deployment->appendLog("  Linked: {$path}");
        }

        $deployment->appendLog('Shared paths linked successfully.');
    }

    /**
     * Upload environment file to the shared directory.
     * For atomic deployments, .env lives in shared/ and is symlinked.
     */
    public function uploadEnvFile(Application $app, Deployment $deployment, string $releasePath): void
    {
        $envVariables = $app->environmentVariables;

        if ($envVariables->isEmpty()) {
            $deployment->appendLog('No environment variables to upload.');

            return;
        }

        $this->ensureConnected($app);
        $deployment->appendLog('Uploading .env file...');

        $envContent = EnvFile::serialize($envVariables);

        // For atomic deployments with Laravel, upload to shared directory
        if ($app->usesAtomicDeployments() && $app->isLaravel()) {
            $envPath = "{$app->getSharedPath()}/.env";
        } else {
            $envPath = "{$releasePath}/.env";
        }

        $this->sshService->connectSftp($app->server);
        $this->sshService->uploadContent($envContent, $envPath);

        $deployment->appendLog('.env file uploaded successfully.');
    }

    /**
     * Set permissions on writable paths.
     * Only applies to Laravel applications.
     */
    public function setPermissions(Application $app, Deployment $deployment, string $releasePath): void
    {
        if (! $app->isLaravel()) {
            return;
        }

        $this->ensureConnected($app);
        $deployment->appendLog('Setting permissions on writable paths...');

        // PHP-FPM runs as the deploy user on home-layout servers (see
        // PhpFpmPoolService), www-data otherwise. Writable paths must be
        // owned by whichever user actually executes the code.
        $owner = escapeshellarg(($app->server?->phpRuntimeUser() ?? 'www-data').':www-data');

        $writablePaths = $app->getEffectiveWritablePaths();

        foreach ($writablePaths as $path) {
            $this->assertSafeRelativePath($path, 'writable');

            $fullPath = escapeshellarg("{$releasePath}/{$path}");

            // Set ownership to the PHP runtime user
            $this->sshService->execute(
                "sudo chown -R {$owner} {$fullPath} 2>/dev/null || chown -R {$owner} {$fullPath} 2>/dev/null || true"
            );

            // Set directory permissions
            $this->sshService->execute(
                "sudo chmod -R 775 {$fullPath} 2>/dev/null || chmod -R 775 {$fullPath} 2>/dev/null || true"
            );
        }

        // Also set permissions on shared storage directory
        $sharedPath = escapeshellarg($app->getSharedPath());
        $this->sshService->execute(
            "sudo chown -R {$owner} {$sharedPath} 2>/dev/null || chown -R {$owner} {$sharedPath} 2>/dev/null || true"
        );
        $this->sshService->execute(
            "sudo chmod -R 775 {$sharedPath} 2>/dev/null || chmod -R 775 {$sharedPath} 2>/dev/null || true"
        );

        $deployment->appendLog('Permissions set successfully.');
    }

    /**
     * Activate a release by atomically swapping the current symlink.
     */
    public function activateRelease(Application $app, Deployment $deployment, string $releasePath): void
    {
        $this->ensureConnected($app);
        $currentPath = $app->getCurrentPath();

        $deployment->appendLog('Activating release...');

        $result = $this->sshService->execute($this->atomicSwapCommand($releasePath, $currentPath));

        if (! $result['success']) {
            throw new RuntimeException("Failed to activate release: {$result['output']}");
        }

        $deployment->appendLog("Release activated: {$releasePath}");
        $deployment->appendLog('Current symlink now points to the new release.');
    }

    /**
     * Command that atomically points $currentPath at $releasePath. Used for
     * both activation and rollback.
     *
     * `ln -nfs` alone is unlink-then-symlink, leaving a window with no
     * `current` at all; staging the link and renaming it over `current` is
     * atomic (rename(2)). It also guards against `current` being a real
     * directory (an app switched from in-place to atomic): `ln -nfs` would
     * create the link INSIDE it and report success while old code keeps
     * serving.
     */
    public function atomicSwapCommand(string $releasePath, string $currentPath): string
    {
        $release = escapeshellarg($releasePath);
        $current = escapeshellarg($currentPath);
        $staged = escapeshellarg($currentPath.'.staged');

        return "if [ -e {$current} ] && [ ! -L {$current} ]; then"
            ." echo 'refusing to activate: current exists and is not a symlink' >&2; exit 1;"
            .' fi'
            ." && ln -sfn {$release} {$staged}"
            ." && mv -T {$staged} {$current}";
    }

    /**
     * Clean up old releases beyond the configured limit.
     */
    public function cleanupOldReleases(Application $app, Deployment $deployment): void
    {
        $this->ensureConnected($app);
        $releasesPath = $app->getReleasesPath();
        $keepReleases = max(1, $app->releases_to_keep ?? 5);

        $deployment->appendLog("Cleaning up old releases (keeping last {$keepReleases})...");

        // List all releases sorted by name (timestamp format ensures correct order)
        $result = $this->sshService->execute('ls -1d '.escapeshellarg($releasesPath).'/*/ 2>/dev/null | LC_ALL=C sort -r');

        if (! $result['success'] || empty(trim($result['output']))) {
            $deployment->appendLog('No releases to clean up.');

            return;
        }

        $currentReleasePath = $this->getCurrentReleasePath($app);

        $releases = array_filter(explode("\n", trim($result['output'])));
        $releasesToDelete = array_slice($releases, $keepReleases);

        if (empty($releasesToDelete)) {
            $deployment->appendLog('All releases within limit. No cleanup needed.');

            return;
        }

        $removed = 0;

        foreach ($releasesToDelete as $releaseDir) {
            $releaseDir = rtrim($releaseDir, '/');

            // Never delete the release the current symlink points to
            if ($currentReleasePath !== null && $releaseDir === rtrim($currentReleasePath, '/')) {
                $deployment->appendLog('  Skipped active release: '.basename($releaseDir));

                continue;
            }

            $this->sshService->execute('rm -rf '.escapeshellarg($releaseDir));
            $deployment->appendLog('  Removed: '.basename($releaseDir));
            $removed++;
        }

        $deployment->appendLog("Cleanup completed. Removed {$removed} old release(s).");
    }

    /**
     * Check if the atomic deployment structure has been initialized.
     */
    public function isInitialized(Application $app): bool
    {
        $releasesPath = $app->getReleasesPath();
        $this->ensureConnected($app);
        $result = $this->sshService->execute('test -d '.escapeshellarg($releasesPath)." && echo 'exists'");

        return str_contains($result['output'], 'exists');
    }

    /**
     * Reject shared/writable path entries that would escape the release
     * directory (empty, absolute, or containing '..'). Such an entry would
     * turn the rm -rf in linkSharedPaths into a delete of the release or
     * releases directory itself.
     */
    private function assertSafeRelativePath(string $path, string $kind): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new RuntimeException(
                "Unsafe {$kind} path '{$path}': must be a relative path without '..'."
            );
        }
    }

    /**
     * Ensure SSH connection is established.
     */
    private function ensureConnected(Application $app): void
    {
        try {
            $this->sshService->execute('echo connected');
        } catch (\Exception $e) {
            $this->sshService->connect($app->server);
        }
    }

    /**
     * Get the currently active release path.
     */
    public function getCurrentReleasePath(Application $app): ?string
    {
        $currentPath = $app->getCurrentPath();
        $result = $this->sshService->execute('readlink -f '.escapeshellarg($currentPath).' 2>/dev/null');

        if ($result['success'] && ! empty(trim($result['output']))) {
            return trim($result['output']);
        }

        return null;
    }

    /**
     * Verify a release directory exists.
     */
    public function releaseExists(Application $app, string $releaseId): bool
    {
        $releasePath = "{$app->getReleasesPath()}/{$releaseId}";
        $result = $this->sshService->execute('test -d '.escapeshellarg($releasePath)." && echo 'exists'");

        return str_contains($result['output'], 'exists');
    }
}
