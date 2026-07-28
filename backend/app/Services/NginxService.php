<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Support\RemoteSudo;
use Illuminate\Support\Str;
use RuntimeException;

class NginxService
{
    /**
     * Canonical webroot every template serves ACME challenges from and
     * certbot writes them to. A fixed path works for all app types; document
     * roots do not (Node.js proxies everything to the app process).
     */
    public const ACME_WEBROOT = '/var/www/letsencrypt';

    public function __construct(
        private SSHService $sshService,
        private PhpFpmPoolService $phpFpmPoolService,
    ) {}

    public function generateConfig(Application $app): string
    {
        return match ($app->type) {
            'laravel' => $this->laravelTemplate($app),
            'nodejs' => $this->nodejsTemplate($app),
            'static' => $this->staticTemplate($app),
            default => throw new RuntimeException("Unknown application type: {$app->type}"),
        };
    }

    /**
     * Immutable config file name for an application. Never derived from the
     * domain: domains are mutable, and a renamed primary domain used to leave
     * the old file enabled (serving stale config) forever.
     */
    public function configName(Application $app): string
    {
        return "shipyard-app-{$app->id}";
    }

    /**
     * Config file names this app may have been deployed under before the
     * app-id naming convention (its domain names), plus any extra names the
     * caller knows about (e.g. a just-renamed domain).
     *
     * @param  array<int, string>  $extraNames
     * @return array<int, string>
     */
    private function legacyConfigNames(Application $app, array $extraNames = []): array
    {
        $names = array_merge($app->allDomainNames(), [$app->domain], $extraNames);
        $names = array_unique(array_filter($names));

        return array_values(array_diff($names, [$this->configName($app)]));
    }

    /**
     * Remove config files deployed under the given legacy (domain-based)
     * names. Assumes an open SSH connection; does not reload nginx.
     *
     * @param  array<int, string>  $names
     */
    private function removeLegacyConfigs(Server $server, array $names): void
    {
        foreach ($names as $name) {
            $enabled = escapeshellarg("/etc/nginx/sites-enabled/{$name}");
            $available = escapeshellarg("/etc/nginx/sites-available/{$name}");
            $this->sshService->execute(RemoteSudo::wrap($server, "rm -f {$enabled} {$available}"));
        }
    }

    /**
     * Write a config file into /etc/nginx. SFTP writes run as the SSH user
     * and cannot create files there for non-root users, so the content is
     * staged in /tmp and moved into place as root. Assumes an open SSH
     * connection.
     */
    private function uploadConfig(Server $server, string $content, string $destination): void
    {
        $temp = '/tmp/shipyard-nginx-'.Str::random(16);
        $this->sshService->uploadContent($content, $temp);
        $this->sshService->execute(RemoteSudo::wrap($server, "mv -f {$temp} {$destination}"));
        $this->sshService->execute(RemoteSudo::wrap($server, "chmod 644 {$destination}"));
    }

    /**
     * @param  array<int, string>  $extraLegacyNames  domain-based config names
     *                                                to clean up besides the app's current domains
     */
    public function deploy(Application $app, array $extraLegacyNames = []): bool
    {
        // The vhost is about to reference the ShipYard pool socket; make
        // sure the pool exists first. Idempotent and cheap when unchanged.
        // Must run before this service's own connect(): ensurePool opens
        // and closes its own SSH session on the shared SSHService.
        if ($app->type === 'laravel' && $app->server?->deploy_user !== null) {
            $this->phpFpmPoolService->ensurePool($app->server, $app->getPhpVersion());
        }

        $config = $this->generateConfig($app);
        $server = $app->server;
        $configName = $this->configName($app);
        $configPath = "/etc/nginx/sites-available/{$configName}";
        $enabledPath = "/etc/nginx/sites-enabled/{$configName}";

        $this->sshService->connect($server);

        // Snapshot the currently deployed config so a failed test can roll
        // back. Leaving a broken config enabled would make every later
        // nginx -t on this server fail and break nginx restarts for all sites.
        $existing = $this->sshService->execute("cat {$configPath} 2>/dev/null");
        $previousConfig = ($existing['success'] && ! empty($existing['output'])) ? $existing['output'] : null;

        // Upload config
        $this->uploadConfig($server, $config, $configPath);

        // Create symlink
        $this->sshService->execute(RemoteSudo::wrap($server, "ln -sf {$configPath} {$enabledPath}"));

        // Test nginx config
        $result = $this->sshService->execute(RemoteSudo::wrap($server, 'nginx -t 2>&1'));
        if (! $result['success']) {
            if ($previousConfig !== null) {
                // Restore the previous (working) config
                $this->uploadConfig($server, $previousConfig, $configPath);
            } else {
                // No previous config: remove the broken one entirely
                $this->sshService->execute(RemoteSudo::wrap($server, "rm -f {$enabledPath} {$configPath}"));
            }

            $this->sshService->disconnect();
            throw new RuntimeException("Nginx config test failed: {$result['output']}");
        }

        // The config test passed: clean up files deployed under the old
        // domain-based naming so they stop serving (stale server_name,
        // stale cert paths). Done before the reload so one reload covers
        // both changes, and only after a successful test so a failed deploy
        // leaves the previously serving files untouched.
        $this->removeLegacyConfigs($server, $this->legacyConfigNames($app, $extraLegacyNames));

        // Reload nginx
        $result = $this->sshService->execute(RemoteSudo::wrap($server, 'systemctl reload nginx'));

        $this->sshService->disconnect();

        return $result['success'];
    }

