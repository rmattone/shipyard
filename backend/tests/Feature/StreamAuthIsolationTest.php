<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DatabaseInstallation;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SSE routes sit outside auth:sanctum (EventSource can't send
 * headers, so a ?token= query param authenticates manually). The model
 * binding therefore resolves without organization scoping and the
 * controllers enforce membership explicitly; these tests lock that in.
 */
class StreamAuthIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;

    private Deployment $deploymentB;

    private DatabaseInstallation $installationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createOrgUser();
        $serverB = Server::factory()->create();
        $applicationB = Application::factory()->create(['server_id' => $serverB->id]);
        $this->deploymentB = Deployment::factory()->create([
            'application_id' => $applicationB->id,
            'status' => 'success',
        ]);
        $this->installationB = DatabaseInstallation::factory()->create([
            'server_id' => $serverB->id,
            'status' => 'success',
        ]);

        $this->userA = $this->createOrgUser();

        // These routes are public (token comes via query param), so in
        // production no organization context is bound when the model
        // binding resolves; mirror that here.
        CurrentOrganization::forget();
    }

    public function test_deployment_stream_rejects_users_from_another_organization(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;

        $response = $this->get("/api/deployments/{$this->deploymentB->id}/stream?token={$token}");

        $response->assertStatus(403);
        $body = $response->streamedContent();
        $this->assertStringContainsString('Unauthorized', $body);
        $this->assertStringNotContainsString('log', $body);
    }

    public function test_installation_stream_rejects_users_from_another_organization(): void
    {
        $token = $this->userA->createToken('test')->plainTextToken;

        $response = $this->get("/api/database-installations/{$this->installationB->id}/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }

    public function test_stream_rejects_missing_token(): void
    {
        $this->get("/api/deployments/{$this->deploymentB->id}/stream")
            ->assertStatus(401);
    }
}
