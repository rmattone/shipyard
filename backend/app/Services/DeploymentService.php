<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Deployment;
use RuntimeException;

class DeploymentService
{
    public function __construct(
        private SSHService $sshService,
        private GitProviderService $gitProviderService,
        private AtomicDeploymentService $atomicDeploymentService
    ) {}

    /**
     * Run a deployment using the appropriate strategy.
     */
    public function runDeployment(Deployment $deployment): Deployment
    {
        $app = $deployment->application;

        if ($app->usesAtomicDeployments()) {
            return $this->runAtomicDeployment($deployment);
        }

        return $this->runInPlaceDeployment($deployment);
    }

    /**
     * Run an atomic deployment with timestamped releases.
     */
    private function runAtomicDeployment(Deployment $deployment): Deployment
    {
        $app = $deployment->application;

        try {
            $app->update(['status' => 'deploying']);
            $deployment->markAsRunning();

            $this->sshService->connect($app->server);

            // Initialize atomic structure if needed
            if (!$this->atomicDeploymentService->isInitialized($app)) {
                $this->atomicDeploymentService->initializeStructure($app, $deployment);
            }

            // Create release directory and clone repository
            $releasePath = $this->atomicDeploymentService->createRelease($app, $deployment);

            // Upload .env file (to shared directory for Laravel)
            $this->atomicDeploymentService->uploadEnvFile($app, $deployment, $releasePath);

            // Link shared paths (Laravel only)
            $this->atomicDeploymentService->linkSharedPaths($app, $deployment, $releasePath);

            // Run the deploy script
            $this->runDeployScript($app, $deployment, $releasePath);

            // Set permissions on writable paths (Laravel only)
            $this->atomicDeploymentService->setPermissions($app, $deployment, $releasePath);

            // Atomic symlink swap
            $this->atomicDeploymentService->activateRelease($app, $deployment, $releasePath);

            // Cleanup old releases
            $this->atomicDeploymentService->cleanupOldReleases($app, $deployment);

            $this->sshService->disconnect();

            // Mark deployment as active and successful
            $deployment->markAsActive();
            $deployment->markAsSuccess();
            $app->update(['status' => 'active']);

            return $deployment;

        } catch (\Exception $e) {
            $deployment->appendLog("ERROR: {$e->getMessage()}");
            $deployment->markAsFailed();
            $app->update(['status' => 'failed']);

            $this->sshService->disconnect();

            throw $e;
        }
    }

    /**
     * Run a traditional in-place deployment (git pull).
     */
    private function runInPlaceDeployment(Deployment $deployment): Deployment
    {
        $app = $deployment->application;

        try {
            $app->update(['status' => 'deploying']);
            $deployment->markAsRunning();

            $this->sshService->connect($app->server);

            // Ensure deploy directory exists
            $this->ensureDeployDirectoryExists($app, $deployment);

            // Clone repo if not exists
            $this->ensureRepositoryCloned($app, $deployment);

            // Upload .env file before running deploy script
            $this->uploadEnvFile($app, $deployment);

            // Run the deploy script
            $this->runDeployScript($app, $deployment);

            // Fix permissions for Laravel apps
            if ($app->isLaravel()) {
                $this->fixLaravelPermissions($app, $deployment);
            }

            $this->sshService->disconnect();

            $deployment->markAsSuccess();
            $app->update(['status' => 'active']);

            return $deployment;

        } catch (\Exception $e) {
            $deployment->appendLog("ERROR: {$e->getMessage()}");
            $deployment->markAsFailed();
            $app->update(['status' => 'failed']);

            $this->sshService->disconnect();

            throw $e;
        }
    }

    private function ensureDeployDirectoryExists(Application $app, Deployment $deployment): void
    {
        $path = $app->deploy_path;
        $parentDir = dirname($path);

        $deployment->appendLog("Ensuring deploy directory exists: {$path}");

        // Create parent directory if needed
        $this->sshService->execute("mkdir -p {$parentDir}");
    }

