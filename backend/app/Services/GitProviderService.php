<?php

namespace App\Services;

use App\Models\GitProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitProviderService
{
    /**
     * Test connection to the git provider
     * Returns user info on success
     */
    public function testConnection(GitProvider $provider): array
    {
        // If using SSH key, test SSH connection instead of API
        if ($provider->usesSSHKey()) {
            return $this->testSSHConnection($provider);
        }

        // Otherwise use API-based testing
        return match ($provider->type) {
            'gitlab' => $this->testGitLabConnection($provider),
            'github' => $this->testGitHubConnection($provider),
            'bitbucket' => $this->testBitbucketConnection($provider),
            default => throw new RuntimeException("Unknown provider type: {$provider->type}"),
        };
    }

    /**
     * Validate SSH private key format
     */
    private function validatePrivateKey(string $privateKey): void
    {
        if (!preg_match('/^-----BEGIN (RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----/', $privateKey)) {
            throw new RuntimeException("Invalid SSH key format: Key must start with '-----BEGIN ... PRIVATE KEY-----'");
        }
        if (!preg_match('/-----END (RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----\n?$/', $privateKey)) {
            throw new RuntimeException("Invalid SSH key format: Key must end with '-----END ... PRIVATE KEY-----'");
        }
        if (stripos($privateKey, 'ENCRYPTED') !== false) {
            throw new RuntimeException("Passphrase-protected SSH keys are not supported. Please use an unencrypted key.");
        }
    }

    /**
     * Test SSH connection to the git provider
     */
    private function testSSHConnection(GitProvider $provider): array
    {
        $host = $provider->getEffectiveHost();
        $privateKey = $provider->getNormalizedPrivateKey();

        // Validate key format
        $this->validatePrivateKey($privateKey);

        // Create a temporary key file
        $keyFile = tempnam(sys_get_temp_dir(), 'ssh_key_');
        file_put_contents($keyFile, $privateKey);
        chmod($keyFile, 0600);

        try {
            // Build SSH command to test connection
            $command = sprintf(
                'ssh -i %s -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null -o BatchMode=yes -T git@%s 2>&1',
                escapeshellarg($keyFile),
                escapeshellarg($host)
            );

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
            $outputStr = implode("\n", $output);

            // Check for common SSH key errors
            if (stripos($outputStr, 'error in libcrypto') !== false) {
                throw new RuntimeException("SSH key format error. Please ensure the key is valid and not corrupted. Try generating a new key.");
            }
            if (stripos($outputStr, 'invalid format') !== false) {
                throw new RuntimeException("Invalid SSH key format. Please ensure you're using a valid OpenSSH private key.");
            }
            if (stripos($outputStr, 'Permission denied') !== false && stripos($outputStr, 'Welcome') === false) {
                throw new RuntimeException("SSH authentication failed. Please ensure the public key is added to your {$provider->type} account.");
            }

            // Git SSH servers return exit code 1 with a welcome message on success
            // They don't allow shell access, so exit code 1 is expected
            // We check if the output contains expected welcome messages
            $isSuccess = $returnCode <= 1 && (
                stripos($outputStr, 'successfully authenticated') !== false ||
                stripos($outputStr, 'You\'ve successfully authenticated') !== false ||
                stripos($outputStr, 'logged in as') !== false ||
                stripos($outputStr, 'Welcome to GitLab') !== false ||
                stripos($outputStr, 'Hi ') !== false ||  // GitHub format: "Hi username!"
                stripos($outputStr, 'authenticated via ssh key') !== false
            );

            if ($isSuccess) {
                // Try to extract username from output
                $username = 'Unknown';
                if (preg_match('/Hi ([^!]+)!/', $outputStr, $matches)) {
                    $username = $matches[1]; // GitHub
                } elseif (preg_match('/Welcome to GitLab, @([^!]+)!/', $outputStr, $matches)) {
                    $username = $matches[1]; // GitLab
                } elseif (preg_match('/logged in as ([^\s\.]+)/', $outputStr, $matches)) {
                    $username = $matches[1]; // Bitbucket
                }

                return [
                    'success' => true,
                    'username' => $username,
                    'name' => $username,
                    'email' => null,
                    'message' => 'SSH connection successful',
                ];
            }

            throw new RuntimeException("SSH connection failed: " . $outputStr);

        } finally {
            // Clean up temp key file
            @unlink($keyFile);
        }
    }

    /**
     * Generate the git clone command - dispatches to appropriate handler based on auth method
     */
    public function generateCloneCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        if ($provider->usesSSHKey()) {
            return $this->generateSSHCloneCommand($provider, $repoUrl, $branch, $targetPath);
        }

        if ($provider->usesAccessToken()) {
            return $this->generateHTTPSCloneCommand($provider, $repoUrl, $branch, $targetPath);
        }

        throw new RuntimeException('No authentication method configured for this provider');
    }

    /**
     * Generate the git clone command using SSH key authentication
     */
    public function generateSSHCloneCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        $sshUrl = $provider->getSSHUrl($repoUrl);
        $privateKey = $provider->getNormalizedPrivateKey();

        return <<<BASH
