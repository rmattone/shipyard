<?php

namespace Tests\Feature;

use App\Models\ScheduledTask;
use App\Models\Server;
use App\Services\CrontabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The crontab sync strategy: every write regenerates a marker-delimited
 * managed block from the DB inside the target user's crontab, preserving
 * whatever the user keeps outside the block. These tests assert the exact
 * script shipped over SSH, since a malformed crontab silently kills every
 * scheduled task on the box.
 */
class CrontabServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    /** @var string[] */
    private array $executedCommands = [];

    private function mockSsh(bool $scriptSucceeds = true, string $scriptOutput = ''): void
    {
        $this->uploadedScripts = [];
        $this->executedCommands = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($scriptSucceeds, $scriptOutput) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptSucceeds, $scriptOutput) {
                $this->executedCommands[] = $command;

                if (str_starts_with($command, 'bash ')) {
                    return [
                        'output' => $scriptOutput,
                        'exit_code' => $scriptSucceeds ? 0 : 1,
                        'success' => $scriptSucceeds,
                    ];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function service(): CrontabService
    {
        return app(CrontabService::class);
    }

    public function test_render_cron_line_for_preset_frequency(): void
    {
        $task = ScheduledTask::factory()->create([
            'command' => 'php8.3 /var/www/app/current/artisan schedule:run',
            'frequency' => 'minutely',
        ]);

        $this->assertSame(
            "* * * * * php8.3 /var/www/app/current/artisan schedule:run >> /var/log/shipyard/task-{$task->id}.log 2>&1",
            $this->service()->renderCronLine($task)
        );
    }

    public function test_render_cron_line_escapes_percent_signs(): void
    {
        $task = ScheduledTask::factory()->create([
            'command' => 'date +%Y-%m-%d',
            'frequency' => 'hourly',
        ]);

        $line = $this->service()->renderCronLine($task);

        $this->assertStringContainsString('date +\%Y-\%m-\%d', $line);
        $this->assertStringStartsWith('0 * * * * ', $line);
    }

    public function test_render_cron_line_for_custom_and_reboot_frequencies(): void
    {
        $custom = ScheduledTask::factory()->custom()->create(['command' => 'echo hi']);
        $reboot = ScheduledTask::factory()->reboot()->create(['command' => 'echo boot']);

        $this->assertStringStartsWith('*/5 * * * * echo hi', $this->service()->renderCronLine($custom));
        $this->assertStringStartsWith('@reboot echo boot', $this->service()->renderCronLine($reboot));
    }

    public function test_managed_block_contains_only_active_tasks_for_the_same_server_and_user(): void
    {
        $server = Server::factory()->create();
        $included = ScheduledTask::factory()->create(['server_id' => $server->id, 'user' => 'www-data', 'command' => 'echo included']);
        $installing = ScheduledTask::factory()->installing()->create(['server_id' => $server->id, 'user' => 'www-data', 'command' => 'echo installing']);
        ScheduledTask::factory()->create(['server_id' => $server->id, 'user' => 'www-data', 'status' => 'removing', 'command' => 'echo removing']);
        ScheduledTask::factory()->failed()->create(['server_id' => $server->id, 'user' => 'www-data', 'command' => 'echo failed']);
        ScheduledTask::factory()->create(['server_id' => $server->id, 'user' => 'root', 'command' => 'echo other-user']);
        ScheduledTask::factory()->create(['command' => 'echo other-server']);

        $block = $this->service()->buildManagedBlock($server, 'www-data');

        $this->assertStringStartsWith('# BEGIN SHIPYARD MANAGED TASKS - DO NOT EDIT', $block);
        $this->assertStringEndsWith('# END SHIPYARD MANAGED TASKS', $block);
        $this->assertStringContainsString('echo included', $block);
        $this->assertStringContainsString('echo installing', $block);
        $this->assertStringNotContainsString('echo removing', $block);
        $this->assertStringNotContainsString('echo failed', $block);
        $this->assertStringNotContainsString('echo other-user', $block);
        $this->assertStringNotContainsString('echo other-server', $block);
        $this->assertStringContainsString("# ShipYard task {$included->id}", $block);
    }

    public function test_install_task_ships_a_crontab_sync_script_with_log_setup(): void
    {
        $this->mockSsh();
        $task = ScheduledTask::factory()->installing()->create(['user' => 'www-data']);

        $this->service()->installTask($task);

        $this->assertCount(1, $this->uploadedScripts);
        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString("crontab -l -u 'www-data' 2>/dev/null || true", $script);
        $this->assertStringContainsString("sed '/^# BEGIN SHIPYARD MANAGED TASKS - DO NOT EDIT$/,/^# END SHIPYARD MANAGED TASKS$/d'", $script);
        $this->assertStringContainsString("crontab -u 'www-data' -", $script);
        $this->assertStringContainsString($this->service()->renderCronLine($task), $script);

        $this->assertStringContainsString('install -d -m 0755 /var/log/shipyard', $script);
        $this->assertStringContainsString("touch '/var/log/shipyard/task-{$task->id}.log'", $script);
        $this->assertStringContainsString("chown 'www-data' '/var/log/shipyard/task-{$task->id}.log'", $script);
        $this->assertStringContainsString("chmod 0640 '/var/log/shipyard/task-{$task->id}.log'", $script);
    }

    public function test_install_task_uses_a_randomized_heredoc_delimiter(): void
    {
        $this->mockSsh();
        $task = ScheduledTask::factory()->installing()->create();

        $this->service()->installTask($task);
        $first = $this->uploadedScripts[0];

        $this->service()->installTask($task);
        $second = $this->uploadedScripts[1];

        preg_match('/SHIPYARD_EOF_(\w+)/', $first, $a);
        preg_match('/SHIPYARD_EOF_(\w+)/', $second, $b);

        $this->assertNotEmpty($a[1] ?? '');
        $this->assertNotEmpty($b[1] ?? '');
        $this->assertNotSame($a[1], $b[1]);
    }

    public function test_remove_task_excludes_the_task_and_deletes_its_log_file(): void
    {
        $this->mockSsh();
        $server = Server::factory()->create();
        $keep = ScheduledTask::factory()->create(['server_id' => $server->id, 'user' => 'www-data', 'command' => 'echo keep']);
        $remove = ScheduledTask::factory()->create(['server_id' => $server->id, 'user' => 'www-data', 'command' => 'echo remove', 'status' => 'removing']);

        $this->service()->removeTask($remove);

        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString('echo keep', $script);
        $this->assertStringNotContainsString('echo remove', $script);
        $this->assertStringContainsString("rm -f '/var/log/shipyard/task-{$remove->id}.log'", $script);
    }

    public function test_failed_sync_script_throws_with_the_script_output(): void
    {
        $this->mockSsh(scriptSucceeds: false, scriptOutput: 'no crontab binary');
        $task = ScheduledTask::factory()->installing()->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no crontab binary');

        $this->service()->installTask($task);
    }

    public function test_read_task_output_tails_the_log_file(): void
    {
        $task = ScheduledTask::factory()->create();

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($task) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($task) {
                $this->assertStringContainsString("tail -n 50 '/var/log/shipyard/task-{$task->id}.log'", $command);

                return ['output' => "line1\nline2", 'exit_code' => 0, 'success' => true];
            });
        });

        $result = $this->service()->readTaskOutput($task, 50);

        $this->assertTrue($result['exists']);
        $this->assertSame("line1\nline2", $result['output']);
    }

    public function test_read_task_output_reports_missing_log_file(): void
    {
        $task = ScheduledTask::factory()->create();

        $this->mock(\App\Services\SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturn([
                'output' => "__SHIPYARD_NO_LOG__\n",
                'exit_code' => 0,
                'success' => true,
            ]);
        });

        $result = $this->service()->readTaskOutput($task);

        $this->assertFalse($result['exists']);
        $this->assertSame('', $result['output']);
    }
}
