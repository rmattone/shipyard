<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * user and directory are the only values that reach the systemd unit file
 * (the command goes into a wrapper script, where a single line is harmless),
 * so their validation is the unit-file injection defense: no whitespace,
 * quotes, %, backslashes, or traversal may pass.
 */
class DaemonShellSafetyTest extends TestCase
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

    private function postDaemon(array $overrides): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            "/api/servers/{$this->server->id}/daemons",
            array_merge([
                'command' => 'php artisan queue:work',
                'user' => 'www-data',
                'directory' => '/var/www/app/current',
            ], $overrides)
        );
    }

    public function test_commands_with_line_breaks_or_nul_are_rejected(): void
    {
        foreach (["a\nb", "a\r\nb", "a\0b"] as $command) {
            $this->postDaemon(['command' => $command])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['command']);
        }

        Queue::assertNothingPushed();
    }

    public function test_shell_metacharacters_in_commands_are_allowed(): void
    {
        $this->postDaemon(['command' => 'php artisan horizon || php artisan queue:work "redis" --queue=high,default'])
            ->assertStatus(202);
    }

    public function test_malformed_users_are_rejected(): void
    {
        foreach (['root; rm -rf /', "www-data\nevil", '../etc', 'WWW-DATA', '1user', "user'"] as $user) {
            $this->postDaemon(['user' => $user])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['user']);
        }

        Queue::assertNothingPushed();
    }

    public function test_hostile_directories_are_rejected(): void
    {
        $directories = [
            'relative/path',
            '/var/www/../etc',
            '/var/w w',
            '/var/%i',
            "/var/'x'",
            "/var/www\nExecStart=/bin/evil",
            '/var/www"',
            '/var/www\\evil',
        ];

        foreach ($directories as $directory) {
            $this->postDaemon(['directory' => $directory])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['directory']);
        }

        Queue::assertNothingPushed();
    }

    public function test_realistic_directories_are_accepted(): void
    {
        foreach (['/var/www/app/current', '/home/deploy/my-app_v2', '/srv/app.example.com'] as $directory) {
            $this->postDaemon(['directory' => $directory])->assertStatus(202);
        }
    }

    public function test_process_counts_are_bounded(): void
    {
        foreach ([0, 11, -1] as $processes) {
            $this->postDaemon(['processes' => $processes])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['processes']);
        }

        $this->postDaemon(['processes' => 10])->assertStatus(202);
    }
}
