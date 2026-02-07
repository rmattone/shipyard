<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use RuntimeException;

class AtomicDeploymentService
{
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
        $basePath = $app->getBasePath();
        $releasesPath = $app->getReleasesPath();

        $deployment->appendLog("Initializing atomic deployment structure...");

        // Create base and releases directories
        $this->sshService->execute("mkdir -p {$releasesPath}");

        // Create shared directory and structure only for Laravel apps
        if ($app->isLaravel()) {
            $sharedPath = $app->getSharedPath();
            $this->sshService->execute("mkdir -p {$sharedPath}");

            // Create shared directory structure for Laravel
            $sharedPaths = $app->getEffectiveSharedPaths();
            foreach ($sharedPaths as $path) {
                // Skip .env file - it's handled separately
                if ($path === '.env') {
                    continue;
                }

                $fullSharedPath = "{$sharedPath}/{$path}";
                $this->sshService->execute("mkdir -p {$fullSharedPath}");
            }

            $deployment->appendLog("Created shared directories for Laravel app.");
        }

        $deployment->appendLog("Atomic deployment structure initialized.");
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

        $deployment->appendLog("Cloning repository into release directory...");

        if ($app->gitProvider) {
            $cloneScript = $this->gitProviderService->generateCloneCommand(
                $app->gitProvider,
                $repo,
                $branch,
                $releasePath
            );

            // Upload and execute the clone script
            $scriptPath = "/tmp/git-clone-{$app->id}-" . time() . ".sh";
            $this->sshService->connectSftp($app->server);
            $this->sshService->uploadContent($cloneScript, $scriptPath);
            $this->sshService->connect($app->server);
            $this->sshService->execute("chmod +x {$scriptPath}");
            $result = $this->sshService->execute("bash {$scriptPath} 2>&1", 300);
            $this->sshService->execute("rm -f {$scriptPath}");
        } else {
            // Fallback to direct clone (assumes SSH keys are configured on server)
            $result = $this->sshService->execute("git clone -b {$branch} {$repo} {$releasePath} 2>&1", 300);
        }

        $deployment->appendLog($result['output']);

        if (!$result['success']) {
            throw new RuntimeException("Git clone failed: {$result['output']}");
        }