    private function ensureRepositoryCloned(Application $app, Deployment $deployment): void
    {
        $path = $app->deploy_path;
        $branch = $app->branch;
        $repo = $app->repository_url;

        // Check if directory exists and has git
        $result = $this->sshService->execute("test -d {$path}/.git && echo 'exists'");
        $exists = str_contains($result['output'], 'exists');

        if (!$exists) {
            $deployment->appendLog("Cloning repository...");

            // Use git provider credentials if available
            if ($app->gitProvider) {
                $cloneScript = $this->gitProviderService->generateCloneCommand(
                    $app->gitProvider,
                    $repo,
                    $branch,
                    $path
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
                $result = $this->sshService->execute("git clone -b {$branch} {$repo} {$path} 2>&1", 300);
            }

            $deployment->appendLog($result['output']);

            if (!$result['success']) {
                throw new RuntimeException("Git clone failed: {$result['output']}");
            }
        } else {
            // Repository exists, update remote URL if using git provider
            if ($app->gitProvider) {
                $remoteUrl = $app->gitProvider->usesSSHKey()
                    ? $app->gitProvider->getSSHUrl($repo)
                    : $app->gitProvider->getCleanHttpsUrl($repo);
                $this->sshService->execute("cd {$path} && git remote set-url origin {$remoteUrl}");
            }
        }
    }

    private function runDeployScript(Application $app, Deployment $deployment, ?string $releasePath = null): void
    {
        $script = $app->getDeployScriptWithVariables($releasePath);

        $deployment->appendLog("Running deployment script...");
        $deployment->appendLog("---");

        // Execute each line of the script
        $lines = explode("\n", $script);
        $scriptContent = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Skip empty lines and comments for logging
            if (!empty($trimmed) && !str_starts_with($trimmed, '#')) {
                $deployment->appendLog("> {$trimmed}");
            }
            $scriptContent .= $line . "\n";
        }

        // If git provider is configured, wrap script with GIT_ASKPASS setup
        if ($app->gitProvider) {
            $scriptContent = $this->wrapScriptWithGitCredentials($app, $scriptContent);
        }

        // Create a temporary script file and execute it
        $scriptPath = "/tmp/deploy-{$app->id}-" . time() . ".sh";

        // Upload script
        $this->sshService->connectSftp($app->server);
        $this->sshService->uploadContent($scriptContent, $scriptPath);

        // Make executable and run
        $this->sshService->connect($app->server);
        $this->sshService->execute("chmod +x {$scriptPath}");

        $result = $this->sshService->execute("bash {$scriptPath} 2>&1", 600);

        $deployment->appendLog("---");
        $deployment->appendLog($result['output']);

        // Cleanup
        $this->sshService->execute("rm -f {$scriptPath}");

        if (!$result['success']) {
            throw new RuntimeException("Deployment script failed with exit code: {$result['exit_code']}");
        }

        $deployment->appendLog("---");
        $deployment->appendLog("Deployment completed successfully!");
    }

    /**
     * Wrap deploy script with git credentials setup for authenticated operations
     */
    private function wrapScriptWithGitCredentials(Application $app, string $script): string
    {
        if ($app->gitProvider->usesSSHKey()) {
            return $this->wrapScriptWithSSHKey($app, $script);
        }

        return $this->wrapScriptWithAskPass($app, $script);
    }

    /**
     * Wrap deploy script with SSH key setup for authenticated git operations
     */
    private function wrapScriptWithSSHKey(Application $app, string $script): string
    {
        $privateKey = $app->gitProvider->getNormalizedPrivateKey();

        return <<<BASH
#!/bin/bash

# Setup SSH key for authenticated git operations
_SSH_KEY_FILE=\$(mktemp)
cat > "\$_SSH_KEY_FILE" << 'SSH_KEY_EOF'
{$privateKey}
SSH_KEY_EOF
chmod 600 "\$_SSH_KEY_FILE"

# Create SSH wrapper script for better compatibility
_SSH_WRAPPER=\$(mktemp)
cat > "\$_SSH_WRAPPER" << WRAPPER_EOF
#!/bin/bash
exec ssh -i "\$_SSH_KEY_FILE" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes "\\\$@"
WRAPPER_EOF
chmod +x "\$_SSH_WRAPPER"
export GIT_SSH="\$_SSH_WRAPPER"

# Cleanup function
_cleanup_ssh() {
    rm -f "\$_SSH_KEY_FILE" "\$_SSH_WRAPPER"
}
trap _cleanup_ssh EXIT

# Original deploy script
{$script}
BASH;
    }

    /**
     * Wrap deploy script with GIT_ASKPASS setup for authenticated git operations
     */
    private function wrapScriptWithAskPass(Application $app, string $script): string
    {
        [$username, $password] = $app->gitProvider->getCredentials();

        return <<<BASH
#!/bin/bash

# Setup GIT_ASKPASS for authenticated git operations
_GIT_ASKPASS_SCRIPT=\$(mktemp)
cat > "\$_GIT_ASKPASS_SCRIPT" << 'ASKPASS_EOF'
#!/bin/bash
case "\$1" in
    *Username*|*username*) echo '{$username}' ;;
    *Password*|*password*) echo '{$password}' ;;
esac
ASKPASS_EOF
chmod +x "\$_GIT_ASKPASS_SCRIPT"
export GIT_ASKPASS="\$_GIT_ASKPASS_SCRIPT"

# Cleanup function
_cleanup_askpass() {
    rm -f "\$_GIT_ASKPASS_SCRIPT"
}
trap _cleanup_askpass EXIT

# Original deploy script
{$script}
BASH;
    }

    private function fixLaravelPermissions(Application $app, Deployment $deployment): void
    {
        $path = $app->deploy_path;

        $deployment->appendLog("Fixing Laravel storage permissions...");

        // Set ownership to www-data (web server user)
        $this->sshService->execute("sudo chown -R www-data:www-data {$path}/storage {$path}/bootstrap/cache 2>/dev/null || true");

        // Set directory permissions
        $this->sshService->execute("sudo chmod -R 775 {$path}/storage {$path}/bootstrap/cache 2>/dev/null || chmod -R 775 {$path}/storage {$path}/bootstrap/cache 2>/dev/null || true");

        $deployment->appendLog("Permissions fixed.");
    }

    private function uploadEnvFile(Application $app, Deployment $deployment): void
    {
        $envVariables = $app->environmentVariables;

        if ($envVariables->isEmpty()) {
            $deployment->appendLog("No environment variables to upload.");
            return;
        }

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

        $envPath = "{$app->deploy_path}/.env";

        $this->sshService->connectSftp($app->server);
        $this->sshService->uploadContent($envContent, $envPath);

        $deployment->appendLog(".env file uploaded successfully.");
    }
}
