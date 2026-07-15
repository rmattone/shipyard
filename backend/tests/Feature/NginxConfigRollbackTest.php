<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Services\NginxService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NginxConfigRollbackTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<int, array{content: string, path: string}> */
    private array $uploads = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

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

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeApp(): Application
    {
        $app = Application::factory()->create(['type' => 'laravel']);
        Domain::factory()->primary()->create([
            'application_id' => $app->id,
            'domain' => 'example.test',
        ]);

        return $app;
    }

    public function test_failed_config_test_restores_the_previous_config(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        $previousConfig = 'server { listen 80; server_name example.test; } # previous working config';
        $this->fakeResults['cat /etc/nginx/sites-available/example.test'] = [
            'output' => $previousConfig, 'exit_code' => 0, 'success' => true,
        ];
        $this->fakeResults['nginx -t'] = [
            'output' => 'nginx: configuration file test failed', 'exit_code' => 1, 'success' => false,
        ];

        try {
            app(NginxService::class)->deploy($app);
            $this->fail('Expected deploy to throw on failed nginx -t.');
        } catch (\RuntimeException) {
            // expected
        }

        $lastUpload = end($this->uploads);
        $this->assertSame('/etc/nginx/sites-available/example.test', $lastUpload['path']);
        $this->assertSame($previousConfig, $lastUpload['content'], 'The previous working config must be restored after a failed test.');
    }

    public function test_failed_config_test_removes_a_config_that_had_no_predecessor(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        // No existing config on the server
        $this->fakeResults['cat /etc/nginx/sites-available/example.test'] = [
            'output' => '', 'exit_code' => 1, 'success' => false,
        ];
        $this->fakeResults['nginx -t'] = [
            'output' => 'nginx: configuration file test failed', 'exit_code' => 1, 'success' => false,
        ];

        try {
            app(NginxService::class)->deploy($app);
            $this->fail('Expected deploy to throw on failed nginx -t.');
        } catch (\RuntimeException) {
            // expected
        }

        $removals = array_filter($this->executedCommands, fn ($c) => str_starts_with($c, 'rm -f'));
        $this->assertNotEmpty($removals, 'A brand-new broken config must be removed, not left enabled.');
        $this->assertStringContainsString('sites-enabled/example.test', implode(' ', $removals));
    }

    public function test_successful_deploy_reloads_nginx(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        $this->assertTrue(app(NginxService::class)->deploy($app));
        $this->assertContains('systemctl reload nginx', $this->executedCommands);
    }
}
