<?php

namespace Tests\Feature;

use App\Models\GitProvider;
use App\Models\Server;
use App\Services\SSHService;
use App\Support\Ssh\HostKey;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HostKeyFixture;
use Tests\TestCase;

class SshHostKeyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_explicitly_save_trust_for_servers_and_providers(): void
    {
        $this->actingAs($this->createOrgUser());
        $key = HostKeyFixture::key();
        $server = $this->postJson('/api/servers', [
            'name' => 'Pinned', 'host' => 'example.test', 'username' => 'root', 'private_key' => 'credential', 'ssh_host_key' => $key,
        ])->assertCreated()->assertJsonPath('ssh_host_key', $key)->json('id');
        $provider = $this->postJson('/api/git-providers', [
            'name' => 'Pinned Git', 'type' => 'github', 'private_key' => 'credential', 'ssh_host_key' => $key,
        ])->assertCreated()->assertJsonPath('ssh_host_key', $key)->json('id');
        $this->assertSame($key, Server::findOrFail($server)->ssh_host_key);
        $this->assertSame($key, GitProvider::findOrFail($provider)->ssh_host_key);
        foreach (["/api/servers/{$server}", "/api/git-providers/{$provider}"] as $url) {
            $this->putJson($url, ['ssh_host_key' => "*.test ssh-ed25519 abc\nmalicious"])->assertUnprocessable()->assertJsonValidationErrors('ssh_host_key');
        }
    }

    public function test_members_cannot_replace_trust_and_other_organizations_cannot_access_it(): void
    {
        $member = $this->createOrgUser('member');
        $server = Server::factory()->create(['ssh_host_key' => HostKeyFixture::key()]);
        $provider = GitProvider::factory()->create(['ssh_host_key' => HostKeyFixture::key()]);
        $this->actingAs($member);
        foreach (["/api/servers/{$server->id}", "/api/git-providers/{$provider->id}"] as $url) {
            $this->putJson($url, ['ssh_host_key' => null])->assertForbidden();
        }
        $this->actingAs($this->createOrgUser());
        foreach (["/api/servers/{$server->id}", "/api/git-providers/{$provider->id}"] as $url) {
            $this->putJson($url, ['ssh_host_key' => null])->assertNotFound();
        }
    }

    public function test_adhoc_test_carries_explicit_trust_without_persisting_a_server(): void
    {
        $this->actingAs($this->createOrgUser());
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('testConnection')->once()->withArgs(fn (Server $server) => $server->ssh_host_key === HostKeyFixture::key())
                ->andReturn(['success' => true]);
        });
        $this->postJson('/api/servers/test-connection', [
            'host' => 'example.test', 'username' => 'root', 'private_key' => 'credential', 'ssh_host_key' => HostKeyFixture::key(),
        ])->assertOk();
        $this->assertSame(0, Server::count());
    }

    public function test_server_can_be_created_without_a_host_key_and_learns_it_on_connection(): void
    {
        $this->actingAs($this->createOrgUser());
        $id = $this->postJson('/api/servers', [
            'name' => 'First connection', 'host' => 'example.test', 'username' => 'root', 'private_key' => 'credential',
        ])->assertCreated()->json('id');
        $this->assertNull(Server::findOrFail($id)->ssh_host_key);
        $this->fakeSsh();

        $key = HostKey::normalize(HostKeyFixture::key());
        $this->postJson("/api/servers/{$id}/test-connection")->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('ssh_host_key', $key);
        $this->assertSame($key, Server::findOrFail($id)->ssh_host_key);

        // Saving unrelated settings must retain the automatically learned key.
        $this->putJson("/api/servers/{$id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('ssh_host_key', $key);
    }

    public static function unknownHostKeys(): array
    {
        return ['omitted' => [[]], 'empty' => [['ssh_host_key' => '']]];
    }

    #[DataProvider('unknownHostKeys')]
    public function test_adhoc_connection_learns_an_omitted_or_empty_host_key_without_creating_a_server(array $trust): void
    {
        $this->actingAs($this->createOrgUser());
        $this->fakeSsh();
        $this->postJson('/api/servers/test-connection', [
            'host' => 'example.test', 'username' => 'root', 'private_key' => 'credential', ...$trust,
        ])->assertOk()->assertJsonPath('ssh_host_key', HostKey::normalize(HostKeyFixture::key()));
        $this->assertSame(0, Server::count());
    }

    public function test_failed_authentication_does_not_save_a_host_key(): void
    {
        $this->actingAs($this->createOrgUser());
        $server = Server::factory()->create();
        $client = $this->fakeSsh(authenticated: false);
        $client->shouldNotReceive('exec');

        $this->postJson("/api/servers/{$server->id}/test-connection")->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', "SSH authentication failed for {$server->host}");
        $this->assertNull($server->fresh()->ssh_host_key);
    }

    public function test_concurrent_enrollment_cannot_overwrite_a_different_key_or_execute_commands(): void
    {
        $this->actingAs($this->createOrgUser());
        $server = Server::factory()->create();
        $otherKey = HostKey::normalize(EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH'));
        $client = $this->fakeSsh(onLogin: fn () => $server->update(['ssh_host_key' => $otherKey]));
        $client->shouldNotReceive('exec');

        $this->postJson("/api/servers/{$server->id}/test-connection")->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'SSH host key mismatch. Connection refused because another connection saved a different host key.');
        $this->assertSame($otherKey, $server->fresh()->ssh_host_key);
    }

    public function test_concurrent_enrollment_of_the_same_key_succeeds(): void
    {
        $this->actingAs($this->createOrgUser());
        $server = Server::factory()->create();
        $key = HostKey::normalize(HostKeyFixture::key());
        $this->fakeSsh(onLogin: fn () => $server->update(['ssh_host_key' => $key]));

        $this->postJson("/api/servers/{$server->id}/test-connection")->assertOk()
            ->assertJsonPath('ssh_host_key', $key);
        $this->assertSame($key, $server->fresh()->ssh_host_key);
    }

    public function test_a_stale_model_checks_the_key_learned_by_another_connection_before_login(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create(['is_local' => false]);
        $otherKey = HostKey::normalize(EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH'));
        $server->fresh()->update(['ssh_host_key' => $otherKey]);
        $client = $this->fakeSsh();
        $client->shouldNotReceive('login');
        $client->shouldNotReceive('exec');

        $result = app(SSHService::class)->testConnection($server);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('host key mismatch', $result['message']);
        $this->assertSame($otherKey, $server->fresh()->ssh_host_key);
    }

    private function fakeSsh(bool $authenticated = true, ?Closure $onLogin = null): Mockery\MockInterface
    {
        $client = Mockery::mock(SSH2::class);
        $client->shouldReceive('setTimeout');
        $client->shouldReceive('setPreferredAlgorithms');
        $client->shouldReceive('getServerPublicHostKey')->once()->andReturn(HostKeyFixture::key());
        $client->shouldReceive('login')->andReturnUsing(function () use ($authenticated, $onLogin) {
            $onLogin?->__invoke();

            return $authenticated;
        })->byDefault();
        $client->shouldReceive('exec')->andReturn('Linux test server')->byDefault();
        $client->shouldReceive('isTimeout')->andReturn(false);
        $client->shouldReceive('getExitStatus')->andReturn(0);
        $client->shouldReceive('disconnect');

        $this->app->instance(SSHService::class, new class($client) extends SSHService
        {
            public function __construct(private SSH2 $client) {}

            protected function makeSshClient(Server $server): SSH2
            {
                return $this->client;
            }

            protected function loadPrivateKey(Server $server): mixed
            {
                return 'fake';
            }
        });

        return $client;
    }
}
