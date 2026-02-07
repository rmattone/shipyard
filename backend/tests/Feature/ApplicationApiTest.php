<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->server = Server::factory()->create();
    }

    public function test_can_list_applications(): void
    {
        Application::factory()->count(3)->create(['server_id' => $this->server->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/applications');

        $response->assertOk()
            ->assertJsonCount(3);
    }

    public function test_can_create_application(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/applications', [
                'server_id' => $this->server->id,
                'name' => 'Test App',
                'type' => 'laravel',
                'domain' => 'test.example.com',
                'repository_url' => 'git@gitlab.com:test/repo.git',
                'branch' => 'main',
                'deploy_path' => '/var/www/test',
            ]);

        $response->assertCreated()
            ->assertJsonPath('application.name', 'Test App')
            ->assertJsonStructure(['webhook_url', 'webhook_secret']);

        $this->assertDatabaseHas('applications', ['name' => 'Test App']);
    }

    public function test_can_view_application(): void
    {
        $app = Application::factory()->create(['server_id' => $this->server->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}");

        $response->assertOk()
            ->assertJsonFragment(['name' => $app->name]);
    }

    public function test_can_delete_application(): void
    {
        $app = Application::factory()->create(['server_id' => $this->server->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/applications/{$app->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('applications', ['id' => $app->id]);
    }
}
