<?php

namespace Tests\Feature;

use App\Jobs\ProcessServerSshKeyInstall;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\User;
use App\Services\AuthorizedKeysService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\TestCase;

/**
 * Server-user management: listing login users, creating a deploy user,
 * switching ShipYard's own connection user, and marking an EXISTING user as
 * the deploy user. These tests pin the dangerous properties: a sudoers file
 * is validated (visudo -cf) before it is ever installed, the new connection
 * user is verified reachable BEFORE the switch is persisted, a failed
 * verification must leave server->username completely unchanged in the
 * database, and marking a deploy user (markDeployUser) must not persist
 * deploy_user unless the target account has a login shell, lives at exactly
 * /home/{user}, has no applications deployed outside that home (their
 * PHP-FPM pool and file ownership would stop matching), and its home
 * directory both exists and is owned by that same account before the
 * restricting chmod ever runs.
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

        $this->user = $this->createOrgUser();

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
     * @param  array{connectionSucceeds?: bool, sudoCheckSucceeds?: bool, expectedTestConnectionUsername?: string, failScriptsContaining?: string, outputForScriptsContaining?: array<string, string>}  $options
     */
    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true, array $options = []): void
    {
        $this->uploadedScripts = [];
        $connectionSucceeds = $options['connectionSucceeds'] ?? true;
        $sudoCheckSucceeds = $options['sudoCheckSucceeds'] ?? true;
        $expectedTestConnectionUsername = $options['expectedTestConnectionUsername'] ?? null;
        // Lets a single test make ONE of several scripts uploaded within the
        // same request fail (matched by a substring of its body), while the
        // rest succeed with $scriptSucceeds. Needed because a request can
        // run more than one remote script (e.g. listUsers, then a follow-up
        // chmod) and they must be independently controllable to pin
        // ordering invariants (e.g. "do not persist until the LAST script
        // succeeds").
        $failScriptsContaining = $options['failScriptsContaining'] ?? null;
        // Same idea, but overrides OUTPUT instead of success/failure: lets a
        // test make the script whose body contains a given substring return
        // a specific marker (e.g. SHIPYARD_HOME_MISSING) while every other
        // script in the same request keeps returning $scriptOutput. Needed
        // because markDeployUser's follow-up script echoes its own markers
        // that listUsers' script output would otherwise mask.
        $outputForScriptsContaining = $options['outputForScriptsContaining'] ?? [];

        $uploadedByPath = [];

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds, $connectionSucceeds, $sudoCheckSucceeds, $expectedTestConnectionUsername, $failScriptsContaining, $outputForScriptsContaining, &$uploadedByPath) {
            $testConnectionExpectation = $mock->shouldReceive('testConnection');

            if ($expectedTestConnectionUsername !== null) {
                // Pins that verification runs AS THE NEW (not-yet-persisted)
                // user, not the server's current connection user.
                $testConnectionExpectation->withArgs(
                    fn (Server $s) => $s->username === $expectedTestConnectionUsername && ! $s->exists
                );
            }

            $testConnectionExpectation->andReturn([
                'success' => $connectionSucceeds,
                'message' => $connectionSucceeds ? 'Connection successful' : 'Connection failed',
                'system_info' => null,
            ]);
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) use (&$uploadedByPath) {
                $this->uploadedScripts[] = $content;
                $uploadedByPath[$path] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptOutput, $scriptSucceeds, $sudoCheckSucceeds, $failScriptsContaining, $outputForScriptsContaining, &$uploadedByPath) {
                if (str_starts_with($command, 'bash ')) {
                    $succeeds = $scriptSucceeds;
                    $output = $scriptOutput;

                    // runRemoteScript always executes as `bash '<path>' 2>&1`;
                    // recover the path so the uploaded body (recorded above)
                    // can be inspected for the failing marker / output override.
                    if (preg_match("/^bash '(.+)' 2>&1$/", $command, $matches)) {
                        $content = $uploadedByPath[$matches[1]] ?? '';

                        if ($failScriptsContaining !== null && str_contains($content, $failScriptsContaining)) {
                            $succeeds = false;
                        }

                        foreach ($outputForScriptsContaining as $needle => $overrideOutput) {
                            if (str_contains($content, $needle)) {
                                $output = $overrideOutput;

                                break;
                            }
                        }
                    }

                    return [
                        'output' => $output,
                        'exit_code' => $succeeds ? 0 : 1,
                        'success' => $succeeds,
                    ];
                }

                if (str_starts_with($command, 'sudo -n true')) {
                    return [
                        'output' => '',
                        'exit_code' => $sudoCheckSucceeds ? 0 : 1,
                        'success' => $sudoCheckSucceeds,
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

    public function test_set_deploy_user_requires_authentication(): void
    {
        $this->postJson("/api/servers/{$this->server->id}/deploy-user", [
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

        $this->assertStringContainsString("useradd -m -d '/home/deploy' -s /bin/bash 'deploy'", $script);
        $this->assertStringContainsString('visudo -cf', $script);
        $this->assertStringContainsString('deploy ALL=(ALL) NOPASSWD:ALL', $script);
        $this->assertMatchesRegularExpression('/SHIPYARD_EOF_\w+/', $script);
        $this->assertStringContainsString('install -m 0440', $script);
        $this->assertStringContainsString('/etc/sudoers.d/shipyard-deploy', $script);
        $this->assertLessThan(strpos($script, 'visudo'), strpos($script, 'chmod 711'));

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

        $this->assertStringContainsString("useradd -m -d '/home/deploy' -s /bin/bash 'deploy'", $script);
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
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('server_ssh_keys', ['username' => 'deploy']);
        Queue::assertNothingPushed();
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_store_reuses_a_stale_ssh_key_row_instead_of_tripping_the_unique_index(): void
    {
        Queue::fake();
        $this->mockSsh('');

        // Simulates a retried request: the OS user create succeeded on a
        // prior attempt and left a ServerSshKey row behind, but the fresh
        // request must absorb it (same server_id/username/fingerprint)
        // rather than raw-500 on the unique index.
        $publicKey = PublicKeyLoader::load($this->server->private_key)
            ->getPublicKey()->toString('OpenSSH');
        $normalized = app(AuthorizedKeysService::class)->validateAndNormalize($publicKey);

        $existing = ServerSshKey::factory()->create([
            'server_id' => $this->server->id,
            'username' => 'deploy',
            'fingerprint' => $normalized['fingerprint'],
            'name' => 'stale-name',
            'public_key' => $normalized['key'],
            'status' => 'failed',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'deploy',
                'sudo' => true,
            ])
            ->assertStatus(201);

        $this->assertDatabaseCount('server_ssh_keys', 1);
        $this->assertDatabaseHas('server_ssh_keys', [
            'id' => $existing->id,
            'server_id' => $this->server->id,
            'username' => 'deploy',
            'name' => 'ShipYard',
            'status' => 'installing',
        ]);

        Queue::assertPushed(ProcessServerSshKeyInstall::class);
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

    public function test_create_user_script_restricts_home_directory_to_711(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'sudo' => false,
            ])
            ->assertStatus(201);

        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString("chmod 711 '/home/shipyard'", $script);
        $this->assertGreaterThan(strpos($script, 'useradd'), strpos($script, 'chmod 711'));
    }

    public function test_create_user_with_use_as_deploy_user_sets_the_server_column(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('server.deploy_user', 'shipyard');

        $this->assertSame('shipyard', $this->server->fresh()->deploy_user);
    }

    public function test_create_user_without_flag_leaves_deploy_user_null(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", ['username' => 'shipyard'])
            ->assertStatus(201)
            ->assertJsonPath('server.deploy_user', null);

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_failed_create_does_not_set_deploy_user(): void
    {
        Queue::fake();
        $this->mockSsh('SHIPYARD_USER_EXISTS');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($this->server->fresh()->deploy_user);
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
        $this->mockSsh(self::SWITCH_USERS_FIXTURE, options: ['expectedTestConnectionUsername' => 'deploy']);

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
        // Trailing colon (no group) sets the user's login group, rather than
        // assuming a group literally named after the user.
        $this->assertStringContainsString("chown -R 'deploy': '/var/www/shipyard/my-app'", $chownScript);
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

    public function test_switch_user_returns_409_and_leaves_username_unchanged_when_target_user_lacks_passwordless_sudo(): void
    {
        $this->mockSsh(self::SWITCH_USERS_FIXTURE, options: [
            'sudoCheckSucceeds' => false,
            'expectedTestConnectionUsername' => 'deploy',
        ]);

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

    public function test_switch_user_to_root_skips_the_passwordless_sudo_check(): void
    {
        // sudoCheckSucceeds is false to prove root's success here is NOT
        // because the sudo check happened to pass; root must skip it
        // entirely, since root never needs sudo.
        $this->mockSsh(self::SWITCH_USERS_FIXTURE, options: ['sudoCheckSucceeds' => false]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/switch-user", [
                'username' => 'root',
                'fix_ownership' => false,
            ])
            ->assertOk()
            ->assertJsonFragment(['username' => 'root']);

        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'username' => 'root',
        ]);
    }

    public function test_switch_user_rejects_local_servers(): void
    {
        $localServer = Server::factory()->create([
            'username' => 'root',
            'is_local' => true,
        ]);

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldNotReceive('testConnection');
            $mock->shouldNotReceive('connect');
            $mock->shouldNotReceive('execute');
        });

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$localServer->id}/switch-user", [
                'username' => 'deploy',
                'fix_ownership' => false,
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('servers', [
            'id' => $localServer->id,
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

    // ---------------------------------------------------------------
    // POST /servers/{server}/deploy-user (markDeployUser)
    // ---------------------------------------------------------------

    public function test_set_deploy_user_marks_an_existing_user(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertOk()
            ->assertJsonPath('deploy_user', 'deploy')
            ->assertJsonPath('default_deploy_base', '/home/deploy');

        $this->assertSame('deploy', $this->server->fresh()->deploy_user);

        // The follow-up script restricts the home directory, but only after
        // verifying it exists and is owned by the account being adopted
        // (adopted accounts, unlike freshly useradd'd ones, give no such
        // guarantee). These assertions inspect the uploaded script body
        // directly rather than routing through mocked marker output, so
        // deleting either check from the real script fails this test even
        // though the mock never actually interprets shell.
        $joined = implode("\n", $this->uploadedScripts);
        $this->assertStringContainsString("test -d '/home/deploy'", $joined);
        $this->assertStringContainsString("stat -c %U '/home/deploy'", $joined);
        $this->assertStringContainsString("chmod 711 '/home/deploy'", $joined);
    }

    public function test_set_deploy_user_rejects_unknown_user(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'ghost'])
            ->assertStatus(422);

        $this->assertStringContainsString('was not found among login-capable users', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_root(): void
    {
        // Without this mock, a regression of the root guard would fall
        // through to listUsers() and have the real SSHService dial the
        // factory's random public IP.
        $this->mockSsh(self::USERS_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'root'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Root cannot be used as the deploy user.');

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_nonstandard_home(): void
    {
        // Fixture with a user whose home is outside /home; the layout
        // generates /home/{user} paths, so this must be refused loudly.
        $fixture = <<<'TXT'
        USER:root:0:/root:/bin/bash
        USER:svc:1000:/srv/svc:/bin/bash
        SUDO:root:yes
        SUDO:svc:no
        TXT;

        $this->mockSsh($fixture);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'svc'])
            ->assertStatus(422);

        $this->assertStringContainsString('deploy layout requires', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_a_login_incapable_user(): void
    {
        // listUsers() filters nologin/false-shell accounts out entirely, so
        // an account that genuinely exists but cannot log in must NOT be
        // told it was simply "not found" — it is expected to become the
        // server's connection user, so a login shell is mandatory.
        $fixture = <<<'TXT'
        USER:root:0:/root:/bin/bash
        USER:svc2:1002:/home/svc2:/usr/sbin/nologin
        SUDO:root:yes
        SUDO:svc2:no
        TXT;

        $this->mockSsh($fixture);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'svc2'])
            ->assertStatus(422);

        $this->assertStringContainsString("expected to become this server's connection user", $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_does_not_persist_when_chmod_fails(): void
    {
        $this->mockSsh(self::USERS_FIXTURE, options: ['failScriptsContaining' => 'chmod 711']);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertStatus(500);

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_when_home_directory_is_missing(): void
    {
        // The follow-up script's HOME_MISSING marker is echoed instead of
        // listUsers' own fixture output, but only for the script whose body
        // contains 'stat -c %U' (the follow-up script; 'chmod 711' is NOT
        // used as the key here because createDeployUser's script also
        // contains that substring, so it would be an ambiguous match), so
        // listUsers itself still succeeds normally and finds 'deploy'.
        $this->mockSsh(self::USERS_FIXTURE, options: [
            'outputForScriptsContaining' => ['stat -c %U' => 'SHIPYARD_HOME_MISSING'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertStatus(422);

        $this->assertStringContainsString('no home directory', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_when_home_directory_is_not_owned_by_the_user(): void
    {
        $this->mockSsh(self::USERS_FIXTURE, options: [
            'outputForScriptsContaining' => ['stat -c %U' => 'SHIPYARD_HOME_NOT_OWNED'],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertStatus(422);

        $this->assertStringContainsString('not owned by', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_when_applications_live_outside_the_new_home(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        Application::factory()->create([
            'server_id' => $this->server->id,
            'deploy_path' => '/var/www/shipyard/old-app',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertStatus(422);

        $this->assertStringContainsString('outside /home/', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
        // Fails fast: never even reaches listUsers/the remote script.
        $this->assertEmpty($this->uploadedScripts);
    }

    public function test_set_deploy_user_layout_guard_is_not_fooled_by_like_wildcards(): void
    {
        // '_' is a single-character wildcard under SQL LIKE, so a naive
        // `where('deploy_path', 'not like', "/home/{$username}/%")` would
        // let an app parked at /home/depXloy/... (any X) slip through the
        // guard for username 'dep_loy', since '_' in the pattern matches
        // the literal 'x'. The guard must do exact prefix comparison.
        $this->mockSsh(self::USERS_FIXTURE);

        Application::factory()->create([
            'server_id' => $this->server->id,
            'deploy_path' => '/home/depxloy/other-app',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'dep_loy'])
            ->assertStatus(422);

        $this->assertStringContainsString('outside /home/', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_create_user_with_use_as_deploy_user_rejects_when_applications_live_outside_the_new_home(): void
    {
        Queue::fake();
        $this->mockSsh('');

        Application::factory()->create([
            'server_id' => $this->server->id,
            'deploy_path' => '/var/www/shipyard/old-app',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('outside /home/', $response->json('message'));
        $this->assertNull($this->server->fresh()->deploy_user);
        // Fails fast, BEFORE the remote useradd script is ever uploaded.
        $this->assertEmpty($this->uploadedScripts);
        Queue::assertNothingPushed();
    }
}