    public function remove(Application $app): bool
    {
        $server = $app->server;
        $configName = $this->configName($app);
        $configPath = "/etc/nginx/sites-available/{$configName}";
        $enabledPath = "/etc/nginx/sites-enabled/{$configName}";

        $this->sshService->connect($server);

        $this->sshService->execute(RemoteSudo::wrap($server, "rm -f {$enabledPath}"));
        $this->sshService->execute(RemoteSudo::wrap($server, "rm -f {$configPath}"));

        // Also remove files deployed under the old domain-based naming so a
        // deleted app cannot keep serving through an orphaned config.
        $this->removeLegacyConfigs($server, $this->legacyConfigNames($app));

        // Removing this app's files cannot break the config, but another
        // site's config may already be broken; reloading then would take
        // every site down. Test first and surface the problem instead.
        $test = $this->sshService->execute(RemoteSudo::wrap($server, 'nginx -t 2>&1'));
        if (! $test['success']) {
            $this->sshService->disconnect();
            throw new RuntimeException("Nginx config test failed after removing the app config, reload skipped: {$test['output']}");
        }

        $result = $this->sshService->execute(RemoteSudo::wrap($server, 'systemctl reload nginx'));

        $this->sshService->disconnect();

        return $result['success'];
    }

    /**
     * Get the current nginx configuration content from the server.
     */
    public function getConfigContent(Application $app): string
    {
        $configPath = "/etc/nginx/sites-available/{$this->configName($app)}";

        $this->sshService->connect($app->server);

        $result = $this->sshService->execute("cat {$configPath} 2>/dev/null");

        $this->sshService->disconnect();

        if (! $result['success'] || empty($result['output'])) {
            // Return a generated config if no config file exists
            return $this->generateConfig($app);
        }

        return $result['output'];
    }

    /**
     * Update nginx configuration with custom content.
     */
    public function updateConfigContent(Application $app, string $content): bool
    {
        $server = $app->server;
        $configName = $this->configName($app);
        $configPath = "/etc/nginx/sites-available/{$configName}";
        $enabledPath = "/etc/nginx/sites-enabled/{$configName}";

        $this->sshService->connect($server);

        // Snapshot the currently deployed config so a failed test restores a
        // known-working state (a freshly generated config is not guaranteed
        // to be valid either)
        $existing = $this->sshService->execute("cat {$configPath} 2>/dev/null");
        $previousConfig = ($existing['success'] && ! empty($existing['output'])) ? $existing['output'] : null;

        // Upload the new config
        $this->uploadConfig($server, $content, $configPath);

        // Ensure symlink exists
        $this->sshService->execute(RemoteSudo::wrap($server, "ln -sf {$configPath} {$enabledPath}"));

        // Test nginx config
        $result = $this->sshService->execute(RemoteSudo::wrap($server, 'nginx -t 2>&1'));
        if (! $result['success']) {
            if ($previousConfig !== null) {
                $this->uploadConfig($server, $previousConfig, $configPath);
            } else {
                $this->sshService->execute(RemoteSudo::wrap($server, "rm -f {$enabledPath} {$configPath}"));
            }

            $this->sshService->disconnect();
            throw new RuntimeException("Nginx config test failed: {$result['output']}");
        }

        // Reload nginx
        $result = $this->sshService->execute(RemoteSudo::wrap($server, 'systemctl reload nginx'));

        $this->sshService->disconnect();

        return $result['success'];
    }

    /**
     * Get server_name directive value with all domains.
     */
    private function getServerNames(Application $app): string
    {
        $domains = $app->allDomainNames();
        if (empty($domains)) {
            return $app->domain;
        }

        return implode(' ', $domains);
    }

    /**
     * Get all SSL-enabled domains.
     */
    private function getSslDomains(Application $app): array
    {
        return $app->domains()
            ->where('ssl_enabled', true)
            ->get()
            ->all();
    }

    /**
     * Get domain names that do NOT have SSL enabled.
     */
    private function getNonSslDomainNames(Application $app): array
    {
        $nonSslDomains = $app->domains()
            ->where('ssl_enabled', false)
            ->pluck('domain')
            ->toArray();

        // Include legacy app domain if no domains exist
        if (empty($nonSslDomains) && $app->domains()->count() === 0 && $app->domain) {
            $nonSslDomains[] = $app->domain;
        }

        return $nonSslDomains;
    }

