<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\TerminalSession;
use App\Services\TerminalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TerminalSessionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_ended_records_reason_and_timestamp(): void
    {
        $this->createOrgUser();
        $session = TerminalSession::factory()->create(['status' => 'active']);

        $session->markEnded('shell_exited');

        $fresh = $session->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame('shell_exited', $fresh->ended_reason);
        $this->assertNotNull($fresh->ended_at);
    }

    public function test_open_refuses_local_servers(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create(['is_local' => true, 'private_key' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('local');

        app(TerminalService::class)->open($server, 80, 24);
    }

    public function test_input_key_is_session_scoped(): void
    {
        $this->assertSame('terminal:42:input', app(TerminalService::class)->inputKey(42));
    }

    public function test_reap_stale_ends_abandoned_sessions_only(): void
    {
        $this->createOrgUser();

        $abandonedPending = TerminalSession::factory()->create([
            'status' => 'pending',
            'created_at' => now()->subSeconds(120),
        ]);
        $freshPending = TerminalSession::factory()->create(['status' => 'pending']);
        $deadActive = TerminalSession::factory()->create([
            'status' => 'active',
            'last_seen_at' => now()->subSeconds(120),
        ]);
        $liveActive = TerminalSession::factory()->create([
            'status' => 'active',
            'last_seen_at' => now()->subSeconds(5),
        ]);

        app(TerminalService::class)->reapStale();

        $this->assertSame('ended', $abandonedPending->fresh()->status);
        $this->assertSame('never_attached', $abandonedPending->fresh()->ended_reason);
        $this->assertSame('pending', $freshPending->fresh()->status);
        $this->assertSame('ended', $deadActive->fresh()->status);
        $this->assertSame('stale', $deadActive->fresh()->ended_reason);
        $this->assertSame('active', $liveActive->fresh()->status);
    }
}
