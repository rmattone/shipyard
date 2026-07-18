<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Services\CertbotService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertbotWebrootTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    /**
     * SSL-2: for Node.js apps the document root is proxied to the Node
     * process, so certbot must use the canonical ACME webroot instead of
     * the app's document root.
     */
    public function test_certificate_issuance_uses_the_canonical_acme_webroot(): void
    {
        $this->mockSsh();

        $app = Application::factory()->create([
            'type' => 'nodejs',
            'deployment_strategy' => 'atomic',
        ]);
        $domain = Domain::factory()->primary()->create([
            'application_id' => $app->id,
            'domain' => 'node.example.test',
        ]);

        app(CertbotService::class)->obtainCertificateForDomain($domain, 'admin@example.test');

        $certbot = null;
        foreach ($this->executedCommands as $command) {
            if (str_contains($command, 'certbot certonly')) {
                $certbot = $command;
                break;
            }
        }

        $this->assertNotNull($certbot, 'Expected a certbot certonly command to run.');
        $this->assertStringContainsString('-w /var/www/letsencrypt', $certbot);
        $this->assertStringNotContainsString($app->getDocumentRoot(), $certbot);

        $mkdir = array_filter(
            $this->executedCommands,
            fn ($c) => str_contains($c, 'mkdir -p /var/www/letsencrypt')
        );
        $this->assertNotEmpty($mkdir, 'The canonical ACME webroot must be created before running certbot.');
    }

    /**
     * NGINX-2: the legacy app-level issuance path must synthesize a primary
     * Domain row; templates only emit SSL blocks for SSL-enabled Domain rows,
     * so without one the certificate would never be served.
     */
    public function test_legacy_app_level_issuance_creates_the_missing_primary_domain_row(): void
    {
        $this->mockSsh();

        $app = Application::factory()->create([
            'type' => 'laravel',
            'domain' => 'legacy.test',
            'ssl_enabled' => false,
        ]);

        app(CertbotService::class)->obtainCertificate($app, 'admin@example.test');

        $this->assertDatabaseHas('domains', [
            'application_id' => $app->id,
            'domain' => 'legacy.test',
            'is_primary' => true,
            'ssl_enabled' => true,
        ]);
    }

    /**
     * SSL-3: if the nginx deploy after issuance fails, the domain must not
     * be shown as SSL-active while HTTPS is dead.
     */
    public function test_failed_nginx_deploy_does_not_leave_the_domain_marked_ssl_active(): void
    {
        $this->mockSsh();
        $this->fakeResults['nginx -t'] = ['output' => 'nginx: test failed', 'exit_code' => 1, 'success' => false];

        $app = Application::factory()->create(['type' => 'laravel']);
        $domain = Domain::factory()->primary()->create([
            'application_id' => $app->id,
            'domain' => 'ssl3.example.test',
            'ssl_enabled' => false,
        ]);

        try {
            app(CertbotService::class)->obtainCertificateForDomain($domain, 'admin@example.test');
            $this->fail('Expected issuance to throw when the nginx deploy fails.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(
            $domain->fresh()->ssl_enabled,
            'The domain must not report SSL active while nginx is not serving the certificate.'
        );
    }
}