    /**
     * Servers with a deploy user serve PHP through the ShipYard managed
     * pool (owned by that user); legacy servers keep the distro default
     * pool socket. Decided per server so one server never mixes layouts.
     */
    private function phpSocketPath(Application $app): string
    {
        $version = $app->getPhpVersion();

        if ($app->server?->deploy_user !== null) {
            return PhpFpmPoolService::socketPath($version);
        }

        return "/var/run/php/php{$version}-fpm.sock";
    }

    private function laravelTemplate(Application $app): string
    {
        $serverName = $this->getServerNames($app);
        $root = $app->getDocumentRoot();
        $phpSocket = 'unix:'.$this->phpSocketPath($app);
        $acme = $this->acmeLocationBlock();

        // SSL blocks are emitted per SSL-enabled Domain row only. The legacy
        // app-level ssl_enabled flag without such a row used to produce an
        // invalid config (empty server_name, no 443 block).
        $sslDomains = $this->getSslDomains($app);

        if (! empty($sslDomains)) {
            $nonSslDomains = $this->getNonSslDomainNames($app);

            $blocks = [];

            // Port 80 block: redirect SSL domains, serve non-SSL domains
            $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, add a separate port 80 block that serves them
            if (! empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass {$phpSocket};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;

                // Redirect block for SSL domains only
                $sslDomainNames = implode(' ', array_map(fn ($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;
            }

            // One port 443 block per SSL domain
            foreach ($sslDomains as $sslDomain) {
                $ssl = $this->sslBlockForDomain($sslDomain);
                $blocks[] = <<<NGINX
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$sslDomain->domain};
    root {$root};

    {$ssl}

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass {$phpSocket};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
            }

            return implode("\n\n", $blocks);
        }

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass {$phpSocket};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    private function nodejsTemplate(Application $app): string
    {
        $serverName = $this->getServerNames($app);
        $port = $app->getPort();
        $acme = $this->acmeLocationBlock();

        // See laravelTemplate: SSL blocks only for SSL-enabled Domain rows.
        $sslDomains = $this->getSslDomains($app);

        if (! empty($sslDomains)) {
            $nonSslDomains = $this->getNonSslDomainNames($app);

            $blocks = [];

            // Port 80: redirect all to HTTPS
            $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, serve them on port 80 instead of redirecting
            if (! empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        proxy_pass http://localhost:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
    }
}
NGINX;

                $sslDomainNames = implode(' ', array_map(fn ($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;
            }

            // One port 443 block per SSL domain
            foreach ($sslDomains as $sslDomain) {
                $ssl = $this->sslBlockForDomain($sslDomain);
                $blocks[] = <<<NGINX
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$sslDomain->domain};

    {$ssl}

    location / {
        proxy_pass http://localhost:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
    }
}
NGINX;
            }

            return implode("\n\n", $blocks);
        }

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        proxy_pass http://localhost:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_cache_bypass \$http_upgrade;
    }
}
NGINX;
    }

    private function staticTemplate(Application $app): string
    {
        $serverName = $this->getServerNames($app);
        $root = $app->getDocumentRoot();
        $acme = $this->acmeLocationBlock();

        // See laravelTemplate: SSL blocks only for SSL-enabled Domain rows.
        $sslDomains = $this->getSslDomains($app);

        if (! empty($sslDomains)) {
            $nonSslDomains = $this->getNonSslDomainNames($app);

            $blocks = [];

            // Port 80: redirect all to HTTPS
            $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, serve them on port 80 instead of redirecting
            if (! empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to dotfiles except .well-known
    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX;

                $sslDomainNames = implode(' ', array_map(fn ($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;
            }

            // One port 443 block per SSL domain
            foreach ($sslDomains as $sslDomain) {
                $ssl = $this->sslBlockForDomain($sslDomain);
                $blocks[] = <<<NGINX
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {$sslDomain->domain};
    root {$root};

    {$ssl}

    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to dotfiles except .well-known
    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX;
            }

            return implode("\n\n", $blocks);
        }

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    {$acme}

    index index.html;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Deny access to dotfiles except .well-known
    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX;
    }

    /**
     * Location block serving ACME challenges from the canonical webroot.
     * `^~` keeps regex locations (like the dotfile deny rules) from
     * shadowing the challenge path. Rendered inside a server block, hence
     * the hardcoded indentation of the continuation lines.
     */
    private function acmeLocationBlock(): string
    {
        $webroot = self::ACME_WEBROOT;

        return "location ^~ /.well-known/acme-challenge/ {\n        root {$webroot};\n        allow all;\n    }";
    }

    private function sslBlockForDomain(Domain $domain): string
    {
        return <<<SSL
ssl_certificate /etc/letsencrypt/live/{$domain->domain}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{$domain->domain}/privkey.pem;
    ssl_session_timeout 1d;
    ssl_session_cache shared:SSL:50m;
    ssl_session_tickets off;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
SSL;
    }
}
