<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRunStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stream_rejects_a_request_without_a_token(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $response = $this->get("/api/backup-runs/{$run->id}/stream");

        $response->assertStatus(401);
    }

    public function test_the_stream_rejects_a_token_from_another_organization(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'running']);

        $outsider = User::factory()->create();
        $token = $outsider->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(403);
    }

    public function test_the_stream_rejects_a_member_of_the_owning_organization(): void
    {
        $user = $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'running']);

        $organization = $user->currentOrganization;
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => Organization::ROLE_MEMBER]);
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(403);
    }

    public function test_the_stream_refuses_rather_than_500s_when_the_run_server_is_soft_deleted(): void
    {
        // A database-only server (no applications) can be trashed while its
        // Database and BackupRun rows survive: cascade deletion only fires
        // on force-delete, and ServerController::destroy's applications
        // guard doesn't look at databases at all. During that trash window
        // ->database->server resolves null through the soft-delete scope.
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $run = BackupRun::factory()->create(['database_id' => $database->id, 'status' => 'success']);
        $token = $user->createToken('test')->plainTextToken;

        $server->delete();

        // These routes are public (token comes via query param), so in
        // production no organization context is bound when the model
        // binding resolves; mirror that here. Left bound, the run's own
        // BelongsToOrganizationThroughParent scope would filter it out of
        // the *binding* itself (since Server's soft-delete scope now hides
        // the row the scope's whereHas needs), turning this into a 404
        // caught by ->missing() instead of exercising the null-safe chain
        // inside stream() that this test means to cover.
        CurrentOrganization::forget();

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }

    public function test_a_nonexistent_run_refuses_with_the_same_status_as_no_token(): void
    {
        $this->get('/api/backup-runs/999999/stream')
            ->assertStatus(401);
    }

    public function test_a_nonexistent_run_refuses_with_the_same_status_as_an_authenticated_denial(): void
    {
        $user = $this->createOrgUser();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/999999/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }

    public function test_a_finished_run_streams_its_log_and_closes(): void
    {
        $user = $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'success', 'log' => "all done\n"]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(200);
        // Symfony's Response::prepare() appends "; charset=utf-8" to any
        // text/* content type during the full HTTP kernel round trip, so an
        // exact-match assertion against 'text/event-stream' never passes here.
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('event: connected', $content);
        $this->assertStringContainsString('all done', $content);
        $this->assertStringContainsString('event: complete', $content);
    }
}
