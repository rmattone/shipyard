<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMemberTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createOrgUser();
        $this->organization = $this->owner->currentOrganization;

        $this->member = User::factory()->create();
        $this->member->organizations()->attach($this->organization, [
            'role' => Organization::ROLE_MEMBER,
        ]);
    }

    public function test_members_are_listed_with_their_roles(): void
    {
        $this->actingAs($this->owner)
            ->getJson("/api/organizations/{$this->organization->id}/members")
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $this->member->id, 'role' => 'member'])
            ->assertJsonFragment(['id' => $this->owner->id, 'role' => 'owner']);
    }

    public function test_owner_can_change_a_members_role(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/organizations/{$this->organization->id}/members/{$this->member->id}", [
                'role' => 'admin',
            ])
            ->assertOk()
            ->assertJsonFragment(['role' => 'admin']);

        $this->assertSame('admin', $this->member->roleIn($this->organization));
    }

    public function test_members_cannot_manage_roles(): void
    {
        $this->actingAs($this->member)
            ->putJson("/api/organizations/{$this->organization->id}/members/{$this->owner->id}", [
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->owner)
            ->putJson("/api/organizations/{$this->organization->id}/members/{$this->owner->id}", [
                'role' => 'member',
            ])
            ->assertUnprocessable();

        $this->actingAs($this->owner)
            ->deleteJson("/api/organizations/{$this->organization->id}/members/{$this->owner->id}")
            ->assertUnprocessable();
    }

    public function test_owner_can_remove_a_member_and_their_current_org_is_repointed(): void
    {
        $this->member->forceFill([
            'current_organization_id' => $this->organization->id,
        ])->save();

        $this->actingAs($this->owner)
            ->deleteJson("/api/organizations/{$this->organization->id}/members/{$this->member->id}")
            ->assertOk();

        $this->assertNull($this->member->fresh()->roleIn($this->organization));
        $this->assertNotSame($this->organization->id, $this->member->fresh()->current_organization_id);
    }

    public function test_a_member_can_leave_but_cannot_remove_someone_else(): void
    {
        $other = User::factory()->create();
        $other->organizations()->attach($this->organization, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($this->member)
            ->deleteJson("/api/organizations/{$this->organization->id}/members/{$other->id}")
            ->assertForbidden();

        $this->actingAs($this->member)
            ->deleteJson("/api/organizations/{$this->organization->id}/members/{$this->member->id}")
            ->assertOk();

        $this->assertNull($this->member->fresh()->roleIn($this->organization));
    }

    public function test_non_members_get_404_for_the_member_list(): void
    {
        $stranger = $this->createOrgUser();

        $this->actingAs($stranger)
            ->getJson("/api/organizations/{$this->organization->id}/members")
            ->assertNotFound();
    }
}
