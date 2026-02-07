<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_list_servers(): void
    {
        Server::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/servers');

        $response->assertOk()
            ->assertJsonCount(3);
    }

    public function test_can_create_server(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/servers', [
                'name' => 'Test Server',
                'host' => '192.168.1.100',
                'port' => 22,
                'username' => 'root',
                'private_key' => '-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----',
            ]);

        $response->assertCreated()
            ->assertJsonFragment(['name' => 'Test Server']);

        $this->assertDatabaseHas('servers', ['name' => 'Test Server']);
    }

    public function test_can_update_server(): void
    {
        $server = Server::factory()->create();

        $response = $this->actingAs($this->user)
            ->putJson("/api/servers/{$server->id}", [
                'name' => 'Updated Server',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Server']);
    }

    public function test_can_delete_server(): void
    {
        $server = Server::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    }

    public function test_cannot_delete_server_with_applications(): void
    {
        $server = Server::factory()->hasApplications(1)->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}");

        $response->assertUnprocessable();
    }

    public function test_unauthenticated_access_is_denied(): void
    {
        $response = $this->getJson('/api/servers');

        $response->assertUnauthorized();
    }
}
