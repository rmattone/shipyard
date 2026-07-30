<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The SSE routes authenticate a ?token= query param by hand, outside
 * auth:sanctum. These tests lock in that the shared resolver applies the
 * same expiry rules as Sanctum's guard: the per-row expires_at column and
 * the global sanctum.expiration window over created_at.
 */
class StreamTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createOrgUser();
        $server = Server::factory()->create();
        $application = Application::factory()->create(['server_id' => $server->id]);
        $this->deployment = Deployment::factory()->create([
            'application_id' => $application->id,
            'status' => 'success',
        ]);

        // Mirror production: no organization context on these public routes.
        CurrentOrganization::forget();
    }

    private function streamWith(string $token): TestResponse
    {
        return $this->get("/api/deployments/{$this->deployment->id}/stream?token={$token}");
    }

    public function test_expired_token_is_rejected(): void
    {
        $plain = $this->user->createToken('test', ['*'], now()->subMinute())->plainTextToken;

        $this->streamWith($plain)->assertStatus(401);
    }

    public function test_legacy_token_older_than_the_config_window_is_rejected(): void
    {
        config(['sanctum.expiration' => 60]);

        $plain = $this->user->createToken('test')->plainTextToken;
        PersonalAccessToken::findToken($plain)->forceFill([
            'expires_at' => null,
            'created_at' => now()->subMinutes(61),
        ])->save();

        $this->streamWith($plain)->assertStatus(401);
    }

    public function test_fresh_token_streams(): void
    {
        config(['sanctum.expiration' => 60]);

        $plain = $this->user->createToken('test', ['*'], now()->addMinutes(60))->plainTextToken;

        $response = $this->streamWith($plain);

        $response->assertOk();
        $this->assertStringContainsString('connected', $response->streamedContent());
    }
}
