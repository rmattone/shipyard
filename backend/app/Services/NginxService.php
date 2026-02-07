<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Domain;
use RuntimeException;

class NginxService
{
    public function __construct(
        private SSHService $sshService
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

    public function deploy(Application $app): bool
    {
        $config = $this->generateConfig($app);
        $primaryDomain = $app->primaryDomain()?->domain ?? $app->domain;
        $configPath = "/etc/nginx/sites-available/{$primaryDomain}";
        $enabledPath = "/etc/nginx/sites-enabled/{$primaryDomain}";

        $this->sshService->connect($app->server);

        // Upload config
        $this->sshService->uploadContent($config, $configPath);

        // Create symlink
        $this->sshService->execute("ln -sf {$configPath} {$enabledPath}");

        // Test nginx config
        $result = $this->sshService->execute('nginx -t 2>&1');
        if (!$result['success']) {
            throw new RuntimeException("Nginx config test failed: {$result['output']}");
        }

        // Reload nginx
        $result = $this->sshService->execute('systemctl reload nginx');

        $this->sshService->disconnect();

        return $result['success'];
    }

    public function remove(Application $app): bool
    {
        $primaryDomain = $app->primaryDomain()?->domain ?? $app->domain;
        $configPath = "/etc/nginx/sites-available/{$primaryDomain}";
        $enabledPath = "/etc/nginx/sites-enabled/{$primaryDomain}";

        $this->sshService->connect($app->server);

        $this->sshService->execute("rm -f {$enabledPath}");
        $this->sshService->execute("rm -f {$configPath}");

        $result = $this->sshService->execute('systemctl reload nginx');

        $this->sshService->disconnect();

        return $result['success'];
    }

    /**
     * Get the current nginx configuration content from the server.
     */
    public function getConfigContent(Application $app): string
    {
        $primaryDomain = $app->primaryDomain()?->domain ?? $app->domain;
        $configPath = "/etc/nginx/sites-available/{$primaryDomain}";

        $this->sshService->connect($app->server);

        $result = $this->sshService->execute("cat {$configPath} 2>/dev/null");

        $this->sshService->disconnect();

        if (!$result['success'] || empty($result['output'])) {
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
        $primaryDomain = $app->primaryDomain()?->domain ?? $app->domain;
        $configPath = "/etc/nginx/sites-available/{$primaryDomain}";
        $enabledPath = "/etc/nginx/sites-enabled/{$primaryDomain}";

        $this->sshService->connect($app->server);

        // Upload the new config
        $this->sshService->uploadContent($content, $configPath);

        // Ensure symlink exists
        $this->sshService->execute("ln -sf {$configPath} {$enabledPath}");

        // Test nginx config
        $result = $this->sshService->execute('nginx -t 2>&1');
        if (!$result['success']) {
            // Restore the generated config if test fails
            $generatedConfig = $this->generateConfig($app);
            $this->sshService->uploadContent($generatedConfig, $configPath);
            $this->sshService->disconnect();
            throw new RuntimeException("Nginx config test failed: {$result['output']}");
        }

        // Reload nginx
        $result = $this->sshService->execute('systemctl reload nginx');

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
     * Check if any domain has SSL enabled.
     */
    private function hasAnySslEnabled(Application $app): bool
    {
        return $app->domains()->where('ssl_enabled', true)->exists()
            || $app->ssl_enabled;
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

    private function laravelTemplate(Application $app): string
    {
        $serverName = $this->getServerNames($app);
        $root = $app->getDocumentRoot();
        $hasSsl = $this->hasAnySslEnabled($app);

        if ($hasSsl) {
            $sslDomains = $this->getSslDomains($app);
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
    location /.well-known/acme-challenge/ {
        allow all;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, add a separate port 80 block that serves them
            if (!empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        allow all;
    }

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
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;

                // Redirect block for SSL domains only
                $sslDomainNames = implode(' ', array_map(fn($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        allow all;
    }

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
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
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
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
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
        $hasSsl = $this->hasAnySslEnabled($app);

        if ($hasSsl) {
            $sslDomains = $this->getSslDomains($app);
            $nonSslDomains = $this->getNonSslDomainNames($app);

            $blocks = [];

            // Port 80: redirect all to HTTPS
            $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$serverName};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
        allow all;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, serve them on port 80 instead of redirecting
            if (!empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
        allow all;
    }

    location / {
        proxy_pass http://localhost:3000;
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

                $sslDomainNames = implode(' ', array_map(fn($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        root /var/www/html;
        allow all;
    }

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
        proxy_pass http://localhost:3000;
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

    location / {
        proxy_pass http://localhost:3000;
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
        $hasSsl = $this->hasAnySslEnabled($app);

        if ($hasSsl) {
            $sslDomains = $this->getSslDomains($app);
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
    location /.well-known/acme-challenge/ {
        allow all;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}
NGINX;

            // If there are non-SSL domains, serve them on port 80 instead of redirecting
            if (!empty($nonSslDomains)) {
                $nonSslServerName = implode(' ', $nonSslDomains);
                $blocks[0] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$nonSslServerName};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        allow all;
    }

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

                $sslDomainNames = implode(' ', array_map(fn($d) => $d->domain, $sslDomains));
                $blocks[] = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$sslDomainNames};
    root {$root};

    # Allow Let's Encrypt ACME challenge
    location /.well-known/acme-challenge/ {
        allow all;
    }

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
