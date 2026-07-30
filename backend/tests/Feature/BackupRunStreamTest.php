<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
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
