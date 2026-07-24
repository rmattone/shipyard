<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UFW firewall management. These tests pin the safety-critical property of
 * enable(): the SSH port must be allowed BEFORE ufw is force-enabled, or
 * ShipYard would firewall itself out of every server the moment an admin
 * turns UFW on. They also cover the numbered-status parser (mixed rules,
 * v6 duplicates, unparseable lines) and the spec-based add/delete rule
 * commands (bare port, protocol "both", and source-scoped rules).
 */
class FirewallApiTest extends TestCase
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
        $this->server = Server::factory()->create(['username' => 'deploy', 'port' => 22]);
    }

    private const ACTIVE_MIXED_FIXTURE = <<<'TXT'
    Status: active

         To                         Action      From
         --                         ------      ----
    [ 1] 22/tcp                     ALLOW IN    Anywhere
    [ 2] 80,443/tcp                 ALLOW IN    10.0.0.0/24
    [ 3] 22/tcp (v6)                ALLOW IN    Anywhere (v6)
    this is not a rule line at all
    [ 4] 22/tcp                     ALLOW IN    Anywhere (v6)
    [ 5] 3000:3005/tcp              ALLOW IN    Anywhere
    [ 6] 22/tcp                     LIMIT IN    Anywhere
    [ 7] 22/tcp                     ALLOW IN    Anywhere on eth0
    [ 8] 22/tcp                     ALLOW IN    10.0.0.0/24               # office VPN
    TXT;

    private const INACTIVE_FIXTURE = <<<'TXT'
    Status: inactive
    TXT;

    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true): void
    {
        $this->uploadedScripts = [];

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds) {
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
        $this->getJson("/api/servers/{$this->server->id}/firewall")
            ->assertUnauthorized();
    }

    public function test_rules_endpoint_requires_authentication(): void
    {
        $this->postJson("/api/servers/{$this->server->id}/firewall/rules", [
            'port' => '8080',
            'protocol' => 'tcp',
        ])->assertUnauthorized();
    }

    public function test_get_parses_active_status_with_mixed_rules_and_skips_unparseable_lines(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $response = $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/firewall")
            ->assertOk()
            ->assertJson(['installed' => true, 'active' => true]);

        // Exact-match the whole rules list (not just a subset): this pins
        // both that every legitimate rule shape parses correctly AND that
        // the one deliberately unparseable junk line contributes no entry,
        // i.e. the list has exactly 8 items, not 9.
        $this->assertEquals([
            ['number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'v6' => false],
            ['number' => 2, 'to' => '80,443/tcp', 'action' => 'ALLOW IN', 'from' => '10.0.0.0/24', 'v6' => false],
            ['number' => 3, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'v6' => true],
            ['number' => 4, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'v6' => true],
            // Port range: the "to" column keeps ufw's colon-range syntax verbatim.
            ['number' => 5, 'to' => '3000:3005/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'v6' => false],
            // LIMIT IN: the action column isn't restricted to ALLOW/DENY/REJECT.
            ['number' => 6, 'to' => '22/tcp', 'action' => 'LIMIT IN', 'from' => 'Anywhere', 'v6' => false],
            // Interface-scoped rule: "on eth0" is part of the From column's
            // free text and must survive intact (single spaces inside it).
            ['number' => 7, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere on eth0', 'v6' => false],
            // ufw >= 0.35 rule comment: stripped from the From column.
            ['number' => 8, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => '10.0.0.0/24', 'v6' => false],
        ], $response->json('rules'));
    }

    public function test_get_parses_inactive_status(): void
    {
        $this->mockSsh(self::INACTIVE_FIXTURE);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/firewall")
            ->assertOk()
            ->assertJson([
                'installed' => true,
                'active' => false,
                'rules' => [],
            ]);
    }

    public function test_get_reports_not_installed_when_marker_is_present(): void
    {
        $this->mockSsh("SHIPYARD_UFW_MISSING\n");

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/firewall")
            ->assertOk()
            ->assertJson([
                'installed' => false,
                'active' => false,
                'rules' => [],
            ]);
    }

    public function test_get_returns_500_on_failure(): void
    {
        $this->mockSsh('boom', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/firewall")
            ->assertStatus(500);
    }

    public static function invalidRulePayloadProvider(): array
    {
        return [
            'port zero' => [['port' => '0', 'protocol' => 'tcp', 'source' => null], 'port'],
            'port too large' => [['port' => '70000', 'protocol' => 'tcp', 'source' => null], 'port'],
            'range low greater than high' => [['port' => '5000:400', 'protocol' => 'tcp', 'source' => null], 'port'],
            'range part zero' => [['port' => '0:100', 'protocol' => 'tcp', 'source' => null], 'port'],
            'bad port regex' => [['port' => '80abc', 'protocol' => 'tcp', 'source' => null], 'port'],
            'bad protocol' => [['port' => '8080', 'protocol' => 'icmp', 'source' => null], 'protocol'],
            'nonsense source' => [['port' => '8080', 'protocol' => 'tcp', 'source' => 'nonsense'], 'source'],
            'cidr out of range' => [['port' => '8080', 'protocol' => 'tcp', 'source' => '10.0.0.0/33'], 'source'],
            'ipv6 source rejected' => [['port' => '8080', 'protocol' => 'tcp', 'source' => '::1'], 'source'],
        ];
    }

    /** @dataProvider invalidRulePayloadProvider */
    public function test_post_rule_validation_rejects_bad_payloads(array $payload, string $invalidField): void
    {
        $this->mockSsh('Status: active');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$invalidField]);

        $this->assertCount(0, $this->uploadedScripts);
    }

    public function test_post_rule_happy_path_without_source(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
            ])
            ->assertCreated()
            ->assertJson(['installed' => true, 'active' => true]);

        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString('ufw allow', $script);
        $this->assertMatchesRegularExpression('/ufw allow \'?8080\'?\/tcp/', $script);
        $this->assertStringNotContainsString('/udp', $script);
    }

    public function test_post_rule_protocol_both_emits_tcp_and_udp_commands(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'both',
            ])
            ->assertCreated();

        $script = $this->uploadedScripts[0];

        $this->assertMatchesRegularExpression('/ufw allow \'?8080\'?\/tcp/', $script);
        $this->assertMatchesRegularExpression('/ufw allow \'?8080\'?\/udp/', $script);
    }

    public function test_post_rule_with_source_uses_from_form(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
                'source' => '10.0.0.0/24',
            ])
            ->assertCreated();

        $script = $this->uploadedScripts[0];

        $this->assertMatchesRegularExpression(
            '/ufw allow from \'?10\.0\.0\.0\/24\'? to any port \'?8080\'? proto tcp/',
            $script
        );
    }

    public function test_delete_rule_inserts_delete_keyword(): void
    {
        $this->mockSsh(self::INACTIVE_FIXTURE);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
            ])
            ->assertOk();

        $script = $this->uploadedScripts[0];

        $this->assertMatchesRegularExpression('/ufw delete allow \'?8080\'?\/tcp/', $script);
    }

    public function test_delete_rule_with_source_inserts_delete_keyword_in_from_form(): void
    {
        $this->mockSsh(self::INACTIVE_FIXTURE);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
                'source' => '10.0.0.0/24',
            ])
            ->assertOk();

        $script = $this->uploadedScripts[0];

        $this->assertMatchesRegularExpression(
            '/ufw delete allow from \'?10\.0\.0\.0\/24\'? to any port \'?8080\'? proto tcp/',
            $script
        );
    }

    /**
     * When protocol "both" fails partway through, set -euo pipefail aborts
     * the script right after the failing command, so only the phase marker
     * for the protocol that was actually running (udp here; tcp already
     * succeeded and its marker rolled by) makes it into the output.
     */
    public function test_add_rule_failure_names_the_protocol_that_actually_failed(): void
    {
        $this->mockSsh("SHIPYARD_UFW_PHASE_TCP\nSHIPYARD_UFW_PHASE_UDP\nERROR", scriptSucceeds: false);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'both',
            ])
            ->assertStatus(500);

        $this->assertStringContainsString('protocol udp', $response->json('message'));
    }

    public function test_delete_rule_failure_names_the_protocol_that_actually_failed_when_the_first_one_fails(): void
    {
        $this->mockSsh('SHIPYARD_UFW_PHASE_TCP', scriptSucceeds: false);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'both',
            ])
            ->assertStatus(500);

        $this->assertStringContainsString('protocol tcp', $response->json('message'));
    }

    public function test_enable_allows_the_ssh_port_before_force_enabling(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/enable")
            ->assertOk();

        $script = $this->uploadedScripts[0];

        $dialedPortAllowPos = strpos($script, 'ufw allow 22/tcp');
        $sshdEffectivePortsAllowPos = strpos($script, 'ufw allow "$p"/tcp');
        $enablePos = strpos($script, 'ufw --force enable');

        $this->assertNotFalse($dialedPortAllowPos, 'Script must allow the dialed SSH port.');
        $this->assertNotFalse($sshdEffectivePortsAllowPos, 'Script must also allow every port sshd reports as effective.');
        $this->assertNotFalse($enablePos, 'Script must force-enable ufw.');

        // BOTH allow commands - the dialed port and sshd's effective ports -
        // must land before ufw is ever force-enabled, or ShipYard could
        // firewall itself out the moment an admin turns ufw on.
        $this->assertLessThan($enablePos, $dialedPortAllowPos, 'The dialed-port allow rule must be added BEFORE ufw is enabled.');
        $this->assertLessThan($enablePos, $sshdEffectivePortsAllowPos, 'The sshd-effective-ports allow loop must run BEFORE ufw is enabled.');

        $this->assertStringContainsString('SSHD=$(command -v sshd', $script);
        $this->assertStringContainsString('$SUDO "$SSHD" -T', $script);
    }

    public function test_enable_uses_the_servers_configured_port(): void
    {
        $customPortServer = Server::factory()->create(['username' => 'deploy', 'port' => 2222]);
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$customPortServer->id}/firewall/enable")
            ->assertOk();

        $script = $this->uploadedScripts[0];

        $this->assertMatchesRegularExpression('/ufw allow \'?2222\'?\/tcp/', $script);
    }

    public function test_disable_endpoint(): void
    {
        $this->mockSsh(self::INACTIVE_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/disable")
            ->assertOk();

        $this->assertStringContainsString('ufw disable', $this->uploadedScripts[0]);
    }

    public function test_install_endpoint(): void
    {
        $this->mockSsh(self::ACTIVE_MIXED_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/install")
            ->assertOk();

        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $script);
        $this->assertStringContainsString('apt-get install -y ufw', $script);
    }

    public function test_mutations_return_500_on_ssh_failure(): void
    {
        $this->mockSsh('boom', scriptSucceeds: false);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
            ])
            ->assertStatus(500);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/firewall/rules", [
                'port' => '8080',
                'protocol' => 'tcp',
            ])
            ->assertStatus(500);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/enable")
            ->assertStatus(500);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/disable")
            ->assertStatus(500);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/firewall/install")
            ->assertStatus(500);
    }
}
