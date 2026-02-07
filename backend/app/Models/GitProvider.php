<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GitProvider extends Model
{
    use HasFactory;

    protected $appends = [
        'has_private_key',
        'has_access_token',
    ];

    protected $fillable = [
        'name',
        'type',
        'host',
        'access_token',
        'private_key',
        'username',
        'is_default',
    ];

    protected $hidden = [
        'access_token',
        'private_key',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'private_key' => 'encrypted',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // When setting a provider as default, unset others
        static::saving(function (GitProvider $provider) {
            if ($provider->is_default && $provider->isDirty('is_default')) {
                static::where('id', '!=', $provider->id ?? 0)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * Get the default host for this provider type
     */
    public function getDefaultHost(): string
    {
        return match ($this->type) {
            'gitlab' => 'gitlab.com',
            'github' => 'github.com',
            'bitbucket' => 'bitbucket.org',
            default => '',
        };
    }

    /**
     * Get the effective host (custom or default)
     */
    public function getEffectiveHost(): string
    {
        return $this->host ?: $this->getDefaultHost();
    }

    /**
     * Get the API base URL for the provider
     */
    public function getApiBaseUrl(): string
    {
        $host = $this->getEffectiveHost();

        return match ($this->type) {
            'gitlab' => "https://{$host}/api/v4",
            'github' => $host === 'github.com' ? 'https://api.github.com' : "https://{$host}/api/v3",
            'bitbucket' => 'https://api.bitbucket.org/2.0',
            default => '',
        };
    }

    /**
     * Get credentials for git operations
     * Returns [username, password] for HTTPS authentication
     */
    public function getCredentials(): array
    {
        return match ($this->type) {
            'gitlab' => ['oauth2', $this->access_token],
            'github' => ['x-access-token', $this->access_token],
            'bitbucket' => [$this->username ?: 'x-token-auth', $this->access_token],
            default => ['', $this->access_token],
        };
    }

    /**
     * Convert any repository URL to authenticated HTTPS URL
     */
    public function getAuthenticatedUrl(string $repoUrl): string
    {
        // Parse the repository URL to extract owner and repo
        $parsed = $this->parseRepositoryUrl($repoUrl);

        if (!$parsed) {
            return $repoUrl; // Return original if can't parse
        }

        $host = $this->getEffectiveHost();
        [$username, $password] = $this->getCredentials();

        // Build authenticated HTTPS URL
        $encodedPassword = urlencode($password);
        return "https://{$username}:{$encodedPassword}@{$host}/{$parsed['path']}.git";
    }

    /**
     * Get clean HTTPS URL (without credentials) for display
     */
    public function getCleanHttpsUrl(string $repoUrl): string
    {
        $parsed = $this->parseRepositoryUrl($repoUrl);

        if (!$parsed) {
            return $repoUrl;
        }

        $host = $this->getEffectiveHost();
        return "https://{$host}/{$parsed['path']}.git";
    }

    /**
     * Parse repository URL to extract path (owner/repo)
     */
    public function parseRepositoryUrl(string $repoUrl): ?array
    {
        // SSH format: git@gitlab.com:user/repo.git
        if (preg_match('/^git@[^:]+:(.+?)(?:\.git)?$/', $repoUrl, $matches)) {
            return ['path' => rtrim($matches[1], '.git')];
        }

        // HTTPS format: https://gitlab.com/user/repo.git
        if (preg_match('/^https?:\/\/[^\/]+\/(.+?)(?:\.git)?$/', $repoUrl, $matches)) {
            return ['path' => rtrim($matches[1], '.git')];
        }

        // Simple format: user/repo
        if (preg_match('/^[\w\-\.]+\/[\w\-\.]+$/', $repoUrl)) {
            return ['path' => $repoUrl];
        }

        return null;
    }

    /**
     * Check if this is a GitLab provider
     */
    public function isGitLab(): bool
    {
        return $this->type === 'gitlab';
    }

    /**
     * Check if this is a GitHub provider
     */
    public function isGitHub(): bool
    {
        return $this->type === 'github';
    }

    /**
     * Check if this is a Bitbucket provider
     */
    public function isBitbucket(): bool
    {
        return $this->type === 'bitbucket';
    }

    /**
     * Check if this provider uses SSH key authentication
     */
    public function usesSSHKey(): bool
    {
        return !empty($this->private_key);
    }

    /**
     * Get normalized private key with proper line endings
     */
    public function getNormalizedPrivateKey(): string
    {
        $key = $this->private_key;
        // Normalize line endings
        $key = str_replace("\r\n", "\n", $key);  // Windows line endings
        $key = str_replace("\r", "\n", $key);     // Old Mac line endings
        return trim($key) . "\n";                  // Ensure single trailing newline
    }

    /**
     * Check if this provider uses access token authentication
     */
    public function usesAccessToken(): bool
    {
        return !empty($this->access_token);
    }

    /**
     * Accessor for has_private_key attribute (for API responses)
     */
    public function getHasPrivateKeyAttribute(): bool
    {
        return $this->usesSSHKey();
    }

    /**
     * Accessor for has_access_token attribute (for API responses)
     */
    public function getHasAccessTokenAttribute(): bool
    {
        return $this->usesAccessToken();
    }

    /**
     * Convert any repository URL to SSH format (git@host:path.git)
     */
    public function getSSHUrl(string $repoUrl): string
    {
        $parsed = $this->parseRepositoryUrl($repoUrl);

        if (!$parsed) {
            return $repoUrl;
        }

        $host = $this->getEffectiveHost();
        return "git@{$host}:{$parsed['path']}.git";
    }
}
