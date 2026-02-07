<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'git_provider_id',
        'name',
        'type',
        'node_version',
        'domain',
        'repository_url',
        'branch',
        'deploy_path',
        'build_command',
        'post_deploy_commands',
        'deploy_script',
        'ssl_enabled',
        'status',
        'webhook_secret',
        'deployment_strategy',
        'releases_to_keep',
        'shared_paths',
        'writable_paths',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'post_deploy_commands' => 'array',
            'ssl_enabled' => 'boolean',
            'shared_paths' => 'array',
            'writable_paths' => 'array',
            'releases_to_keep' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Application $application) {
            if (empty($application->webhook_secret)) {
                $application->webhook_secret = Str::random(40);
            }

            // Auto-generate deploy path if not set
            if (empty($application->deploy_path)) {
                $application->deploy_path = self::generateDeployPath($application->name);
            }

            // Don't set a default deploy script - use the dynamic default based on strategy
            // This allows the script to adapt if strategy changes
        });
    }

    public static function generateDeployPath(string $name): string
    {
        $safeName = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $name));
        $safeName = preg_replace('/-+/', '-', $safeName); // collapse multiple dashes
        $safeName = trim($safeName, '-');
        return "/var/www/shipyard/{$safeName}";
    }

    public static function getDefaultDeployScript(string $type, string $strategy = 'in_place'): string
    {
        if ($strategy === 'atomic') {
            return match ($type) {
                'laravel' => self::getAtomicLaravelScript(),
                'nodejs' => self::getAtomicNodejsScript(),
                'static' => self::getAtomicStaticScript(),
                default => "# Custom deployment script\ncd \$DEPLOY_PATH\n",
            };
        }

        return match ($type) {
            'laravel' => self::getDefaultLaravelScript(),
            'nodejs' => self::getDefaultNodejsScript(),
            'static' => self::getDefaultStaticScript(),
            default => "# Custom deployment script\ncd \$DEPLOY_PATH\n",
        };
    }

    private static function getDefaultLaravelScript(): string
    {
        return <<<'SCRIPT'
cd $DEPLOY_PATH

# Pull latest changes
git pull origin $BRANCH

# Install PHP dependencies
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Install NPM dependencies and build assets (if needed)
if [ -f "package.json" ]; then
    npm install
    npm run build
fi

# Run Laravel optimizations
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
php artisan view:cache
php artisan event:cache

# Set permissions for web server (www-data)
sudo chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
sudo chmod -R 775 storage bootstrap/cache 2>/dev/null || chmod -R 775 storage bootstrap/cache

# Restart queue workers (if using)
php artisan queue:restart

echo "Deployment completed successfully!"
SCRIPT;
    }

    private static function getDefaultNodejsScript(): string
    {
        return <<<'SCRIPT'
#!/bin/bash
set -e

# Source profile to ensure node/npm are in PATH (nvm, etc.)
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"

# Use specific Node.js version if specified
if [ -n "$NODE_VERSION" ]; then
    echo "Using Node.js version $NODE_VERSION..."
    nvm use $NODE_VERSION || nvm install $NODE_VERSION
fi

cd $DEPLOY_PATH

# Pull latest changes
git pull origin $BRANCH

# Install dependencies
npm install

# Build the application
npm run build

# Restart the application with PM2
pm2 restart $APP_NAME || pm2 start npm --name "$APP_NAME" -- start

echo "Deployment completed successfully!"
SCRIPT;
    }

    private static function getDefaultStaticScript(): string
    {
        return <<<'SCRIPT'
#!/bin/bash
set -e

# Source profile to ensure node/npm are in PATH (nvm, etc.)
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"

# Use specific Node.js version if specified
if [ -n "$NODE_VERSION" ]; then
    echo "Using Node.js version $NODE_VERSION..."
    nvm use $NODE_VERSION || nvm install $NODE_VERSION
fi

cd $DEPLOY_PATH

# Pull latest changes
git pull origin $BRANCH

# Install dependencies and build (if needed)
if [ -f "package.json" ]; then
    echo "Installing dependencies..."
    npm install
    echo "Building project..."
    npm run build
fi

echo "Deployment completed successfully!"
SCRIPT;
    }

    /**
     * Atomic deployment script for Laravel.
     * No git pull needed - repository is already cloned into release directory.
     * Shared paths (storage, .env) are symlinked by AtomicDeploymentService.
     */
    private static function getAtomicLaravelScript(): string
    {
        return <<<'SCRIPT'
#!/bin/bash
set -e

cd $DEPLOY_PATH

# Install PHP dependencies
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Install NPM dependencies and build assets (if needed)
if [ -f "package.json" ]; then
    npm install
    npm run build
fi

# Create public/storage symlink (points to storage/app/public which is in shared)
php artisan storage:link --force

# Run Laravel optimizations
php artisan optimize:clear
php artisan migrate --force
php artisan optimize
php artisan view:cache
php artisan event:cache

# Restart queue workers (if using)
php artisan queue:restart

echo "Deployment completed successfully!"
SCRIPT;
    }

    /**
     * Atomic deployment script for Node.js.
     * No git pull needed - repository is already cloned into release directory.
     */
    private static function getAtomicNodejsScript(): string
    {
        return <<<'SCRIPT'
#!/bin/bash
set -e

# Source profile to ensure node/npm are in PATH (nvm, etc.)
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"

# Use specific Node.js version if specified
if [ -n "$NODE_VERSION" ]; then
    echo "Using Node.js version $NODE_VERSION..."
    nvm use $NODE_VERSION || nvm install $NODE_VERSION
fi

cd $DEPLOY_PATH

# Install dependencies
npm install

# Build the application
npm run build

# Note: PM2 restart is handled after symlink activation
echo "Deployment completed successfully!"
SCRIPT;
    }

    /**
     * Atomic deployment script for static sites.
     * No git pull needed - repository is already cloned into release directory.
     */
    private static function getAtomicStaticScript(): string
    {
        return <<<'SCRIPT'
#!/bin/bash
set -e

# Source profile to ensure node/npm are in PATH (nvm, etc.)
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh"

# Use specific Node.js version if specified
if [ -n "$NODE_VERSION" ]; then
    echo "Using Node.js version $NODE_VERSION..."
    nvm use $NODE_VERSION || nvm install $NODE_VERSION
fi

cd $DEPLOY_PATH

# Install dependencies and build (if needed)
if [ -f "package.json" ]; then
    echo "Installing dependencies..."
    npm install
    echo "Building project..."
    npm run build
fi

echo "Deployment completed successfully!"
SCRIPT;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function gitProvider(): BelongsTo
    {
        return $this->belongsTo(GitProvider::class);
    }

    public function environmentVariables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->orderByDesc('created_at');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'application_tag')
            ->withTimestamps();
    }

    public function latestDeployment(): ?Deployment
    {
        return $this->deployments()->first();
    }

    /**
     * Get the primary domain for this application.
     */
    public function primaryDomain(): ?Domain
    {
        return $this->domains()->where('is_primary', true)->first();
    }

    /**
     * Get all domain names as an array of strings (for nginx server_name).
     */
    public function allDomainNames(): array
    {
        return $this->domains()->pluck('domain')->toArray();
    }

    public function isLaravel(): bool
    {
        return $this->type === 'laravel';
    }

    public function isNodejs(): bool
    {
        return $this->type === 'nodejs';
    }

    public function isStatic(): bool
    {
        return $this->type === 'static';
    }

    public function getWebhookUrl(): string
    {
        return url("/api/webhook/{$this->id}");
    }

    public function getDeployScriptWithVariables(?string $releasePath = null): string
    {
        // Use custom script if set, otherwise get default based on type and strategy
        $script = $this->deploy_script ?? self::getDefaultDeployScript(
            $this->type,
            $this->deployment_strategy ?? 'atomic'
        );

        $appName = Str::slug($this->name);

        // For atomic deployments, use the release path; otherwise use deploy_path
        $deployPath = $releasePath ?? $this->deploy_path;

        // Replace variables
        $replacements = [
            '$DEPLOY_PATH' => $deployPath,
            '$BRANCH' => $this->branch,
            '$APP_NAME' => $appName,
            '$DOMAIN' => $this->domain,
            '$NODE_VERSION' => $this->node_version ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $script);
    }

    /**
     * Check if this application uses atomic deployments.
     */
    public function usesAtomicDeployments(): bool
    {
        return $this->deployment_strategy === 'atomic';
    }

    /**
     * Get the base path for atomic deployments (same as deploy_path).
     */
    public function getBasePath(): string
    {
        return $this->deploy_path;
    }

    /**
     * Get the releases directory path.
     */
    public function getReleasesPath(): string
    {
        return "{$this->deploy_path}/releases";
    }

    /**
     * Get the current symlink path.
     */
    public function getCurrentPath(): string
    {
        return "{$this->deploy_path}/current";
    }

    /**
     * Get the shared directory path.
     */
    public function getSharedPath(): string
    {
        return "{$this->deploy_path}/shared";
    }

    /**
     * Get the document root for nginx configuration.
     * For atomic deployments, points to /current/public (Laravel) or /current/dist (static).
     * For in-place deployments, uses the traditional path.
     */
    public function getDocumentRoot(): string
    {
        if ($this->usesAtomicDeployments()) {
            return match ($this->type) {
                'laravel' => "{$this->getCurrentPath()}/public",
                'static' => "{$this->getCurrentPath()}/dist",
                'nodejs' => $this->getCurrentPath(),
                default => $this->getCurrentPath(),
            };
        }

        return match ($this->type) {
            'laravel' => "{$this->deploy_path}/public",
            'static' => "{$this->deploy_path}/dist",
            'nodejs' => $this->deploy_path,
            default => $this->deploy_path,
        };
    }

    /**
     * Get default shared paths based on application type.
     * Only Laravel apps need shared paths.
     */
    public static function getDefaultSharedPaths(string $type): array
    {
        if ($type === 'laravel') {
            return [
                'storage/app',
                'storage/logs',
                'storage/framework/cache',
                'storage/framework/sessions',
                'storage/framework/views',
                '.env',
            ];
        }

        return [];
    }

    /**
     * Get default writable paths based on application type.
     * Only Laravel apps need writable paths.
     */
    public static function getDefaultWritablePaths(string $type): array
    {
        if ($type === 'laravel') {
            return [
                'storage',
                'bootstrap/cache',
            ];
        }

        return [];
    }

    /**
     * Get the effective shared paths for this application.
     */
    public function getEffectiveSharedPaths(): array
    {
        return $this->shared_paths ?? self::getDefaultSharedPaths($this->type);
    }

    /**
     * Get the effective writable paths for this application.
     */
    public function getEffectiveWritablePaths(): array
    {
        return $this->writable_paths ?? self::getDefaultWritablePaths($this->type);
    }

    /**
     * Get the currently active deployment.
     */
    public function activeDeployment(): ?Deployment
    {
        return $this->deployments()
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get deployments available for rollback (successful, with existing release directories).
     */
    public function rollbackableDeployments()
    {
        return $this->deployments()
            ->where('status', 'success')
            ->whereNotNull('release_path')
            ->where('is_active', false)
            ->orderByDesc('created_at');
    }

    /**
     * Get the storage path for logs.
     * For atomic deployments, logs are in shared/storage/logs.
     * For in-place deployments, logs are in deploy_path/storage/logs.
     */
    public function getLogsPath(): string
    {
        if ($this->usesAtomicDeployments() && $this->isLaravel()) {
            return "{$this->getSharedPath()}/storage/logs";
        }

        return "{$this->deploy_path}/storage/logs";
    }
}
