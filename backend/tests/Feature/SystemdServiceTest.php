<?php

namespace Tests\Feature;

use App\Models\Daemon;
use App\Models\Server;
use App\Services\SystemdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Daemons are installed as systemd templated units. The user command is
 * deliberately kept OUT of the unit file (it lives verbatim in a root-owned
 * wrapper script), so the unit file needs no escaping and a hostile command
 * can never inject unit directives. These tests pin both file contents and
 * the exact scripts shipped over SSH.
 */
class SystemdServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    /** @var string[] */
    private array $executedCommands = [];

    private function mockSsh(bool $succeeds = true, string $output = ''): void
    {
        $this->uploadedScripts = [];
        $this->executedCommands = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($succeeds, $output) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($succeeds, $output) {
                $this->executedCommands[] = $command;

                if (str_starts_with($command, 'bash ')) {
                    return ['output' => $output, 'exit_code' => $succeeds ? 0 : 1, 'success' => $succeeds];
                }

                return ['output' => $output, 'exit_code' => $succeeds ? 0 : 1, 'success' => $succeeds];
            });
        });
    }

    private function service(): SystemdService
    {
        return app(SystemdService::class);
    }

    public function test_unit_file_never_contains_the_user_command(): void
    {
        $daemon = Daemon::factory()->create([
            'command' => 'echo "%i" \\ $HOME \'quoted\' && rm -rf /',
            'user' => 'www-data',
            'directory' => '/var/www/app/current',
        ]);

        $unit = $this->service()->renderUnitFile($daemon);

        $this->assertStringNotContainsString('rm -rf', $unit);
        $this->assertStringNotContainsString('echo', $unit);
        $this->assertStringContainsString('User=www-data', $unit);
        $this->assertStringContainsString('WorkingDirectory=/var/www/app/current', $unit);
        $this->assertStringContainsString("ExecStart=/etc/shipyard/daemons/shipyard-daemon-{$daemon->id}.sh", $unit);
        $this->assertStringContainsString('Restart=always', $unit);
        $this->assertStringContainsString('RestartSec=5', $unit);
        $this->assertStringContainsString('TimeoutStopSec=30', $unit);
        $this->assertStringContainsString('KillMode=control-group', $unit);
        $this->assertStringContainsString("SyslogIdentifier=shipyard-daemon-{$daemon->id}", $unit);
        $this->assertStringContainsString('StartLimitIntervalSec=0', $unit);
        $this->assertStringContainsString('WantedBy=multi-user.target', $unit);
    }

    public function test_wrapper_script_contains_the_command_verbatim(): void
    {
        $daemon = Daemon::factory()->create([
            'command' => 'php8.3 artisan queue:work redis --queue=default --tries=3',
        ]);

        $wrapper = $this->service()->renderWrapperScript($daemon);

        $this->assertStringStartsWith("#!/bin/bash -l\n", $wrapper);
        $this->assertStringContainsString("\nphp8.3 artisan queue:work redis --queue=default --tries=3\n", $wrapper);
    }

    public function test_rendering_rejects_hostile_user_and_directory_values(): void
    {
        $service = $this->service();

        $hostileUser = Daemon::factory()->make(['user' => 'www-data; rm -rf /']);
        $relativeDir = Daemon::factory()->make(['directory' => 'relative/path']);
        $traversalDir = Daemon::factory()->make(['directory' => '/var/www/../etc']);
        $specifierDir = Daemon::factory()->make(['directory' => '/var/%i']);

        foreach ([$hostileUser, $relativeDir, $traversalDir, $specifierDir] as $daemon) {
            try {
                $service->renderUnitFile($daemon);
                $this->fail('Expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_instance_units_reflect_the_process_count(): void
    {
        $daemon = Daemon::factory()->multiProcess(3)->create();

        $this->assertSame([
            "shipyard-daemon-{$daemon->id}@1.service",
            "shipyard-daemon-{$daemon->id}@2.service",
            "shipyard-daemon-{$daemon->id}@3.service",
        ], $daemon->instanceUnits());
    }

    public function test_install_ships_both_files_and_starts_all_instances(): void
    {
        $this->mockSsh();
        $daemon = Daemon::factory()->multiProcess(2)->installing()->create();

        $this->service()->installDaemon($daemon);

        $this->assertCount(1, $this->uploadedScripts);
        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString('install -d -m 0755 /etc/shipyard/daemons', $script);
        $this->assertStringContainsString("tee /etc/shipyard/daemons/shipyard-daemon-{$daemon->id}.sh", $script);
        $this->assertStringContainsString("chmod 0755 /etc/shipyard/daemons/shipyard-daemon-{$daemon->id}.sh", $script);
        $this->assertStringContainsString("tee /etc/systemd/system/shipyard-daemon-{$daemon->id}@.service", $script);
        $this->assertStringContainsString("chmod 0644 /etc/systemd/system/shipyard-daemon-{$daemon->id}@.service", $script);
        $this->assertStringContainsString('systemctl daemon-reload', $script);
        $this->assertStringContainsString(
            "systemctl enable --now shipyard-daemon-{$daemon->id}@1.service shipyard-daemon-{$daemon->id}@2.service",
            $script
        );

        // Two randomized heredoc delimiters, distinct from each other.
        preg_match_all('/SHIPYARD_EOF_(\w+)/', $script, $matches);
        $delimiters = array_unique($matches[1]);
        $this->assertCount(2, $delimiters);
    }

    public function test_remove_stops_and_deletes_everything_idempotently(): void
    {
        $this->mockSsh();
        $daemon = Daemon::factory()->multiProcess(2)->create(['status' => 'removing']);

        $this->service()->removeDaemon($daemon);

        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString(
            "systemctl disable --now shipyard-daemon-{$daemon->id}@1.service shipyard-daemon-{$daemon->id}@2.service",
            $script
        );
        $this->assertStringContainsString("systemctl stop 'shipyard-daemon-{$daemon->id}@*'", $script);
        $this->assertStringContainsString("rm -f /etc/systemd/system/shipyard-daemon-{$daemon->id}@.service /etc/shipyard/daemons/shipyard-daemon-{$daemon->id}.sh", $script);
        $this->assertStringContainsString('systemctl daemon-reload', $script);
        $this->assertStringContainsString("systemctl reset-failed 'shipyard-daemon-{$daemon->id}@*'", $script);
        $this->assertStringContainsString('|| true', $script);
    }

    public function test_failed_install_script_throws_with_output(): void
    {
        $this->mockSsh(succeeds: false, output: 'tee: permission denied');
        $daemon = Daemon::factory()->installing()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tee: permission denied');

        $this->service()->installDaemon($daemon);
    }

    public function test_restart_uses_sudo_for_non_root_ssh_users_and_throws_on_failure(): void
    {
        $server = Server::factory()->create(['username' => 'ubuntu']);
        $daemon = Daemon::factory()->multiProcess(2)->create(['server_id' => $server->id]);

        $this->mockSsh();
        $this->service()->restartDaemon($daemon);

        $restart = collect($this->executedCommands)->first(fn ($c) => str_contains($c, 'systemctl restart'));
        $this->assertNotNull($restart);
        $this->assertStringStartsWith('sudo -n systemctl restart ', $restart);
        $this->assertStringContainsString("shipyard-daemon-{$daemon->id}@1.service shipyard-daemon-{$daemon->id}@2.service", $restart);

        $this->mockSsh(succeeds: false, output: 'Failed to restart');
        $this->expectException(\RuntimeException::class);
        $this->service()->restartDaemon($daemon);
    }

    public function test_status_aggregates_instance_states(): void
    {
        $daemon = Daemon::factory()->multiProcess(2)->create();

        foreach ([
            ["active\nactive\n", 'running', ['1' => 'active', '2' => 'active']],
            ["active\nfailed\n", 'degraded', ['1' => 'active', '2' => 'failed']],
            ["inactive\ninactive\n", 'stopped', ['1' => 'inactive', '2' => 'inactive']],
        ] as [$output, $expectedState, $expectedInstances]) {
            $this->mockSsh(succeeds: true, output: $output);

            $status = $this->service()->getStatus($daemon);

            $this->assertSame($expectedState, $status['state']);
            $this->assertSame($expectedInstances, $status['instances']);
        }
    }

    public function test_read_logs_tails_journalctl_with_clamped_lines(): void
    {
        $daemon = Daemon::factory()->create();

        $this->mockSsh(succeeds: true, output: "2026-07-18 job processed\n");
        $result = $this->service()->readLogs($daemon, 5000);

        $journalctl = collect($this->executedCommands)->first(fn ($c) => str_contains($c, 'journalctl'));
        $this->assertNotNull($journalctl);
        $this->assertStringContainsString("journalctl -u 'shipyard-daemon-{$daemon->id}@*' -n 2000 --no-pager", $journalctl);
        $this->assertTrue($result['exists']);
        $this->assertStringContainsString('job processed', $result['output']);

        $this->mockSsh(succeeds: true, output: "-- No entries --\n");
        $result = $this->service()->readLogs($daemon);
        $this->assertFalse($result['exists']);
    }
}
