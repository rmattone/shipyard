<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The command, user, and cron fields all end up inside a root-executed
 * crontab sync script. A newline in any of them would let a request write
 * arbitrary cron lines (or terminate the managed block), so validation
 * must reject anything that can break out of a single crontab entry.
 */
class ScheduledTaskShellSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = $this->createOrgUser();
        $this->server = Server::factory()->create();
    }

    private function postTask(array $overrides): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            "/api/servers/{$this->server->id}/scheduled-tasks",
            array_merge([
                'command' => 'echo ok',
                'user' => 'www-data',
                'frequency' => 'minutely',
            ], $overrides)
        );
    }

    public function test_commands_with_line_breaks_or_nul_are_rejected(): void
    {
        $payloads = [
            "echo a\n* * * * * evil",
            "echo a\r\n@reboot evil",
            "echo a\0whatever",
            "echo a\n# END SHIPYARD MANAGED TASKS",
        ];

        foreach ($payloads as $command) {
            $this->postTask(['command' => $command])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['command']);
        }

        Queue::assertNothingPushed();
    }

    public function test_shell_metacharacters_in_commands_are_allowed(): void
    {
        // Commands legitimately contain quotes, pipes, &&, % etc. — they are
        // one cron entry's payload, not something we interpolate unquoted.
        $this->postTask(['command' => 'php artisan backup:run && curl "https://ping.example.com?x=1" | logger'])
            ->assertStatus(202);
    }

    public function test_malicious_or_malformed_users_are_rejected(): void
    {
        $users = [
            'root; rm -rf /',
            "www-data\nevil",
            '../etc',
            'WWW-DATA',
            '1user',
            'user name',
            "user'",
        ];

        foreach ($users as $user) {
            $this->postTask(['user' => $user])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user']);
        }

        Queue::assertNothingPushed();
    }

    public function test_realistic_unix_users_are_accepted(): void
    {
        foreach (['www-data', 'deploy_user', '_apt', 'root'] as $user) {
            $this->postTask(['user' => $user])->assertStatus(202);
        }
    }

    public function test_cron_fields_reject_shell_and_whitespace_characters(): void
    {
        $bad = ['* *', '5;reboot', '$(id)', '`id`', '@reboot', "1\n2"];

        foreach ($bad as $minute) {
            $this->postTask([
                'frequency' => 'custom',
                'minute' => $minute,
                'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['minute']);
        }

        Queue::assertNothingPushed();
    }

    public function test_valid_cron_field_syntax_is_accepted(): void
    {
        foreach (['*/5', '1,15,30', '0-30', '*'] as $minute) {
            $this->postTask([
                'frequency' => 'custom',
                'minute' => $minute,
                'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
            ])->assertStatus(202);
        }
    }
}
