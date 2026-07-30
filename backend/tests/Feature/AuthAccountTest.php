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
}
