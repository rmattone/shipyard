<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrganizationRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $member;

    private Server $server;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createOrgUser();
        $organization = $this->owner->currentOrganization;

        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($organization, ['role' => Organization::ROLE_ADMIN]);
        $this->admin->forceFill(['current_organization_id' => $organization->id])->save();

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($organization, ['role' => Organization::ROLE_MEMBER]);
        $this->member->forceFill(['current_organization_id' => $organization->id])->save();

        $this->server = Server::factory()->create();
        $this->application = Application::factory()->create(['server_id' => $this->server->id]);
    }

    public function test_members_can_read_everything_in_their_organization(): void
    {
        $this->actingAs($this->member)->getJson('/api/servers')->assertOk();
        $this->actingAs($this->member)->getJson("/api/servers/{$this->server->id}")->assertOk();
        $this->actingAs($this->member)->getJson("/api/applications/{$this->application->id}")->assertOk();
        $this->actingAs($this->member)->getJson("/api/applications/{$this->application->id}/deployments")->assertOk();
    }

    public function test_members_cannot_write_resources(): void
    {
        $this->actingAs($this->member)
            ->postJson('/api/servers', ['name' => 'x', 'host' => '1.2.3.4', 'username' => 'root'])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->putJson("/api/applications/{$this->application->id}", ['name' => 'renamed'])
            ->assertForbidden();

        $this->actingAs($this->member)
            ->deleteJson("/api/servers/{$this->server->id}")
            ->assertForbidden();

        $this->actingAs($this->member)
            ->postJson("/api/applications/{$this->application->id}/env", ['key' => 'A', 'value' => 'b'])
            ->assertForbidden();
    }

    public function test_members_can_trigger_deployments(): void
    {
        Queue::fake();

        $this->actingAs($this->member)
            ->postJson("/api/applications/{$this->application->id}/deploy")
            ->assertOk();

        Queue::assertPushed(ProcessDeployment::class);
    }

    public function test_admins_can_write_resources_but_not_manage_the_organization(): void
    {
        $organizationId = $this->owner->current_organization_id;

        $this->actingAs($this->admin)
            ->putJson("/api/applications/{$this->application->id}", ['name' => 'renamed'])
            ->assertOk();

        $this->actingAs($this->admin)
            ->putJson("/api/organizations/{$organizationId}", ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/api/organizations/{$organizationId}/invitations", [
                'email' => 'x@example.com',
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_system_routes_are_owner_only(): void
    {
        $this->actingAs($this->member)->getJson('/api/system/version')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/system/version')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/system/version')->assertOk();
    }

    public function test_members_can_still_switch_organizations_and_leave(): void
    {
        $other = $this->createOrgUser();
        $otherOrg = $other->currentOrganization;
        $otherOrg->users()->attach($this->member, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($this->member)
            ->postJson("/api/organizations/{$otherOrg->id}/switch")
            ->assertOk();

        $this->actingAs($this->member)
            ->deleteJson("/api/organizations/{$otherOrg->id}/members/{$this->member->id}")
            ->assertOk();
    }
}
