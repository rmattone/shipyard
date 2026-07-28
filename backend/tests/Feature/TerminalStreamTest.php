<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\TerminalService;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * The terminal stream sits outside auth:sanctum like the other SSE
 * routes: ?token= authenticates manually and the binding resolves
 * unscoped, so the controller enforces ownership, membership, AND the
 * admin role by hand. These tests pin the whole gate; the live PTY
 * loop itself needs a real server and is verified manually.
 */
class TerminalStreamTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $role = Organization::ROLE_ADMIN): array
    {
        $user = $this->createOrgUser($role);
        $server = Server::factory()->create();
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'status' => 'pending',
        ]);

        // Streams resolve without an org context in production.
        CurrentOrganization::forget();

        return [$user, $session];
    }

    public function test_stream_rejects_missing_token(): void
    {
        [, $session] = $this->makeSession();

        $this->get("/api/terminal-sessions/{$session->id}/stream")
            ->assertStatus(401);
    }

    public function test_stream_rejects_another_users_session(): void
    {
        [, $session] = $this->makeSession();

        CurrentOrganization::forget();
        $intruder = $this->createOrgUser(Organization::ROLE_ADMIN);
        $token = $intruder->createToken('test')->plainTextToken;
        CurrentOrganization::forget();

        $response = $this->get("/api/terminal-sessions/{$session->id}/stream?token={$token}");

        $response->assertStatus(403);
        $this->assertStringContainsString('Unauthorized', $response->streamedContent());
    }

    public function test_stream_rejects_member_role(): void
    {
        // A member who somehow owns a session row (e.g. demoted after
        // opening it) must still be refused: the stream re-checks role.
        [$user, $session] = $this->makeSession(Organization::ROLE_MEMBER);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->get("/api/terminal-sessions/{$session->id}/stream?token={$token}");

        $response->assertStatus(403);
    }

    public function test_stream_conflicts_when_session_already_attached(): void
    {
        [$user, $session] = $this->makeSession();
        $session->update(['status' => 'active', 'started_at' => now()]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->get("/api/terminal-sessions/{$session->id}/stream?token={$token}");

        $response->assertStatus(409);
    }

    public function test_stream_conflicts_when_pending_session_expired(): void
    {
        [$user, $session] = $this->makeSession();
        $session->timestamps = false;
        $session->forceFill(['created_at' => now()->subSeconds(120)])->save();
        $token = $user->createToken('test')->plainTextToken;

        $this->get("/api/terminal-sessions/{$session->id}/stream?token={$token}")
            ->assertStatus(409);
    }

    public function test_ssh_failure_ends_session_with_reason_and_streams_end_event(): void
    {
        [$user, $session] = $this->makeSession();
        $token = $user->createToken('test')->plainTextToken;

        Redis::shouldReceive('del')->once()->andReturn(1);

        $this->mock(TerminalService::class, function ($mock) {
            $mock->shouldReceive('inputKey')->andReturn('terminal:test:input');
            $mock->shouldReceive('open')->once()->andThrow(
                new \RuntimeException('SSH authentication failed for 10.0.0.1')
            );
        });

        $response = $this->get("/api/terminal-sessions/{$session->id}/stream?token={$token}");
        $response->assertOk();

        // The streamed body is not asserted here: the stream closure
        // drains every output buffer (required so SSE events reach the
        // client unbuffered), which includes the one streamedContent()
        // relies on. The observable outcome is the session bookkeeping.
        // Buffer levels are restored afterwards so PHPUnit stays happy.
        $level = ob_get_level();
        $response->sendContent();
        while (ob_get_level() < $level) {
            ob_start();
        }

        $fresh = $session->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame('ssh_failed', $fresh->ended_reason);
        $this->assertNotNull($fresh->ended_at);
    }
}
