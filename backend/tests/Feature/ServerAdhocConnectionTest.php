<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The create-server form must be able to test credentials BEFORE the server
 * record exists, so the endpoint takes the connection details directly and
 * never persists anything.
 */
class ServerAdhocConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_can_be_tested_without_creating_a_server(): void
    {
        $received = null;
        $this->mock(SSHService::class, function ($mock) use (&$received) {
            $mock->shouldReceive('testConnection')->andReturnUsing(function (Server $server) use (&$received) {
                $received = $server;

                return ['success' => true, 'message' => 'Connection successful', 'system_info' => 'Linux ip-10-0-0-1'];
            });
        });

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/servers/test-connection', [
                'host' => '54.10.20.30',
                'port' => 2222,
                'username' => 'ubuntu',
                'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, Server::count(), 'The ad-hoc test must not persist a server.');
        $this->assertNotNull($received);
        $this->assertSame('54.10.20.30', $received->host);
        $this->assertSame(2222, $received->port);
        $this->assertFalse($received->exists);
    }

    public function test_a_failed_connection_returns_422_with_the_reason(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('testConnection')->andReturn([
                'success' => false, 'message' => 'Authentication failed', 'system_info' => null,
            ]);
        });

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/servers/test-connection', [
                'host' => '54.10.20.30',
                'username' => 'ubuntu',
                'private_key' => 'not-a-real-key',
            ]);

        $response->assertStatus(422)->assertJson(['success' => false, 'message' => 'Authentication failed']);
    }

    public function test_connection_details_are_required(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/servers/test-connection', ['host' => '54.10.20.30']);

        $response->assertStatus(422)->assertJsonValidationErrors(['username', 'private_key']);
    }
}
