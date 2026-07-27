<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_the_users_organizations_with_role(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'id' => $user->current_organization_id,
                'role' => 'owner',
            ]);
    }

    public function test_creating_an_organization_makes_the_creator_its_owner_and_switches_into_it(): void
    {
        $user = $this->createOrgUser();

        $response = $this->actingAs($user)
            ->postJson('/api/organizations', ['name' => 'Acme'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Acme', 'role' => 'owner']);

        $user->refresh();
        $this->assertSame($response->json('id'), $user->current_organization_id);
        $this->assertSame(2, $user->organizations()->count());
    }

    public function test_auth_user_payload_includes_organizations_and_current_organization(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonStructure([
                'id', 'name', 'email',
                'organizations' => [['id', 'name', 'role']],
                'current_organization' => ['id', 'name', 'role'],
            ])
            ->assertJsonPath('current_organization.id', $user->current_organization_id);
    }

    public function test_switching_changes_the_current_organization_and_the_visible_resources(): void
    {
        // Org B with a server.
        $userB = $this->createOrgUser();
        $serverB = Server::factory()->create();

        // User A, member of both organizations.
        $userA = $this->createOrgUser();
        $serverA = Server::factory()->create();
        $orgB = $userB->currentOrganization;
        $userA->organizations()->attach($orgB, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($userA)
            ->getJson('/api/servers')
            ->assertOk()
            ->assertJsonFragment(['id' => $serverA->id])
            ->assertJsonMissing(['id' => $serverB->id]);

        $this->actingAs($userA)
            ->postJson("/api/organizations/{$orgB->id}/switch")
            ->assertOk()
            ->assertJsonFragment(['id' => $orgB->id, 'role' => 'member']);

        $this->actingAs($userA)
            ->getJson('/api/servers')
            ->assertOk()
            ->assertJsonFragment(['id' => $serverB->id])
            ->assertJsonMissing(['id' => $serverA->id]);
    }

    public function test_cannot_switch_into_an_organization_you_do_not_belong_to(): void
    {
        $stranger = $this->createOrgUser();
        $strangerOrg = $stranger->currentOrganization;

        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->postJson("/api/organizations/{$strangerOrg->id}/switch")
            ->assertNotFound();

        $this->assertNotSame($strangerOrg->id, $user->fresh()->current_organization_id);
    }

    public function test_only_owners_can_rename_and_members_of_other_orgs_get_404(): void
    {
        $owner = $this->createOrgUser();
        $organization = $owner->currentOrganization;

        $member = User::factory()->create();
        $member->organizations()->attach($organization, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($member)
            ->putJson("/api/organizations/{$organization->id}", ['name' => 'Nope'])
            ->assertForbidden();

        $stranger = $this->createOrgUser();
        $this->actingAs($stranger)
            ->putJson("/api/organizations/{$organization->id}", ['name' => 'Nope'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->putJson("/api/organizations/{$organization->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $organization->fresh()->name);
    }

    public function test_cannot_delete_an_organization_that_still_owns_servers(): void
    {
        $owner = $this->createOrgUser();
        Server::factory()->create();

        $this->actingAs($owner)
            ->deleteJson('/api/organizations/'.$owner->current_organization_id)
            ->assertUnprocessable();
    }

    public function test_deleting_an_empty_organization_works_and_falls_back_to_another_membership(): void
    {
        $owner = $this->createOrgUser();
        $original = $owner->currentOrganization;

        $second = $this->actingAs($owner)
            ->postJson('/api/organizations', ['name' => 'Second'])
            ->json('id');

        $this->actingAs($owner)
            ->deleteJson("/api/organizations/{$second}")
            ->assertOk();

        $this->assertDatabaseMissing('organizations', ['id' => $second]);

        // current_organization_id was nulled by the FK; the middleware
        // falls back to the surviving membership on the next request.
        $this->actingAs($owner)
            ->getJson('/api/servers')
            ->assertOk();

        $this->assertSame($original->id, $owner->fresh()->current_organization_id);
    }

    public function test_a_user_with_no_organizations_gets_403_on_scoped_routes_but_can_still_bootstrap(): void
    {
        $user = User::factory()->withoutOrganization()->create();

        $this->actingAs($user)
            ->getJson('/api/servers')
            ->assertForbidden();

        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('current_organization', null);

        $this->actingAs($user)
            ->postJson('/api/organizations', ['name' => 'Fresh start'])
            ->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/servers')
            ->assertOk();
    }
}
