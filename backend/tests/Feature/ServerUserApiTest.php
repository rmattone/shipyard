<?php

namespace Tests\Feature;

use App\Jobs\ProcessServerSshKeyInstall;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

/**
 * Server-user management: listing login users, creating a deploy user, and
 * switching ShipYard's own connection user. These tests pin the dangerous
 * properties: a sudoers file is validated (visudo -cf) before it is ever
 * installed, the new connection user is verified reachable BEFORE the switch
 * is persisted, and a failed verification must leave server->username
 * completely unchanged in the database.
 */
class ServerUserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    /** @var string[] */
    private array $uploadedScripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $keyPair = EC::createKey('Ed25519');

        $this->server = Server::factory()->create([
            'username' => 'root',
            'private_key' => $keyPair->toString('OpenSSH'),
        ]);
    }

    private const USERS_FIXTURE = <<<'TXT'
    USER:root:0:/root:/bin/bash
    USER:deploy:1000:/home/deploy:/bin/bash
    USER:daemon:100:/usr/sbin:/usr/sbin/nologin
    USER:analytics:1001:/home/analytics:/usr/sbin/nologin
    SUDO:root:yes
    SUDO:deploy:yes
    SUDO:daemon:no
    SUDO:analytics:no
    TXT;

    /**
     * @param  array{connectionSucceeds?: bool}  $options
     */
    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true, array $options = []): void
    {
        $this->uploadedScripts = [];
        $connectionSucceeds = $options['connectionSucceeds'] ?? true;

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds, $connectionSucceeds) {
            $mock->shouldReceive('testConnection')->andReturn([
                'success' => $connectionSucceeds,
                'message' => $connectionSucceeds ? 'Connection successful' : 'Connection failed',
                'system_info' => null,
            ]);
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptOutput, $scriptSucceeds) {
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

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function test_index_requires_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/users")
            ->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $this->postJson("/api/servers/{$this->server->id}/users", [
            'username' => 'deploy',
        ])->assertUnauthorized();
    }

    public function test_switch_user_requires_authentication(): void
    {
        $this->postJson("/api/servers/{$this->server->id}/switch-user", [
            'username' => 'deploy',
        ])->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // GET /servers/{server}/users
    // ---------------------------------------------------------------

    public function test_index_lists_and_filters_users(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        $response = $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/users")
            ->assertOk();

        $users = $response->json('users') ?? $response->json();

        $names = array_column($users, 'name');
        $this->assertContains('root', $names);
        $this->assertContains('deploy', $names);
        $this->assertNotContains('daemon', $names);
        $this->assertNotContains('analytics', $names);
        $this->assertCount(2, $users);

        $byName = [];
        foreach ($users as $u) {
            $byName[$u['name']] = $u;
        }

        $this->assertSame(0, $byName['root']['uid']);
        $this->assertIsInt($byName['root']['uid']);
        $this->assertTrue($byName['root']['has_sudo']);
        $this->assertTrue($byName['root']['is_connection_user']);

        $this->assertSame(1000, $byName['deploy']['uid']);
        $this->assertIsInt($byName['deploy']['uid']);
        $this->assertTrue($byName['deploy']['has_sudo']);
        $this->assertFalse($byName['deploy']['is_connection_user']);
        $this->assertSame('/home/deploy', $byName['deploy']['home']);
        $this->assertSame('/bin/bash', $byName['deploy']['shell']);
    }

    public function test_index_maps_ssh_failures_to_500(): void
    {
        $this->mockSsh('permission denied', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/users")
            ->assertStatus(500);
    }

    // ---------------------------------------------------------------
    // POST /servers/{server}/users (createDeployUser)
    // ---------------------------------------------------------------

    public function test_store_creates_a_sudo_deploy_user_and_dispatches_key_install(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
                'sudo' => true,
            ])
            ->assertStatus(201);

        $this->assertCount(1, $this->uploadedScripts);
        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString("useradd -m -s /bin/bash 'deploy'", $script);
        $this->assertStringContainsString('visudo -cf', $script);
        $this->assertStringContainsString('deploy ALL=(ALL) NOPASSWD:ALL', $script);
        $this->assertMatchesRegularExpression('/SHIPYARD_EOF_\w+/', $script);
        $this->assertStringContainsString('install -m 0440', $script);
        $this->assertStringContainsString('/etc/sudoers.d/shipyard-deploy', $script);

        $this->assertDatabaseHas('server_ssh_keys', [
            'server_id' => $this->server->id,
            'name' => 'ShipYard',
            'username' => 'deploy',
            'status' => 'installing',
        ]);

        Queue::assertPushed(ProcessServerSshKeyInstall::class);
    }

    public function test_store_creates_a_non_sudo_deploy_user(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
                'sudo' => false,
            ])
            ->assertStatus(201);

        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString("useradd -m -s /bin/bash 'deploy'", $script);
        $this->assertStringNotContainsString('visudo', $script);
        $this->assertStringNotContainsString('sudoers.d', $script);

        $this->assertDatabaseHas('server_ssh_keys', [
            'server_id' => $this->server->id,
            'username' => 'deploy',
            'status' => 'installing',
        ]);

        Queue::assertPushed(ProcessServerSshKeyInstall::class);
    }

    public function test_store_defaults_sudo_to_true_when_omitted(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
            ])
            ->assertStatus(201);

        $this->assertStringContainsString('visudo -cf', $this->uploadedScripts[0]);
    }

    public function test_store_returns_422_when_user_already_exists(): void
    {
        Queue::fake();
        $this->mockSsh('SHIPYARD_USER_EXISTS', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
                'sudo' => true,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('server_ssh_keys', ['username' => 'deploy']);
        Queue::assertNothingPushed();
    }

    public function test_store_returns_500_when_sudoers_file_fails_validation(): void
    {
        Queue::fake();
        $this->mockSsh('SHIPYARD_SUDOERS_INVALID', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
                'sudo' => true,
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('server_ssh_keys', ['username' => 'deploy']);
        Queue::assertNothingPushed();
    }

    public function test_store_rejects_a_malformed_username_before_any_ssh(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldNotReceive('connect');
            $mock->shouldNotReceive('execute');
        });

        Queue::fake();

        foreach (['Deploy', '1deploy', 'de ploy', 'deploy; rm -rf /'] as $username) {
            $this->actingAs($this->user)
                ->postJson("/api/servers/{$this->server->id}/users", [
                    'username' => $username,
                    'sudo' => true,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['username']);
        }

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------
    // POST /servers/{server}/switch-user (switchConnectionUser)
    // ---------------------------------------------------------------

    private const SWITCH_USERS_FIXTURE = <<<'TXT'
    USER:root:0:/root:/bin/bash
    USER:deploy:1000:/home/deploy:/bin/bash
    SUDO:root:yes
    SUDO:deploy:yes
    TXT;

    public function test_switch_user_happy_path_updates_username_and_disconnects(): void
    {
        $this->mockSsh(self::SWITCH_USERS_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'deploy',
                'fix_ownership' => false,
            ])
            ->assertOk()
            ->assertJsonFragment(['username' => 'deploy']);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'deploy',
        ]);
    }

    public function test_switch_user_with_fix_ownership_chowns_application_paths(): void
    {
        $this->mockSsh(self::SWITCH_USERS_FIXTURE);

        Application::factory()->create([
            'server_id' => $this->server->id,
            'deploy_path' => '/var/www/shipyard/my-app',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'deploy',
                'fix_ownership' => true,
            ])
            ->assertOk();

        $chownScript = null;

        foreach ($this->uploadedScripts as $script) {
            if (str_contains($script, 'chown -R')) {
                $chownScript = $script;
            }
        }

        $this->assertNotNull($chownScript);
        $this->assertStringContainsString("chown -R 'deploy':'deploy' '/var/www/shipyard/my-app'", $chownScript);
        $this->assertStringContainsString('www-data:www-data', $chownScript);
        $this->assertStringContainsString('storage', $chownScript);
        $this->assertStringContainsString('bootstrap/cache', $chownScript);
        $this->assertStringContainsString('|| true', $chownScript);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'deploy',
        ]);
    }

    public function test_switch_user_returns_422_and_leaves_username_unchanged_when_user_not_found(): void
    {
        $this->mockSsh(self::USERS_FIXTURE); // does not include "ghost"

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'ghost',
                'fix_ownership' => false,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'root',
        ]);
    }

    public function test_switch_user_returns_409_and_leaves_username_unchanged_when_connection_test_fails(): void
    {
        $this->mockSsh(self::SWITCH_USERS_FIXTURE, options: ['connectionSucceeds' => false]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'deploy',
                'fix_ownership' => false,
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'root',
        ]);
    }

    public function test_switch_user_rejects_a_malformed_username(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldNotReceive('connect');
            $mock->shouldNotReceive('testConnection');
        });

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'Bad User',
                'fix_ownership' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'root',
        ]);
    }
}
