<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitProvider;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Tag;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The isolation matrix: nothing owned by organization B may be visible
 * or reachable to a user acting in organization A, whatever the path
 * (index, binding, nested route, validation rule, or background job).
 */
class CrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private User $userB;

    private Server $serverB;

    private Application $applicationB;

    private GitProvider $gitProviderB;

    protected function setUp(): void
    {
        parent::setUp();

        // Build org B's world first (context bound to B while creating),
        // then org A's user last so the context ends bound to A.
        $this->userB = $this->createOrgUser();
        $this->serverB = Server::factory()->create();
        $this->applicationB = Application::factory()->create(['server_id' => $this->serverB->id]);
        $this->gitProviderB = GitProvider::factory()->create();

        $this->userA = $this->createOrgUser();
    }

    public function test_index_endpoints_hide_the_other_organizations_resources(): void
    {
        $serverA = Server::factory()->create();

        $this->actingAs($this->userA)
            ->getJson('/api/servers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissing(['id' => $this->serverB->id])
            ->assertJsonFragment(['id' => $serverA->id]);

        $this->actingAs($this->userA)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($this->userA)
            ->getJson('/api/git-providers')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($this->userA)
            ->getJson('/api/notification-channels')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_show_update_and_delete_bindings_return_404_for_the_other_organization(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/servers/{$this->serverB->id}")
            ->assertNotFound();

        $this->actingAs($this->userA)
            ->putJson("/api/servers/{$this->serverB->id}", ['name' => 'stolen'])
            ->assertNotFound();

        $this->actingAs($this->userA)
            ->deleteJson("/api/servers/{$this->serverB->id}")
            ->assertNotFound();

        $this->actingAs($this->userA)
            ->getJson("/api/git-providers/{$this->gitProviderB->id}")
            ->assertNotFound();
    }

    public function test_top_level_child_bindings_return_404_for_the_other_organization(): void
    {
        $deploymentB = Deployment::factory()->create(['application_id' => $this->applicationB->id]);

        $this->actingAs($this->userA)
            ->getJson("/api/applications/{$this->applicationB->id}")
            ->assertNotFound();

        $this->actingAs($this->userA)
            ->getJson("/api/applications/{$this->applicationB->id}/deployments")
            ->assertNotFound();

        // Deployment reached through its own application still resolves.
        $this->actingAs($this->userB)
            ->getJson("/api/applications/{$this->applicationB->id}/deployments")
            ->assertOk()
            ->assertJsonFragment(['id' => $deploymentB->id]);
    }

    public function test_nested_routes_404_when_the_parent_belongs_to_the_other_organization(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/servers/{$this->serverB->id}/daemons")
            ->assertNotFound();

        $this->actingAs($this->userA)
            ->getJson("/api/servers/{$this->serverB->id}/tags")
            ->assertNotFound();
    }

    public function test_cannot_create_an_application_on_the_other_organizations_server(): void
    {
        $this->actingAs($this->userA)
            ->postJson('/api/applications', [
                'server_id' => $this->serverB->id,
                'name' => 'intruder',
                'type' => 'static',
                'repository_url' => 'https://example.com/repo.git',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['server_id']);
    }

    public function test_cannot_attach_the_other_organizations_git_provider(): void
    {
        $serverA = Server::factory()->create();

        $this->actingAs($this->userA)
            ->postJson('/api/applications', [
                'server_id' => $serverA->id,
                'git_provider_id' => $this->gitProviderB->id,
                'name' => 'intruder',
                'type' => 'static',
                'repository_url' => 'https://example.com/repo.git',
                'branch' => 'main',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['git_provider_id']);
    }

    public function test_cannot_rollback_to_the_other_organizations_deployment(): void
    {
        $deploymentB = Deployment::factory()->create([
            'application_id' => $this->applicationB->id,
            'status' => 'success',
            'release_path' => '/var/www/app/releases/1',
        ]);

        $serverA = Server::factory()->create();
        $applicationA = Application::factory()->create([
            'server_id' => $serverA->id,
            'deployment_strategy' => 'atomic',
        ]);

        $this->actingAs($this->userA)
            ->postJson("/api/applications/{$applicationA->id}/rollback", [
                'deployment_id' => $deploymentB->id,
            ])
            ->assertNotFound();
    }

    public function test_cannot_sync_tags_from_another_server(): void
    {
        $serverA = Server::factory()->create();
        $applicationA = Application::factory()->create(['server_id' => $serverA->id]);

        // Tag on org B's server; ids are global so this also covers the
        // cross-organization case.
        CurrentOrganization::forget();
        $tagB = Tag::factory()->create(['server_id' => $this->serverB->id]);

        $this->actingAs($this->userA)
            ->putJson("/api/applications/{$applicationA->id}/tags", [
                'tag_ids' => [$tagB->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tag_ids.0']);
    }

    public function test_default_git_provider_toggle_does_not_unset_the_other_organizations_default(): void
    {
        $this->gitProviderB->update(['is_default' => true]);

        $this->actingAs($this->userA)
            ->postJson('/api/git-providers', [
                'name' => 'A provider',
                'type' => 'github',
                'access_token' => 'ghp_token',
                'is_default' => true,
            ])
            ->assertCreated();

        $this->assertTrue($this->gitProviderB->fresh()->is_default);
    }

    public function test_deployment_notifications_stay_inside_the_owning_organization(): void
    {
        Http::fake();

        // Channel for org A (context is bound to A in setUp).
        NotificationChannel::factory()->create();

        // Deployment in org B finishing must not touch A's Discord.
        CurrentOrganization::set($this->userB->currentOrganization, 'owner');
        Deployment::factory()->running()->create([
            'application_id' => $this->applicationB->id,
        ])->markAsSuccess();

        Http::assertNothingSent();
    }

    public function test_webhooks_still_resolve_applications_without_an_organization_context(): void
    {
        // The webhook route is public; the per-application secret is the
        // authorization and the binding must stay unscoped. Drop the
        // test-side context so the request runs like production (no
        // organization bound on a public route).
        Queue::fake();
        CurrentOrganization::forget();

        $this->postJson(
            "/api/webhook/{$this->applicationB->id}",
            [
                'object_kind' => 'push',
                'ref' => 'refs/heads/'.$this->applicationB->branch,
                'after' => str_repeat('a', 40),
                'commits' => [['message' => 'Test commit', 'author' => ['name' => 'Tester']]],
                'repository' => ['url' => 'git@gitlab.com:test/repo.git'],
            ],
            ['X-Gitlab-Token' => $this->applicationB->webhook_secret],
        )->assertOk();
    }
}
