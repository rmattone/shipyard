<?php

namespace Tests\Feature;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\DatabaseInstallationService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ondrej/php PPA lags new Ubuntu releases (404 on its Release file for
 * e.g. "resolute"), which used to kill the whole PHP install. The installer
 * must probe the PPA for the running series and fall back to the distro's
 * own PHP packages when it is not published.
 */
class PhpInstallPpaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    private function mockSsh(bool $ppaAvailable): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) use ($ppaAvailable) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($ppaAvailable) {
                $this->executedCommands[] = $command;

                $fakes = [
                    'ppa.launchpadcontent.net' => $ppaAvailable ? 'available' : 'unavailable',
                    'cat /etc/os-release' => 'ID=ubuntu VERSION_CODENAME=resolute',
                    'PHP_MAJOR_VERSION' => '8.4',
                    'is-active' => 'active',
                    'php --version' => 'PHP 8.4.2 (cli)',
                    'composer --version' => 'Composer version 2.8.4',
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

    private function runPhpInstall(): DatabaseInstallation
    {
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => Server::factory()->create(['php_version' => null])->id,
            'engine' => 'php',
            'status' => 'pending',
        ]);

        app(DatabaseInstallationService::class)->install($installation);

        return $installation->refresh();
    }

    public function test_php_installs_from_distro_packages_when_the_ppa_is_unavailable(): void
    {
        $this->mockSsh(ppaAvailable: false);

        $installation = $this->runPhpInstall();

        $this->assertSame('success', $installation->status);
        $addRepo = array_filter($this->executedCommands, fn ($c) => str_contains($c, 'add-apt-repository'));
        $this->assertSame([], array_values($addRepo), 'The PPA must not be added when it has no Release for the series.');
        $this->assertStringContainsString('distro PHP packages', $installation->log);
        $this->assertSame('8.4', $installation->server->fresh()->php_version);
    }

    public function test_php_uses_the_ppa_when_it_is_available(): void
    {
        $this->mockSsh(ppaAvailable: true);

        $installation = $this->runPhpInstall();

        $this->assertSame('success', $installation->status);
        $addRepo = array_filter($this->executedCommands, fn ($c) => str_contains($c, 'add-apt-repository -y ppa:ondrej/php'));
        $this->assertNotEmpty($addRepo, 'The PPA must be added when its Release file exists for the series.');
    }
}