        $deployment->appendLog("Repository cloned successfully.");
    }

    /**
     * Create symlinks from the release to shared paths.
     * Only applies to Laravel applications.
     */
    public function linkSharedPaths(Application $app, Deployment $deployment, string $releasePath): void
    {
        if (!$app->isLaravel()) {
            return;
        }

        $this->ensureConnected($app);
        $sharedPath = $app->getSharedPath();
        $sharedPaths = $app->getEffectiveSharedPaths();

        $deployment->appendLog("Linking shared paths...");

        foreach ($sharedPaths as $path) {
            $releaseTarget = "{$releasePath}/{$path}";
            $sharedSource = "{$sharedPath}/{$path}";

            // Remove existing directory/file in release if it exists
            $this->sshService->execute("rm -rf {$releaseTarget}");

            // Create parent directory in release if needed
            $parentDir = dirname($releaseTarget);
            $this->sshService->execute("mkdir -p {$parentDir}");

            // Create symlink from release to shared
            $this->sshService->execute("ln -nfs {$sharedSource} {$releaseTarget}");

            $deployment->appendLog("  Linked: {$path}");
        }

        $deployment->appendLog("Shared paths linked successfully.");
    }

    /**
     * Upload environment file to the shared directory.
     * For atomic deployments, .env lives in shared/ and is symlinked.
     */
    public function uploadEnvFile(Application $app, Deployment $deployment, string $releasePath): void
    {
        $envVariables = $app->environmentVariables;

        if ($envVariables->isEmpty()) {
            $deployment->appendLog("No environment variables to upload.");
            return;
        }

        $this->ensureConnected($app);
        $deployment->appendLog("Uploading .env file...");

        $envContent = '';
        foreach ($envVariables as $var) {
            $value = $var->value;
            // Escape special characters in the value
            if (preg_match('/[\s#]/', $value)) {
                $value = '"' . addslashes($value) . '"';
            }
            $envContent .= "{$var->key}={$value}\n";
        }

        // For atomic deployments with Laravel, upload to shared directory
        if ($app->usesAtomicDeployments() && $app->isLaravel()) {
            $envPath = "{$app->getSharedPath()}/.env";
        } else {
            $envPath = "{$releasePath}/.env";
        }

        $this->sshService->connectSftp($app->server);
        $this->sshService->uploadContent($envContent, $envPath);

        $deployment->appendLog(".env file uploaded successfully.");
    }

    /**
     * Set permissions on writable paths.
     * Only applies to Laravel applications.
     */
    public function setPermissions(Application $app, Deployment $deployment, string $releasePath): void
    {
        if (!$app->isLaravel()) {
            return;
        }

        $this->ensureConnected($app);
        $deployment->appendLog("Setting permissions on writable paths...");

        $writablePaths = $app->getEffectiveWritablePaths();

        foreach ($writablePaths as $path) {
            $fullPath = "{$releasePath}/{$path}";

            // Set ownership to www-data (web server user)
            $this->sshService->execute(
                "sudo chown -R www-data:www-data {$fullPath} 2>/dev/null || chown -R www-data:www-data {$fullPath} 2>/dev/null || true"
            );

            // Set directory permissions
            $this->sshService->execute(
                "sudo chmod -R 775 {$fullPath} 2>/dev/null || chmod -R 775 {$fullPath} 2>/dev/null || true"
            );
        }

        // Also set permissions on shared storage directory
        $sharedPath = $app->getSharedPath();
        $this->sshService->execute(
            "sudo chown -R www-data:www-data {$sharedPath} 2>/dev/null || chown -R www-data:www-data {$sharedPath} 2>/dev/null || true"
        );
        $this->sshService->execute(
            "sudo chmod -R 775 {$sharedPath} 2>/dev/null || chmod -R 775 {$sharedPath} 2>/dev/null || true"
        );

        $deployment->appendLog("Permissions set successfully.");
    }

    /**
     * Activate a release by atomically swapping the current symlink.
     */
    public function activateRelease(Application $app, Deployment $deployment, string $releasePath): void
    {
        $this->ensureConnected($app);
        $currentPath = $app->getCurrentPath();

        $deployment->appendLog("Activating release...");

        // Atomic symlink swap using ln -nfs
        // -n: treat LINK_NAME as a normal file if it is a symbolic link to a directory
        // -f: remove existing destination files
        // -s: make symbolic links instead of hard links
        $result = $this->sshService->execute("ln -nfs {$releasePath} {$currentPath}");

        if (!$result['success']) {
            throw new RuntimeException("Failed to activate release: {$result['output']}");
        }

        $deployment->appendLog("Release activated: {$releasePath}");
        $deployment->appendLog("Current symlink now points to the new release.");
    }

    /**
     * Clean up old releases beyond the configured limit.
     */
    public function cleanupOldReleases(Application $app, Deployment $deployment): void
    {
        $this->ensureConnected($app);
        $releasesPath = $app->getReleasesPath();
        $keepReleases = $app->releases_to_keep ?? 5;

        $deployment->appendLog("Cleaning up old releases (keeping last {$keepReleases})...");

        // List all releases sorted by name (timestamp format ensures correct order)
        $result = $this->sshService->execute("ls -1d {$releasesPath}/*/ 2>/dev/null | sort -r");

        if (!$result['success'] || empty(trim($result['output']))) {
            $deployment->appendLog("No releases to clean up.");
            return;
        }

        $releases = array_filter(explode("\n", trim($result['output'])));
        $releasesToDelete = array_slice($releases, $keepReleases);

        if (empty($releasesToDelete)) {
            $deployment->appendLog("All releases within limit. No cleanup needed.");
            return;
        }

        foreach ($releasesToDelete as $releaseDir) {
            $releaseDir = trim($releaseDir, '/');
            $this->sshService->execute("rm -rf {$releaseDir}");
            $deployment->appendLog("  Removed: " . basename($releaseDir));
        }

        $deployment->appendLog("Cleanup completed. Removed " . count($releasesToDelete) . " old release(s).");
    }

    /**
     * Check if the atomic deployment structure has been initialized.
     */
    public function isInitialized(Application $app): bool
    {
        $releasesPath = $app->getReleasesPath();
        $this->ensureConnected($app);
        $result = $this->sshService->execute("test -d {$releasesPath} && echo 'exists'");
        return str_contains($result['output'], 'exists');
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
        $result = $this->sshService->execute("readlink -f {$currentPath} 2>/dev/null");

        if ($result['success'] && !empty(trim($result['output']))) {
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
        $result = $this->sshService->execute("test -d {$releasePath} && echo 'exists'");
        return str_contains($result['output'], 'exists');
    }
}
