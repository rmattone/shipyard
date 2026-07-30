<?php

namespace Tests\Feature;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthAccountTest extends TestCase
{
    use RefreshDatabase;

    // ---- Token expiry -------------------------------------------------

    public function test_token_expiration_window_is_configured(): void
    {
        $this->assertNotNull(
            config('sanctum.expiration'),
            'sanctum.expiration must have a non-null default so login tokens expire.'
        );
    }

    public function test_login_issues_a_token_with_expires_at_set_from_config(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $user->tokens()->latest('id')->first();

        $this->assertNotNull($token->expires_at);
        $this->assertEqualsWithDelta(
            now()->addMinutes(60)->timestamp,
            $token->expires_at->timestamp,
            5
        );
    }

    public function test_invitation_accept_issues_a_token_with_expires_at_set(): void
    {
        config(['sanctum.expiration' => 60]);

        $invitation = OrganizationInvitation::factory()->create();

        $this->postJson("/api/invitations/{$invitation->token}/accept", [
            'name' => 'Invited User',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertCreated();

        $token = User::where('email', $invitation->email)->firstOrFail()
            ->tokens()->latest('id')->first();

        $this->assertNotNull($token->expires_at);
    }

    public function test_legacy_token_without_expires_at_is_bounded_by_the_config_window(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $plain = $user->createToken('auth-token')->plainTextToken;

        // Pre-expiry rows have expires_at NULL; only created_at bounds them.
        PersonalAccessToken::findToken($plain)->forceFill([
            'expires_at' => null,
            'created_at' => now()->subMinutes(61),
        ])->save();

        $this->withToken($plain)->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_fresh_token_authenticates(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create();
        $plain = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($plain)->getJson('/api/auth/user')->assertOk();
    }

    // ---- Profile ------------------------------------------------------

    public function test_user_can_update_name_and_email(): void
    {
        $user = $this->createOrgUser();

        $response = $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => 'New Name',
            'email' => 'new-email@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', 'new-email@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
            'email' => 'new-email@example.com',
        ]);
    }

    public function test_profile_email_must_be_unique_across_users(): void
    {
        $other = User::factory()->create();
        $user = $this->createOrgUser();

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => $user->name,
            'email' => $other->email,
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_profile_accepts_keeping_the_current_email(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => 'Renamed Only',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('user.name', 'Renamed Only');
    }

    public function test_profile_requires_authentication(): void
    {
        $this->putJson('/api/auth/profile', [
            'name' => 'Nope',
            'email' => 'nope@example.com',
        ])->assertUnauthorized();
    }

    // ---- Password change ----------------------------------------------

    public function test_password_change_rejects_a_wrong_current_password(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'not-the-password',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_rejects_a_weak_new_password(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_password_change_allows_login_with_the_new_password(): void
    {
        $user = $this->createOrgUser();

        $token = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'a-new-strong-password',
        ])->assertOk();
    }

    public function test_password_change_revokes_every_other_token_but_keeps_the_current(): void
    {
        $user = $this->createOrgUser();

        $other = $user->createToken('auth-token')->plainTextToken;
        $current = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($current)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'a-new-strong-password',
            'password_confirmation' => 'a-new-strong-password',
        ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        // The sanctum guard caches the resolved user across in-test
        // requests; drop it so each token is re-evaluated.
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/auth/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/auth/user')->assertUnauthorized();
    }

    // ---- Logout others --------------------------------------------------

    public function test_logout_others_revokes_every_token_but_the_current(): void
    {
        $user = $this->createOrgUser();

        $other = $user->createToken('auth-token')->plainTextToken;
        $current = $user->createToken('auth-token')->plainTextToken;

        $this->withToken($current)->postJson('/api/auth/logout-others')->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/auth/user')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/auth/user')->assertUnauthorized();
    }

    public function test_logout_others_requires_authentication(): void
    {
        $this->postJson('/api/auth/logout-others')->assertUnauthorized();
    }
}
