<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\NginxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NginxTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function makeApp(array $appAttributes = [], array $serverAttributes = [], bool $sslDomain = false, string $domain = 'example.test'): Application
    {
        $server = Server::factory()->create($serverAttributes);

        $app = Application::factory()->create(array_merge(
            ['server_id' => $server->id],
            $appAttributes,
        ));

        $domainFactory = Domain::factory()->primary();
        if ($sslDomain) {
            $domainFactory = $domainFactory->withSsl();
        }
        $domainFactory->create([
            'application_id' => $app->id,
            'domain' => $domain,
        ]);

        return $app->fresh();
    }

    // NGINX-3: Node.js apps must not be hardcoded to port 3000.

    public function test_nodejs_proxy_uses_the_configured_port(): void
    {
        $app = $this->makeApp(['type' => 'nodejs', 'port' => 3100]);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('proxy_pass http://localhost:3100;', $config);
        $this->assertStringNotContainsString('localhost:3000', $config);
    }

    public function test_nodejs_proxy_defaults_to_port_3000(): void
    {
        $app = $this->makeApp(['type' => 'nodejs']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('proxy_pass http://localhost:3000;', $config);
    }

    public function test_nodejs_ssl_config_uses_the_configured_port(): void
    {
        $app = $this->makeApp(['type' => 'nodejs', 'port' => 4200], sslDomain: true);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('proxy_pass http://localhost:4200;', $config);
        $this->assertStringNotContainsString('localhost:3000', $config);
    }

    // NGINX-5: the PHP-FPM socket must not be pinned to php8.3.

    public function test_laravel_socket_uses_the_app_php_version(): void
    {
        $app = $this->makeApp(['type' => 'laravel', 'php_version' => '8.2']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('unix:/var/run/php/php8.2-fpm.sock', $config);
        $this->assertStringNotContainsString('php8.3-fpm.sock', $config);
    }

    public function test_laravel_socket_falls_back_to_the_server_php_version(): void
    {
        $app = $this->makeApp(['type' => 'laravel'], ['php_version' => '8.4']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('unix:/var/run/php/php8.4-fpm.sock', $config);
        $this->assertStringNotContainsString('php8.3-fpm.sock', $config);
    }

    public function test_laravel_socket_defaults_to_php_8_3(): void
    {
        $app = $this->makeApp(['type' => 'laravel']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('unix:/var/run/php/php8.3-fpm.sock', $config);
    }

    public function test_laravel_ssl_config_uses_the_app_php_version_in_every_block(): void
    {
        $app = $this->makeApp(['type' => 'laravel', 'php_version' => '8.2'], sslDomain: true);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('unix:/var/run/php/php8.2-fpm.sock', $config);
        $this->assertStringNotContainsString('php8.3-fpm.sock', $config);
    }

    // NGINX-2: the legacy app-level ssl_enabled flag with no SSL-enabled
    // Domain rows must not produce an invalid config (empty server_name,
    // no 443 block).

    public function test_legacy_ssl_flag_without_ssl_domains_generates_a_plain_http_config(): void
    {
        foreach (['laravel', 'nodejs', 'static'] as $type) {
            $app = $this->makeApp(['type' => $type, 'ssl_enabled' => true], domain: "legacy-{$type}.test");

            $config = app(NginxService::class)->generateConfig($app);

            $this->assertStringNotContainsString(
                'server_name ;',
                $config,
                "The {$type} template emits an empty server_name when only the legacy ssl flag is set."
            );
            $this->assertStringNotContainsString(
                'listen 443',
                $config,
                "The {$type} template must not emit SSL blocks without an SSL-enabled domain (no certificate exists)."
            );
        }
    }

    public function test_legacy_ssl_flag_with_no_domain_rows_does_not_emit_empty_server_names(): void
    {
        $app = Application::factory()->create([
            'type' => 'laravel',
            'ssl_enabled' => true,
            'domain' => 'bare.test',
        ]);

        $config = app(NginxService::class)->generateConfig($app->fresh());

        $this->assertStringNotContainsString('server_name ;', $config);
        $this->assertStringContainsString('server_name bare.test;', $config);
    }

    // SSL-2: every template must serve ACME challenges from one canonical
    // webroot so certbot's webroot authenticator works for all app types.

    public function test_all_templates_serve_acme_challenges_from_the_canonical_webroot(): void
    {
        foreach (['laravel', 'nodejs', 'static'] as $type) {
            foreach ([false, true] as $ssl) {
                $label = $type.($ssl ? '-ssl' : '-plain');
                $app = $this->makeApp(['type' => $type], sslDomain: $ssl, domain: "{$label}.test");

                $config = app(NginxService::class)->generateConfig($app);

                $this->assertStringContainsString(
                    'location ^~ /.well-known/acme-challenge/',
                    $config,
                    "The {$label} template has no ACME challenge location; certbot webroot issuance will 404."
                );
                $this->assertStringContainsString(
                    'root /var/www/letsencrypt;',
                    $config,
                    "The {$label} template does not serve ACME challenges from the canonical webroot."
                );
            }
        }
    }
}
