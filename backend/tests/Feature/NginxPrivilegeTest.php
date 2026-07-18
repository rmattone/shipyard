<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\Server;
use App\Services\CertbotService;
use App\Services\NginxService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NGINX-6: nginx and certbot operations write root-owned paths and reload
 * services, so they must work for root SSH users (no sudo, which may not
 * even be installed) and for non-root users with passwordless sudo.
 */
class NginxPrivilegeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<int, array{content: string, path: string}> */
    private array $uploads = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];
        $this->uploads = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploads[] = ['content' => $content, 'path' => $path];

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeApp(string $username): Application
    {
        $server = Server::factory()->create(['username' => $username]);
        $app = Application::factory()->create(['type' => 'laravel', 'server_id' => $server->id]);
        Domain::factory()->primary()->create([
            'application_id' => $app->id,
            'domain' => 'example.test',
        ]);

        return $app->fresh();
    }

    public function test_deploy_prefixes_privileged_commands_with_sudo_for_non_root_users(): void
    {
        $this->mockSsh();
        $app = $this->makeApp('deploy');

        app(NginxService::class)->deploy($app);

        $joined = implode("\n", $this->executedCommands);
        $this->assertStringContainsString('sudo -n ln -sf', $joined);
        $this->assertStringContainsString('sudo -n nginx -t', $joined);
        $this->assertStringContainsString('sudo -n systemctl reload nginx', $joined);
    }

    public function test_deploy_stages_the_config_and_moves_it_into_place_as_root(): void
    {
        $this->mockSsh();
        $app = $this->makeApp('deploy');

        app(NginxService::class)->deploy($app);

        $lastUpload = end($this->uploads);
        $this->assertStringStartsWith(
            '/tmp/',
            $lastUpload['path'],
            'SFTP writes run as the SSH user and cannot create files in /etc/nginx; the config must be staged.'
        );

        $moves = array_filter(
            $this->executedCommands,
            fn ($c) => str_contains($c, 'sudo -n mv -f')
                && str_contains($c, "sites-available/shipyard-app-{$app->id}")
        );
        $this->assertNotEmpty($moves, 'The staged config must be moved into place with sudo.');
    }

    public function test_deploy_runs_without_sudo_for_root(): void
    {
        $this->mockSsh();
        $app = $this->makeApp('root');

        app(NginxService::class)->deploy($app);

        $withSudo = array_values(array_filter(
            $this->executedCommands,
            fn ($c) => str_contains($c, 'sudo')
        ));
        $this->assertSame([], $withSudo, 'Root needs no sudo, and minimal systems may not have it installed.');
    }

    public function test_certbot_issuance_uses_sudo_for_non_root_users(): void
    {
        $this->mockSsh();
        $app = $this->makeApp('deploy');
        $domain = $app->primaryDomain();

        app(CertbotService::class)->obtainCertificateForDomain($domain, 'admin@example.test');

        $joined = implode("\n", $this->executedCommands);
        $this->assertStringContainsString('sudo -n mkdir -p /var/www/letsencrypt', $joined);
        $this->assertStringContainsString('sudo -n certbot certonly', $joined);
    }
}
