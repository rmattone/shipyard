<?php

namespace Tests\Feature;

use App\Models\GitProvider;
use App\Models\Server;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_existing_untrusted_server_fails_closed_with_actionable_error(): void
    {
        $this->actingAs($this->createOrgUser());
        $server = Server::factory()->create();
        $this->postJson("/api/servers/{$server->id}/test-connection")->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
