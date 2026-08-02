<?php

namespace Tests\Feature;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The installation stream sits outside auth:sanctum like the other SSE
 * routes: ?token= authenticates manually and the {installation} binding
 * resolves unscoped, so the controller enforces organization membership
 * by hand. This file only carries the soft-delete guard regression; the
 * membership/no-token cases are covered by StreamAuthIsolationTest.
 */
class DatabaseInstallationStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_refuses_rather_than_500s_when_the_installation_server_is_soft_deleted(): void
    {
        // A database-only server (no applications) can be trashed while
        // this DatabaseInstallation row survives: cascade deletion only
        // fires on force-delete, and ServerController::destroy's
        // applications guard doesn't look at databases or installations at
        // all. ->server then resolves null through the soft-delete scope
        // for the whole trash retention window.
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => $server->id,
            'status' => 'success',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $server->delete();

        // These routes are public (token comes via query param), so in
        // production no organization context is bound when the model
        // binding resolves; mirror that here. Left bound,
        // DatabaseInstallation's own BelongsToOrganizationThroughParent
        // scope would filter it out of the *binding* itself (Server's
        // soft-delete scope now hides the row the scope's whereHas needs),
        // producing a plain 404 instead of exercising the null-safe chain
        // inside stream() that this test means to cover.
        CurrentOrganization::forget();

        $response = $this->get("/api/database-installations/{$installation->id}/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }
}
