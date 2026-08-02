<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deployment stream sits outside auth:sanctum like the other SSE
 * routes: ?token= authenticates manually and the {deployment} binding
 * resolves unscoped, so the controller enforces organization membership
 * by hand. This file only carries the soft-delete guard regression; the
 * membership/no-token cases are covered by StreamAuthIsolationTest.
 */
class DeploymentStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_refuses_rather_than_500s_when_the_deployment_server_is_soft_deleted(): void
    {
        // ServerController::destroy refuses to trash a server that still has
        // applications, so this path isn't reachable through the API today.
        // The guard belongs in the controller regardless, rather than
        // resting on that rule staying in place in a different file:
        // soft-deleting the server directly (bypassing the API guard, as a
        // deliberate escape hatch or a future rule change might) still must
        // not 500.
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $application = Application::factory()->create(['server_id' => $server->id]);
        $deployment = Deployment::factory()->create([
            'application_id' => $application->id,
            'status' => 'success',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $server->delete();

        // These routes are public (token comes via query param), so in
        // production no organization context is bound when the model
        // binding resolves; mirror that here. Left bound, Deployment's own
        // BelongsToOrganizationThroughParent scope would filter it out of
        // the *binding* itself (Server's soft-delete scope now hides the
        // row the scope's whereHas needs), producing a plain 404 instead of
        // exercising the null-safe chain inside stream() that this test
        // means to cover.
        CurrentOrganization::forget();

        $response = $this->get("/api/deployments/{$deployment->id}/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }
}