#!/bin/bash

# Create temporary SSH key file
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

# Clone with SSH wrapper
GIT_SSH="\$_SSH_WRAPPER" git clone -b {$branch} {$sshUrl} {$targetPath}
_CLONE_STATUS=\$?

# Cleanup
rm -f "\$_SSH_KEY_FILE" "\$_SSH_WRAPPER"

# Return clone status
exit \$_CLONE_STATUS
BASH;
    }

    /**
     * Generate the git clone command using HTTPS with credential helper
     */
    public function generateHTTPSCloneCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        [$username, $password] = $provider->getCredentials();
        $httpsUrl = $provider->getCleanHttpsUrl($repoUrl);

        return <<<BASH
# Create temporary askpass script
_ASKPASS_SCRIPT=\$(mktemp)
cat > "\$_ASKPASS_SCRIPT" << 'ASKPASS_EOF'
#!/bin/bash
case "\$1" in
    *Username*|*username*) echo '{$username}' ;;
    *Password*|*password*) echo '{$password}' ;;
esac
ASKPASS_EOF
chmod +x "\$_ASKPASS_SCRIPT"

# Clone with credential helper
GIT_ASKPASS="\$_ASKPASS_SCRIPT" git clone -b {$branch} {$httpsUrl} {$targetPath}
_CLONE_STATUS=\$?

# Cleanup
rm -f "\$_ASKPASS_SCRIPT"

# Return clone status
exit \$_CLONE_STATUS
BASH;
    }

    /**
     * Generate the git pull command - dispatches to appropriate handler based on auth method
     */
    public function generatePullCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        if ($provider->usesSSHKey()) {
            return $this->generateSSHPullCommand($provider, $repoUrl, $branch, $targetPath);
        }

        if ($provider->usesAccessToken()) {
            return $this->generateHTTPSPullCommand($provider, $repoUrl, $branch, $targetPath);
        }

        throw new RuntimeException('No authentication method configured for this provider');
    }

    /**
     * Generate the git pull command using SSH key authentication
     */
    public function generateSSHPullCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        $sshUrl = $provider->getSSHUrl($repoUrl);
        $privateKey = $provider->getNormalizedPrivateKey();

        return <<<BASH
#!/bin/bash
cd {$targetPath}

# Create temporary SSH key file
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

# Update remote URL to SSH if needed
git remote set-url origin {$sshUrl}

# Pull with SSH wrapper
GIT_SSH="\$_SSH_WRAPPER" git pull origin {$branch}
_PULL_STATUS=\$?

# Cleanup
rm -f "\$_SSH_KEY_FILE" "\$_SSH_WRAPPER"

