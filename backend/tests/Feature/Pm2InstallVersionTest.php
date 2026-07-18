<?php

namespace Tests\Feature;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\DatabaseInstallationService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * pm2's first invocation prints its ASCII banner and daemon spawn messages
 * to stdout before the version number. Storing that raw output in
 * version_installed overflowed the column (SQLSTATE 22001) and marked an
 * otherwise successful install as failed.
 */
class Pm2InstallVersionTest extends TestCase
{
    use RefreshDatabase;

    private const PM2_FIRST_RUN_OUTPUT = <<<'OUT'
-------------

__/\\\\\\\\\\\\\____/\\\\____________/\\\\____/\\\\\\\\\_____
        _\///______________\///______________\///__\///////////////__

                          Runtime Edition

        PM2 is a Production Process Manager for Node.js applications
                     with a built-in Load Balancer.

                        -------------

[PM2] Spawning PM2 daemon with pm2_home=/home/ubuntu/.pm2
[PM2] PM2 Successfully daemonized
7.0.3
OUT;

    public function test_first_run_banner_does_not_break_version_detection(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                if (str_contains($command, 'os-release')) {
                    return ['output' => 'ID=ubuntu', 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, 'command -v nvm')) {
                    return ['output' => 'nvm', 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, 'node --version')) {
                    return ['output' => 'v24.18.0', 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, 'pm2 --version')) {
                    return ['output' => self::PM2_FIRST_RUN_OUTPUT, 'exit_code' => 0, 'success' => true];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });

        $installation = DatabaseInstallation::factory()->create([
            'server_id' => Server::factory()->create()->id,
            'engine' => 'pm2',
            'status' => 'pending',
        ]);

        app(DatabaseInstallationService::class)->install($installation);

        $installation->refresh();
        $this->assertSame('success', $installation->status);
        $this->assertSame('pm2 v7.0.3', $installation->version_installed);
    }
}
