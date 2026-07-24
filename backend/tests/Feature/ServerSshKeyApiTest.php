<?php

namespace Tests\Feature;

use App\Jobs\ProcessServerSshKeyInstall;
use App\Jobs\ProcessServerSshKeyRemoval;
use App\Models\Server;
use App\Models\ServerSshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServerSshKeyApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::factory()->create(['username' => 'root']);
    }

    /**
     * Generates a real, parseable ed25519 public key line (same wire format
     * ssh-keygen produces), so validation tests exercise the real parser
     * rather than a canned fixture string.
     */
    private function generateKey(string $comment = 'test@example.com'): string
    {
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).$publicKey;

        return 'ssh-ed25519 '.base64_encode($blob).' '.$comment;
    }

    /**
     * Builds an sk-ssh-ed25519@openssh.com blob: an OpenSSH string field per
     * component (uint32 length prefix + bytes), an inner type string, 32
     * bytes of "key material", and an application string. $innerType is
     * normally the same as the outer declared type; passing a different
     * value produces a blob that lies about its own type.
     */
    private function generateSkKey(string $innerType = 'sk-ssh-ed25519@openssh.com', string $comment = 'yubikey'): string
    {
        $keyMaterial = random_bytes(32);
        $application = 'ssh:';

        $blob = pack('N', strlen($innerType)).$innerType
            .pack('N', strlen($keyMaterial)).$keyMaterial
            .pack('N', strlen($application)).$application;

        return 'sk-ssh-ed25519@openssh.com '.base64_encode($blob).' '.$comment;
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/ssh-keys")
            ->assertUnauthorized();
    }

    public function test_index_lists_only_the_servers_keys(): void
    {
        ServerSshKey::factory()->create(['server_id' => $this->server->id]);
        ServerSshKey::factory()->create(['server_id' => $this->server->id]);
        ServerSshKey::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/ssh-keys")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_store_dispatches_the_install_job_and_normalizes_the_key(): void
    {
        Queue::fake();
        $key = $this->generateKey('deploy@laptop');

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'My laptop',
                'username' => 'deploy',
                'public_key' => "  {$key}  \n",
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['status' => 'installing', 'username' => 'deploy']);

        Queue::assertPushed(ProcessServerSshKeyInstall::class);

        $id = $response->json('id');
        $this->assertDatabaseHas('server_ssh_keys', [
            'id' => $id,
            'public_key' => $key,
            'status' => 'installing',
        ]);
    }

    public function test_store_rejects_an_unrecognized_key_format(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'Bad key',
                'username' => 'deploy',
                'public_key' => 'not-a-real-key at all',
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_store_rejects_a_multiline_key(): void
    {
        Queue::fake();
        $key = $this->generateKey();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'Multiline',
                'username' => 'deploy',
                'public_key' => "{$key}\n{$key}",
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_store_rejects_garbage_base64(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'Garbage',
                'username' => 'deploy',
                'public_key' => 'ssh-ed25519 !!!not-base64!!! comment',
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_store_accepts_a_valid_sk_key(): void
    {
        // phpseclib 3 cannot parse FIDO (sk-*) keys at all, so this must be
        // validated by the blob's self-declared type, not PublicKeyLoader.
        Queue::fake();
        $key = $this->generateSkKey();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'Security key',
                'username' => 'deploy',
                'public_key' => $key,
            ])
            ->assertStatus(202);

        Queue::assertPushed(ProcessServerSshKeyInstall::class);
    }

    public function test_store_rejects_an_sk_key_whose_blob_declares_a_different_inner_type(): void
    {
        Queue::fake();
        // The line declares sk-ssh-ed25519@openssh.com, but the blob's own
        // leading type string says ssh-ed25519.
        $key = $this->generateSkKey(innerType: 'ssh-ed25519');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'name' => 'Spoofed sk key',
                'username' => 'deploy',
                'public_key' => $key,
            ])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_store_rejects_a_duplicate_key_for_the_same_server_and_username(): void
    {
        Queue::fake();
        $key = $this->generateKey();

        $this->actingAs($this->user)->postJson("/api/servers/{$this->server->id}/ssh-keys", [
            'name' => 'First',
            'username' => 'deploy',
            'public_key' => $key,
        ])->assertStatus(202);

        $this->actingAs($this->user)->postJson("/api/servers/{$this->server->id}/ssh-keys", [
            'name' => 'Second',
            'username' => 'deploy',
            'public_key' => $key,
        ])->assertStatus(422);
    }

    public function test_store_allows_the_same_key_for_a_different_username(): void
    {
        Queue::fake();
        $key = $this->generateKey();

        $this->actingAs($this->user)->postJson("/api/servers/{$this->server->id}/ssh-keys", [
            'name' => 'First',
            'username' => 'deploy',
            'public_key' => $key,
        ])->assertStatus(202);

        $this->actingAs($this->user)->postJson("/api/servers/{$this->server->id}/ssh-keys", [
            'name' => 'Second',
            'username' => 'www-data',
            'public_key' => $key,
        ])->assertStatus(202);
    }

    public function test_store_rejects_malformed_usernames(): void
    {
        Queue::fake();
        $key = $this->generateKey();

        foreach (['root; rm -rf /', "www-data\nevil", '../etc', 'WWW-DATA', '1user', "user'"] as $username) {
            $this->actingAs($this->user)
                ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                    'name' => 'Name',
                    'username' => $username,
                    'public_key' => $key,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['username']);
        }

        Queue::assertNothingPushed();
    }

    public function test_store_requires_a_name(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/ssh-keys", [
                'username' => 'deploy',
                'public_key' => $this->generateKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        Queue::assertNothingPushed();
    }

    public function test_destroy_dispatches_the_removal_job_and_conflicts_while_removing(): void
    {
        Queue::fake();
        $key = ServerSshKey::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/ssh-keys/{$key->id}")
            ->assertStatus(202);

        Queue::assertPushed(ProcessServerSshKeyRemoval::class);
        $this->assertSame('removing', $key->fresh()->status);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/ssh-keys/{$key->id}")
            ->assertStatus(409);
    }

    public function test_destroy_404s_for_a_key_belonging_to_another_server(): void
    {
        $foreign = ServerSshKey::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/ssh-keys/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotNull($foreign->fresh());
    }

    public function test_authorized_endpoint_reports_tracked_and_untracked_keys(): void
    {
        $tracked = ServerSshKey::factory()->create([
            'server_id' => $this->server->id,
            'username' => 'root',
        ]);
        $untrackedKey = $this->generateKey('untracked@laptop');

        $fixture = implode("\n", [
            $tracked->public_key,
            '# a comment line',
            $untrackedKey,
            'this-is-not-a-valid-key-line',
        ]);

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($fixture) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturn([
                'output' => $fixture,
                'exit_code' => 0,
                'success' => true,
            ]);
        });

        $response = $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/ssh-keys/authorized")
            ->assertOk()
            ->assertJson(['username' => 'root']);

        $keys = $response->json('keys');

        $this->assertCount(3, $keys);
        $this->assertSame('ssh-ed25519', $keys[0]['type']);
        $this->assertTrue($keys[0]['tracked']);
        $this->assertSame($tracked->fingerprint, $keys[0]['fingerprint']);

        $this->assertSame('ssh-ed25519', $keys[1]['type']);
        $this->assertFalse($keys[1]['tracked']);

        $this->assertSame('unknown', $keys[2]['type']);
        $this->assertFalse($keys[2]['tracked']);
        $this->assertNull($keys[2]['fingerprint']);
    }

    public function test_authorized_endpoint_rejects_a_bad_username_query_param(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/ssh-keys/authorized?username=".urlencode('bad user'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_authorized_endpoint_maps_ssh_failures_to_500(): void
    {
        $this->mock(\App\Services\SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturn([
                'output' => 'permission denied',
                'exit_code' => 1,
                'success' => false,
            ]);
        });

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/ssh-keys/authorized")
            ->assertStatus(500);
    }
}