# Return pull status
exit \$_PULL_STATUS
BASH;
    }

    /**
     * Generate the git pull command using HTTPS with credential helper
     */
    public function generateHTTPSPullCommand(
        GitProvider $provider,
        string $repoUrl,
        string $branch,
        string $targetPath
    ): string {
        [$username, $password] = $provider->getCredentials();
        $httpsUrl = $provider->getCleanHttpsUrl($repoUrl);

        return <<<BASH
cd {$targetPath}

# Create temporary askpass script
_ASKPASS_SCRIPT=\$(mktemp)
cat > "\$_ASKPASS_SCRIPT" << 'ASKPASS_EOF'
#!/bin/bash
case "\$1" in
    *Username*|*username*) echo '{$username}' ;;
    *Password*|*password*) echo '{$password}' ;;
esac
ASKPASS_EOF
chmod +x "\$_ASKPASS_SCRIPT"

# Update remote URL to HTTPS if needed
git remote set-url origin {$httpsUrl}

# Pull with credential helper
GIT_ASKPASS="\$_ASKPASS_SCRIPT" git pull origin {$branch}
_PULL_STATUS=\$?

# Cleanup
rm -f "\$_ASKPASS_SCRIPT"

# Return pull status
exit \$_PULL_STATUS
BASH;
    }

    /**
     * Generate SSH connection test command
     * Returns a bash script that tests SSH connectivity to the git host
     */
    public function generateSSHTestCommand(GitProvider $provider): string
    {
        $host = $provider->getEffectiveHost();
        $privateKey = $provider->private_key;

        return <<<BASH
# Create temporary SSH key file
_SSH_KEY_FILE=\$(mktemp)
cat > "\$_SSH_KEY_FILE" << 'SSH_KEY_EOF'
{$privateKey}
SSH_KEY_EOF
chmod 600 "\$_SSH_KEY_FILE"

# Test SSH connection (most git hosts return exit code 1 on success but with a welcome message)
ssh -i "\$_SSH_KEY_FILE" -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null -T git@{$host} 2>&1
_SSH_STATUS=\$?

# Cleanup
rm -f "\$_SSH_KEY_FILE"

# SSH to git servers returns 1 on success (they don't allow shell access)
# So we consider both 0 and 1 as success
if [ \$_SSH_STATUS -le 1 ]; then
    exit 0
else
    exit \$_SSH_STATUS
fi
BASH;
    }

    private function testGitLabConnection(GitProvider $provider): array
    {
        $response = Http::withHeaders([
            'PRIVATE-TOKEN' => $provider->access_token,
        ])->get("{$provider->getApiBaseUrl()}/user");

        if (!$response->successful()) {
            throw new RuntimeException(
                "GitLab API error: " . ($response->json('message') ?? $response->body())
            );
        }

        $user = $response->json();

        return [
            'success' => true,
            'username' => $user['username'] ?? 'Unknown',
            'name' => $user['name'] ?? 'Unknown',
            'email' => $user['email'] ?? null,
        ];
    }

    private function testGitHubConnection(GitProvider $provider): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$provider->access_token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$provider->getApiBaseUrl()}/user");

        if (!$response->successful()) {
            throw new RuntimeException(
                "GitHub API error: " . ($response->json('message') ?? $response->body())
            );
        }

        $user = $response->json();

        return [
            'success' => true,
            'username' => $user['login'] ?? 'Unknown',
            'name' => $user['name'] ?? $user['login'] ?? 'Unknown',
            'email' => $user['email'] ?? null,
        ];
    }

    private function testBitbucketConnection(GitProvider $provider): array
    {
        // Bitbucket uses app passwords with username:password authentication
        $response = Http::withBasicAuth(
            $provider->username ?? '',
            $provider->access_token
        )->get("{$provider->getApiBaseUrl()}/user");

        if (!$response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Bitbucket API error: {$error}");
        }

        $user = $response->json();

        return [
            'success' => true,
            'username' => $user['username'] ?? $provider->username ?? 'Unknown',
            'name' => $user['display_name'] ?? 'Unknown',
            'email' => null, // Bitbucket doesn't return email in user endpoint
        ];
    }

    /**
     * List repositories accessible by the git provider
     */
    public function listRepositories(GitProvider $provider, string $search = '', int $page = 1, int $perPage = 20): array
    {
        // Repository listing requires API access token
        if (!$provider->usesAccessToken()) {
            throw new RuntimeException('Repository listing requires an access token. SSH-only providers cannot list repositories.');
        }

        return match ($provider->type) {
            'gitlab' => $this->listGitLabRepositories($provider, $search, $page, $perPage),
            'github' => $this->listGitHubRepositories($provider, $search, $page, $perPage),
            'bitbucket' => $this->listBitbucketRepositories($provider, $search, $page, $perPage),
            default => throw new RuntimeException("Unknown provider type: {$provider->type}"),
        };
    }

    private function listGitLabRepositories(GitProvider $provider, string $search, int $page, int $perPage): array
    {
        $query = [
            'membership' => 'true',
            'per_page' => $perPage,
            'page' => $page,
            'order_by' => 'last_activity_at',
        ];

        if ($search) {
            $query['search'] = $search;
        }

        $response = Http::withHeaders([
            'PRIVATE-TOKEN' => $provider->access_token,
        ])->get("{$provider->getApiBaseUrl()}/projects", $query);

        if (!$response->successful()) {
            throw new RuntimeException("GitLab API error: " . ($response->json('message') ?? $response->body()));
        }

        $projects = $response->json();

        return [
            'repositories' => array_map(fn($project) => [
                'id' => $project['id'],
                'name' => $project['name'],
                'full_name' => $project['path_with_namespace'],
                'description' => $project['description'] ?? '',
                'url' => $project['web_url'],
                'ssh_url' => $project['ssh_url_to_repo'],
                'https_url' => $project['http_url_to_repo'],
                'default_branch' => $project['default_branch'] ?? 'main',
                'private' => $project['visibility'] === 'private',
                'updated_at' => $project['last_activity_at'] ?? null,
            ], $projects),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => count($projects) === $perPage,
        ];
    }

    private function listGitHubRepositories(GitProvider $provider, string $search, int $page, int $perPage): array
    {
        if ($search) {
            // Use search API for search queries
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$provider->access_token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get("{$provider->getApiBaseUrl()}/search/repositories", [
                'q' => $search . ' in:name user:@me',
                'per_page' => $perPage,
                'page' => $page,
                'sort' => 'updated',
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("GitHub API error: " . ($response->json('message') ?? $response->body()));
            }

            $data = $response->json();
            $repos = $data['items'] ?? [];
            $hasMore = ($data['total_count'] ?? 0) > ($page * $perPage);
        } else {
            // List user's repos
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$provider->access_token}",
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])->get("{$provider->getApiBaseUrl()}/user/repos", [
                'per_page' => $perPage,
                'page' => $page,
                'sort' => 'updated',
                'affiliation' => 'owner,collaborator,organization_member',
            ]);

            if (!$response->successful()) {
                throw new RuntimeException("GitHub API error: " . ($response->json('message') ?? $response->body()));
            }

            $repos = $response->json();
            $hasMore = count($repos) === $perPage;
        }

        return [
            'repositories' => array_map(fn($repo) => [
                'id' => $repo['id'],
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'description' => $repo['description'] ?? '',
                'url' => $repo['html_url'],
                'ssh_url' => $repo['ssh_url'],
                'https_url' => $repo['clone_url'],
                'default_branch' => $repo['default_branch'] ?? 'main',
                'private' => $repo['private'],
                'updated_at' => $repo['updated_at'] ?? null,
            ], $repos),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }

    private function listBitbucketRepositories(GitProvider $provider, string $search, int $page, int $perPage): array
    {
        $query = [
            'pagelen' => $perPage,
            'page' => $page,
            'sort' => '-updated_on',
            'role' => 'member',
        ];

        if ($search) {
            $query['q'] = "name ~ \"{$search}\"";
        }

        $response = Http::withBasicAuth(
            $provider->username ?? '',
            $provider->access_token
        )->get("{$provider->getApiBaseUrl()}/repositories", $query);

        if (!$response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Bitbucket API error: {$error}");
        }

        $data = $response->json();
        $repos = $data['values'] ?? [];

        return [
            'repositories' => array_map(fn($repo) => [
                'id' => $repo['uuid'],
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'description' => $repo['description'] ?? '',
                'url' => $repo['links']['html']['href'] ?? '',
                'ssh_url' => $this->getBitbucketCloneUrl($repo, 'ssh'),
                'https_url' => $this->getBitbucketCloneUrl($repo, 'https'),
                'default_branch' => $repo['mainbranch']['name'] ?? 'main',
                'private' => $repo['is_private'],
                'updated_at' => $repo['updated_on'] ?? null,
            ], $repos),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => !empty($data['next']),
        ];
    }

    private function getBitbucketCloneUrl(array $repo, string $type): string
    {
        foreach ($repo['links']['clone'] ?? [] as $link) {
            if ($link['name'] === $type) {
                return $link['href'];
            }
        }
        return '';
    }

    /**
     * List branches for a repository
     */
    public function listBranches(GitProvider $provider, string $repository): array
    {
        if (!$provider->usesAccessToken()) {
            throw new RuntimeException('Branch listing requires an access token.');
        }

        return match ($provider->type) {
            'gitlab' => $this->listGitLabBranches($provider, $repository),
            'github' => $this->listGitHubBranches($provider, $repository),
            'bitbucket' => $this->listBitbucketBranches($provider, $repository),
            default => throw new RuntimeException("Unknown provider type: {$provider->type}"),
        };
    }

    private function listGitLabBranches(GitProvider $provider, string $repository): array
    {
        // Repository can be path_with_namespace (owner/repo) or project ID
        $encodedRepo = urlencode($repository);

        $response = Http::withHeaders([
            'PRIVATE-TOKEN' => $provider->access_token,
        ])->get("{$provider->getApiBaseUrl()}/projects/{$encodedRepo}/repository/branches", [
            'per_page' => 100,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("GitLab API error: " . ($response->json('message') ?? $response->body()));
        }

        $branches = $response->json();

        return [
            'branches' => array_map(fn($branch) => [
                'name' => $branch['name'],
                'default' => $branch['default'] ?? false,
            ], $branches),
        ];
    }

    private function listGitHubBranches(GitProvider $provider, string $repository): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$provider->access_token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$provider->getApiBaseUrl()}/repos/{$repository}/branches", [
            'per_page' => 100,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException("GitHub API error: " . ($response->json('message') ?? $response->body()));
        }

        $branches = $response->json();

        // Get default branch from repo info
        $repoResponse = Http::withHeaders([
            'Authorization' => "Bearer {$provider->access_token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->get("{$provider->getApiBaseUrl()}/repos/{$repository}");

        $defaultBranch = $repoResponse->successful() ? ($repoResponse->json()['default_branch'] ?? 'main') : 'main';

        return [
            'branches' => array_map(fn($branch) => [
                'name' => $branch['name'],
                'default' => $branch['name'] === $defaultBranch,
            ], $branches),
        ];
    }

    private function listBitbucketBranches(GitProvider $provider, string $repository): array
    {
        $response = Http::withBasicAuth(
            $provider->username ?? '',
            $provider->access_token
        )->get("{$provider->getApiBaseUrl()}/repositories/{$repository}/refs/branches", [
            'pagelen' => 100,
        ]);

        if (!$response->successful()) {
            $error = $response->json('error.message') ?? $response->body();
            throw new RuntimeException("Bitbucket API error: {$error}");
        }

        $data = $response->json();
        $branches = $data['values'] ?? [];

        // Get default branch from repo info
        $repoResponse = Http::withBasicAuth(
            $provider->username ?? '',
            $provider->access_token
        )->get("{$provider->getApiBaseUrl()}/repositories/{$repository}");

        $defaultBranch = $repoResponse->successful() ? ($repoResponse->json()['mainbranch']['name'] ?? 'main') : 'main';

        return [
            'branches' => array_map(fn($branch) => [
                'name' => $branch['name'],
                'default' => $branch['name'] === $defaultBranch,
            ], $branches),
        ];
    }
}
