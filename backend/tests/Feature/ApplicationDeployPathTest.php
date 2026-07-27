<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDeployPathTest extends TestCase
{
    use RefreshDatabase;

    private function payload(Server $server, array $overrides = []): array
    {
        return array_merge([
            'server_id' => $server->id,
            'name' => 'My App',
            'type' => 'laravel',
            'repository_url' => 'git@example.com:test/repo.git',
        ], $overrides);
    }

    public function test_duplicate_deploy_path_on_same_server_is_rejected(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        // "My App" and "my-app" both slug to /var/www/shipyard/my-app
        Application::factory()->create(['server_id' => $server->id, 'deploy_path' => '/var/www/shipyard/my-app']);

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server, ['name' => 'my-app']));

        $response->assertStatus(422);
        $this->assertSame(1, Application::where('server_id', $server->id)->count());
    }

    public function test_same_deploy_path_on_a_different_server_is_allowed(): void
    {
        $user = $this->createOrgUser();
        $serverA = Server::factory()->create();
        $serverB = Server::factory()->create();

        Application::factory()->create(['server_id' => $serverA->id, 'deploy_path' => '/var/www/shipyard/my-app']);

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($serverB, ['deploy_path' => '/var/www/shipyard/my-app']));

        $response->assertStatus(201);
    }

    public function test_shallow_deploy_path_is_rejected(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server, ['deploy_path' => '/home/']));

        $response->assertStatus(422)->assertJsonValidationErrors('deploy_path');
    }

    public function test_deploy_path_with_traversal_is_rejected(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server, ['deploy_path' => '/var/www/../../etc']));

        $response->assertStatus(422)->assertJsonValidationErrors('deploy_path');
    }
}
