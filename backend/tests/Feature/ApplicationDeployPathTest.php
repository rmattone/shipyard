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

    public function test_default_deploy_path_uses_home_layout_when_server_has_deploy_user(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server));

        $response->assertStatus(201);
        $this->assertSame('/home/shipyard/my-app', $response->json('application.deploy_path'));
    }

    public function test_default_deploy_path_keeps_var_www_when_server_has_no_deploy_user(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server));

        $response->assertStatus(201);
        $this->assertSame('/var/www/shipyard/my-app', $response->json('application.deploy_path'));
    }

    public function test_generate_path_preview_uses_the_server_layout_when_given_a_server(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->actingAs($user)
            ->postJson('/api/applications/generate-path', ['name' => 'My App', 'server_id' => $server->id])
            ->assertOk()
            ->assertJsonPath('deploy_path', '/home/shipyard/my-app');
    }

    public function test_generate_path_preview_defaults_to_legacy_without_a_server(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->postJson('/api/applications/generate-path', ['name' => 'My App'])
            ->assertOk()
            ->assertJsonPath('deploy_path', '/var/www/shipyard/my-app');
    }

    public function test_creating_hook_generates_home_layout_path_for_non_api_creation(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $application = Application::factory()->create([
            'server_id' => $server->id,
            'name' => 'Hook App',
            'deploy_path' => null,
        ]);

        $this->assertSame('/home/shipyard/hook-app', $application->deploy_path);
    }

    public function test_generated_slug_never_collapses_to_the_bare_base(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->assertSame('/home/shipyard/app', Application::generateDeployPath('###', $server));
    }

    public function test_generate_path_preview_ignores_foreign_org_servers(): void
    {
        // Build the foreign org's user/context and server first, then
        // create the acting user last so the context ends bound to them
        // (mirrors the setup in CrossTenantIsolationTest).
        $this->createOrgUser();
        $foreignServer = Server::factory()->create(['deploy_user' => 'shipyard']);

        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->postJson('/api/applications/generate-path', ['name' => 'My App', 'server_id' => $foreignServer->id])
            ->assertOk()
            ->assertJsonPath('deploy_path', '/var/www/shipyard/my-app');
    }
}
