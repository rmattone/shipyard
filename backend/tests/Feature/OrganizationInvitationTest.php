<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->createOrgUser();
        $this->organization = $this->owner->currentOrganization;
    }

    private function invite(string $email = 'new@example.com', string $role = 'member'): OrganizationInvitation
    {
        $this->actingAs($this->owner)
            ->postJson("/api/organizations/{$this->organization->id}/invitations", [
                'email' => $email,
                'role' => $role,
            ])
            ->assertCreated()
            ->assertJsonStructure(['token', 'accept_url']);

        return $this->organization->invitations()->where('email', $email)->firstOrFail();
    }

    public function test_owner_can_invite_and_the_response_carries_the_accept_url(): void
    {
        $invitation = $this->invite();

        $this->assertSame('member', $invitation->role);
        $this->assertFalse($invitation->isExpired());
    }

    public function test_cannot_invite_an_existing_member_and_reinvite_rotates_the_token(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/organizations/{$this->organization->id}/invitations", [
                'email' => $this->owner->email,
                'role' => 'member',
            ])
            ->assertUnprocessable();

        $first = $this->invite('new@example.com');
        $second = $this->invite('new@example.com', 'admin');

        $this->assertSame(1, $this->organization->invitations()->count());
        $this->assertNotSame($first->token, $second->token);
        $this->assertSame('admin', $second->role);
    }

    public function test_owner_cannot_grant_ownership_by_invitation(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/organizations/{$this->organization->id}/invitations", [
                'email' => 'new@example.com',
                'role' => 'owner',
            ])
            ->assertUnprocessable();
    }

    public function test_only_owners_manage_invitations(): void
    {
        $member = User::factory()->create();
        $member->organizations()->attach($this->organization, ['role' => Organization::ROLE_MEMBER]);

        $this->actingAs($member)
            ->getJson("/api/organizations/{$this->organization->id}/invitations")
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson("/api/organizations/{$this->organization->id}/invitations", [
                'email' => 'x@example.com',
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_show_reveals_the_invitation_details_by_token(): void
    {
        $invitation = $this->invite();

        $this->getJson("/api/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJson([
                'organization' => $this->organization->name,
                'email' => 'new@example.com',
                'role' => 'member',
                'expired' => false,
                'existing_user' => false,
            ]);

        $this->getJson('/api/invitations/not-a-real-token')
            ->assertNotFound();
    }

    public function test_accept_registers_a_new_user_and_returns_a_login_token(): void
    {
        $invitation = $this->invite();

        $response = $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'New Person',
            'password' => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
        ])
            ->assertCreated()
            ->assertJsonStructure(['user', 'token', 'organization']);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('member', $user->roleIn($this->organization));
        $this->assertSame($this->organization->id, $user->current_organization_id);
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);

        // The returned token must actually authenticate (reset the
        // guards actingAs() left behind so the bearer header is used).
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/auth/user', [
            'Authorization' => 'Bearer '.$response->json('token'),
        ])->assertOk()->assertJsonPath('email', 'new@example.com');
    }

    public function test_accept_for_an_existing_account_requires_being_logged_in_as_the_invitee(): void
    {
        $invitee = $this->createOrgUser();
        $invitation = $this->invite($invitee->email, 'admin');

        // Anonymous: rejected.
        $this->postJson("/api/invitations/{$invitation->token}/accept")
            ->assertForbidden();

        // Wrong user: rejected.
        $impostor = $this->createOrgUser();
        $this->actingAs($impostor)
            ->postJson("/api/invitations/{$invitation->token}/accept")
            ->assertForbidden();

        // The invitee: joins with the invited role and switches into it.
        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->token}/accept")
            ->assertOk();

        $this->assertSame('admin', $invitee->roleIn($this->organization));
        $this->assertSame($this->organization->id, $invitee->fresh()->current_organization_id);
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $invitation = OrganizationInvitation::factory()
            ->expired()
            ->create(['organization_id' => $this->organization->id]);

        $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Too Late',
            'password' => 'super-secret-password',
            'password_confirmation' => 'super-secret-password',
        ])->assertStatus(410);

        $this->getJson("/api/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJsonPath('expired', true);
    }

    public function test_owner_can_revoke_a_pending_invitation(): void
    {
        $invitation = $this->invite();

        $this->actingAs($this->owner)
            ->deleteJson("/api/organizations/{$this->organization->id}/invitations/{$invitation->id}")
            ->assertOk();

        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->id]);
    }
}
