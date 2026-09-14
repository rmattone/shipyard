<?php

namespace Tests\Feature;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\DatabaseInstallationService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * "systemctl is-active nginx" reports active on a host where apache2 already
 * owns port 80, because nginx keeps running from an earlier start even though
 * the current unit lost the bind. That server then serves fine until the next
 * reboot, when apache2 wins the race and every site goes down. The install has
 * to check who actually holds the socket.
 */
class NginxPortOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function mockSsh(string $portEightyListeners): void
    {
        $this->mock(SSHService::class, function ($mock) use ($portEightyListeners) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($portEightyListeners) {
                $fakes = [
                    'cat /etc/os-release' => 'ID=ubuntu VERSION_CODENAME=noble',
                    'ss -ltnp' => $portEightyListeners,
                    'is-active' => 'active',
                    'nginx -v' => 'nginx version: nginx/1.24.0',
                ];

                foreach ($fakes as $needle => $output) {
                    if (str_contains($command, $needle)) {
                        return ['output' => $output, 'exit_code' => 0, 'success' => true];
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function runNginxInstall(): DatabaseInstallation
    {
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => Server::factory()->create()->id,
            'engine' => 'nginx',
            'status' => 'pending',
        ]);

        try {
            app(DatabaseInstallationService::class)->install($installation);
        } catch (RuntimeException) {
            // the service marks the row failed and rethrows
        }

        return $installation->refresh();
    }

    public function test_it_succeeds_when_nginx_holds_port_eighty(): void
    {
        $this->mockSsh('LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=812,fd=6))');

        $installation = $this->runNginxInstall();

        $this->assertSame('success', $installation->status);
        $this->assertStringContainsString('nginx owns port 80', $installation->log);
    }

    public function test_it_fails_when_apache_holds_port_eighty(): void
    {
        $this->mockSsh('LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("apache2",pid=744,fd=4))');

        $installation = $this->runNginxInstall();

        $this->assertSame('failed', $installation->status);
        $this->assertStringContainsString('apache2 is listening on port 80', $installation->log);
    }

    public function test_it_fails_when_an_unrelated_process_holds_port_eighty(): void
    {
        $this->mockSsh('LISTEN 0 4096 0.0.0.0:80 0.0.0.0:* users:(("caddy",pid=901,fd=8))');

        $installation = $this->runNginxInstall();

        $this->assertSame('failed', $installation->status);
        $this->assertStringContainsString('Port 80 is held by another process', $installation->log);
    }

    public function test_it_fails_when_nothing_is_listening_on_port_eighty(): void
    {
        $this->mockSsh('');

        $installation = $this->runNginxInstall();

        $this->assertSame('failed', $installation->status);
        $this->assertStringContainsString('Nothing is listening on port 80', $installation->log);
    }
}
