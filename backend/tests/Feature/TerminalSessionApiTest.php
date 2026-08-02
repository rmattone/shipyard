<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Terminal\TerminalMessage;
use App\Services\TerminalService;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class TerminalSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_a_session(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/terminal-sessions",
            ['cols' => 120, 'rows' => 32]
        );

        $response->assertCreated()->assertJsonStructure(['id']);

        $this->assertDatabaseHas('terminal_sessions', [
            'id' => $response->json('id'),
            'user_id' => $user->id,
            'server_id' => $server->id,
            'status' => 'pending',
            'cols' => 120,
            'rows' => 32,
        ]);
    }

    public function test_member_cannot_open_a_session(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_MEMBER);
        $server = Server::factory()->create();

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/terminal-sessions",
            ['cols' => 80, 'rows' => 24]
        )->assertForbidden();
    }

    public function test_cannot_open_for_another_organizations_server(): void
    {
        $this->createOrgUser();
        $foreignServer = Server::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
        ]);

        CurrentOrganization::forget();
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);

        $this->actingAs($user)->postJson(
            "/api/servers/{$foreignServer->id}/terminal-sessions",
            ['cols' => 80, 'rows' => 24]
        )->assertNotFound();
    }

    public function test_local_server_is_refused(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $server = Server::factory()->create(['is_local' => true, 'private_key' => null]);

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/terminal-sessions",
            ['cols' => 80, 'rows' => 24]
        )->assertStatus(422);
    }

    public function test_concurrency_cap_returns_429_but_stale_sessions_are_reaped(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $server = Server::factory()->create();

        TerminalSession::factory()->count(TerminalService::MAX_CONCURRENT)->create([
            'server_id' => $server->id,
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/terminal-sessions",
            ['cols' => 80, 'rows' => 24]
        )->assertStatus(429);

        // Kill the workers: sessions go stale, the cap frees up.
        TerminalSession::query()->update(['last_seen_at' => now()->subSeconds(120)]);

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/terminal-sessions",
            ['cols' => 80, 'rows' => 24]
        )->assertCreated();
    }

    public function test_input_pushes_encoded_keystrokes_to_the_session_list(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        Redis::shouldReceive('lpush')
            ->once()
            ->withArgs(function (string $key, string $payload) use ($session) {
                return $key === "terminal:{$session->id}:input"
                    && TerminalMessage::decode($payload) === ['t' => 'i', 'd' => base64_encode('ls -la')];
            })
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/input",
            ['d' => base64_encode('ls -la')]
        )->assertOk();
    }

    public function test_input_to_another_users_session_is_not_found(): void
    {
        $owner = User::factory()->create();
        $session = TerminalSession::factory()->create([
            'user_id' => $owner->id,
            'status' => 'active',
        ]);

        CurrentOrganization::forget();
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/input",
            ['d' => base64_encode('whoami')]
        )->assertNotFound();
    }

    public function test_input_to_an_ended_session_conflicts(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'ended',
        ]);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/input",
            ['d' => base64_encode('x')]
        )->assertStatus(409);
    }

    public function test_resize_pushes_resize_message(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        Redis::shouldReceive('lpush')
            ->once()
            ->withArgs(fn (string $key, string $payload) => TerminalMessage::decode($payload) === ['t' => 'r', 'c' => 100, 'r' => 40])
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/resize",
            ['cols' => 100, 'rows' => 40]
        )->assertOk();
    }

    public function test_close_pushes_close_message_for_active_session(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        Redis::shouldReceive('lpush')
            ->once()
            ->withArgs(fn (string $key, string $payload) => TerminalMessage::decode($payload) === ['t' => 'c'])
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/close"
        )->assertOk();
    }

    public function test_close_ends_a_pending_session_directly(): void
    {
        $user = $this->createOrgUser(Organization::ROLE_ADMIN);
        $session = TerminalSession::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user)->postJson(
            "/api/terminal-sessions/{$session->id}/close"
        )->assertOk();

        $fresh = $session->fresh();
        $this->assertSame('ended', $fresh->status);
        $this->assertSame('closed_by_user', $fresh->ended_reason);
    }
}
