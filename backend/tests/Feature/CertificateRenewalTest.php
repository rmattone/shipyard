<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateRenewalTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                if (str_contains($command, 'test -f /etc/letsencrypt')) {
                    return ['output' => 'exists', 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, '-noout -dates')) {
                    return ['output' => "notBefore=Jul 15 12:00:00 2026 GMT\nnotAfter=Oct 13 12:00:00 2026 GMT\n", 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, '-noout -issuer')) {
                    return ['output' => "issuer=C = US, O = Let's Encrypt, CN = R3\n", 'exit_code' => 0, 'success' => true];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    public function test_renewal_command_renews_per_server_and_refreshes_expiry(): void
    {
        $this->mockSsh();

        $app = Application::factory()->create();
        $domain = Domain::factory()->create([
            'application_id' => $app->id,
            'domain' => 'renew-me.test',
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addDays(5),
        ]);

        $this->artisan('certificates:renew')->assertSuccessful();

        $renewCommands = array_filter($this->executedCommands, fn ($c) => str_contains($c, 'certbot renew'));
        $this->assertCount(1, $renewCommands, 'certbot renew must run once per server.');
        $this->assertStringContainsString(
            "--deploy-hook 'systemctl reload nginx'",
            reset($renewCommands),
            'Renewal must reload nginx so renewed certificates are actually served.'
        );

        $domain->refresh();
        $this->assertSame('2026-10-13', $domain->ssl_expires_at->format('Y-m-d'), 'Stored expiry must be refreshed from the certificate.');
        $this->assertSame("Let's Encrypt", $domain->ssl_issuer);
    }

    public function test_renewal_command_is_a_noop_without_ssl_domains(): void
    {
        $this->mockSsh();

        Application::factory()->create();

        $this->artisan('certificates:renew')->assertSuccessful();

        $this->assertSame([], $this->executedCommands, 'No SSH activity expected when nothing has SSL enabled.');
    }
}
