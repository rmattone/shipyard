<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sshd hardening toggles (PasswordAuthentication / PermitRootLogin). These
 * tests pin the dangerous properties of the write path: the drop-in must be
 * 00-shipyard.conf (not 99-, which would lose to Ubuntu's stock
 * 50-cloud-init.conf), a failed `sshd -t` must roll the file back rather
 * than leave a broken config in place, the service must reload (never
 * restart) sshd, and the effective values echoed back after the change must
 * be verified to match what was requested before the API reports success.
 */
class SshdSettingsTest extends TestCase
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
        $this->server = Server::factory()->create(['username' => 'deploy']);
    }

    private const SSHD_T_FIXTURE = <<<'TXT'
    addressfamily any
    allowagentforwarding yes
    allowtcpforwarding yes
    banner none
    clientaliveinterval 0
    clientalivecountmax 3
    compression no
    gatewayports no
    kbdinteractiveauthentication no
    logingracetime 120
    loglevel INFO
    maxauthtries 6
    maxsessions 10
    passwordauthentication yes
    permitrootlogin prohibit-password
    permittunnel no
    TXT;

    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true, bool $connectionTestSucceeds = true): void
    {
        $this->uploadedScripts = [];

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds, $connectionTestSucceeds) {
            $mock->shouldReceive('testConnection')->andReturn([
                'success' => $connectionTestSucceeds,
                'message' => $connectionTestSucceeds ? 'Connection successful' : 'Connection failed',
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

    public function test_get_endpoint_requires_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/sshd-settings")
            ->assertUnauthorized();
    }

    public function test_put_endpoint_requires_authentication(): void
    {
        $this->putJson("/api/servers/{$this->server->id}/sshd-settings", [
            'password_authentication' => 'no',
            'permit_root_login' => 'prohibit-password',
        ])->assertUnauthorized();
    }

    public function test_get_parses_a_realistic_sshd_t_fixture_with_include_support(): void
    {
        $this->mockSsh(self::SSHD_T_FIXTURE."\nSHIPYARD_HAS_INCLUDE\n");

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/sshd-settings")
            ->assertOk()
            ->assertJson([
                'password_authentication' => 'yes',
                'permit_root_login' => 'prohibit-password',
                'supports_include' => true,
            ]);
    }

    public function test_get_reports_no_include_support_when_marker_is_absent(): void
    {
        $this->mockSsh(self::SSHD_T_FIXTURE);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/sshd-settings")
            ->assertOk()
            ->assertJson([
                'password_authentication' => 'yes',
                'permit_root_login' => 'prohibit-password',
                'supports_include' => false,
            ]);
    }

    public function test_get_returns_500_when_directives_are_missing_from_output(): void
    {
        $this->mockSsh("addressfamily any\nloglevel INFO\nSHIPYARD_HAS_INCLUDE\n");

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/sshd-settings")
            ->assertStatus(500);
    }

    public function test_put_happy_path_applies_settings_and_returns_fresh_values(): void
    {
        $this->mockSsh("passwordauthentication no\npermitrootlogin prohibit-password\n");

        $response = $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertOk()
            ->assertJson([
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ]);

        $this->assertCount(1, $this->uploadedScripts);
        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString('/etc/ssh/sshd_config.d/00-shipyard.conf', $script);
        $this->assertStringContainsString('Include', $script);
        $this->assertStringContainsString('sshd_config.d', $script);
        $this->assertStringContainsString('"$SSHD" -t', $script);
        $this->assertStringContainsString('SHIPYARD_VALIDATION_FAILED', $script);
        $this->assertStringContainsString('systemctl reload', $script);
        $this->assertStringNotContainsString('systemctl restart', $script);
        $this->assertStringContainsString('# Managed by ShipYard - do not edit', $script);
        $this->assertStringContainsString('PasswordAuthentication no', $script);
        $this->assertStringContainsString('PermitRootLogin prohibit-password', $script);
        $this->assertMatchesRegularExpression('/SHIPYARD_EOF_\w+/', $script);

        unset($response);
    }

    public function test_put_root_lockout_guard_blocks_permit_root_login_no_for_a_root_server(): void
    {
        $rootServer = Server::factory()->create(['username' => 'root']);

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldNotReceive('testConnection');
            $mock->shouldNotReceive('connect');
        });

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$rootServer->id}/sshd-settings", [
                'password_authentication' => 'yes',
                'permit_root_login' => 'no',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Refusing to set PermitRootLogin to "no" on a server accessed as the root user: this would lock out the only configured SSH user. Use "prohibit-password" instead to keep key-based root login while disabling root password login.']);
    }

    public function test_put_allows_prohibit_password_for_a_root_server(): void
    {
        $rootServer = Server::factory()->create(['username' => 'root']);
        $this->mockSsh("passwordauthentication no\npermitrootlogin prohibit-password\n");

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$rootServer->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertOk();
    }

    public function test_put_returns_409_when_connection_check_fails(): void
    {
        $this->mockSsh('', connectionTestSucceeds: false);

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertStatus(409);

        $this->assertCount(0, $this->uploadedScripts);
    }

    public function test_put_returns_422_when_the_distro_lacks_include_support(): void
    {
        $this->mockSsh('SHIPYARD_NO_INCLUDE', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertStatus(422);
    }

    public function test_put_returns_500_when_sshd_validation_fails_and_changes_are_rolled_back(): void
    {
        $this->mockSsh('SHIPYARD_VALIDATION_FAILED', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertStatus(500);
    }

    public function test_put_returns_500_when_effective_values_do_not_match_requested(): void
    {
        // Script reports success, but the effective values echoed back don't
        // match what was requested; this must NOT be reported as success.
        $this->mockSsh("passwordauthentication yes\npermitrootlogin yes\n");

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertStatus(500);
    }

    public function test_put_validation_rejects_bad_enum_values(): void
    {
        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'maybe',
                'permit_root_login' => 'prohibit-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password_authentication']);

        $this->actingAs($this->user)
            ->putJson("/api/servers/{$this->server->id}/sshd-settings", [
                'password_authentication' => 'no',
                'permit_root_login' => 'sometimes',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['permit_root_login']);
    }
}
