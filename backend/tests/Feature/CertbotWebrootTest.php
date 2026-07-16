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
}
