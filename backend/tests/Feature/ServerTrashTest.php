<?php

namespace Tests\Feature;

use App\Models\Daemon;
use App\Models\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting a server drops it into a trash it can be restored from for
 * TRASH_RETENTION_DAYS. The restore and force-delete routes bind
 * withTrashed(), which bypasses the soft-delete scope but must not bypass
 * the organization scope or the admin role gate.
 */
class ServerTrashTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createOrgUser();
    }

    public function test_deleting_a_server_moves_it_to_the_trash(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('servers', ['id' => $server->id]);
    }

    public function test_trashed_server_leaves_the_index_and_appears_in_the_trash(): void
    {
        $kept = Server::factory()->create();
        $trashed = Server::factory()->create();
        $trashed->delete();

        $this->actingAs($this->user)
            ->getJson('/api/servers')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $kept->id);

        $this->actingAs($this->user)
            ->getJson('/api/servers/trashed')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $trashed->id);
    }

    public function test_trashed_server_exposes_when_it_will_be_purged(): void
    {
        $server = Server::factory()->create();
        $server->delete();

        $response = $this->actingAs($this->user)
            ->getJson('/api/servers/trashed')
            ->assertOk();

        $expected = $server->fresh()->deleted_at
            ->copy()
            ->addDays(Server::TRASH_RETENTION_DAYS)
            ->toIso8601String();

        $this->assertSame($expected, $response->json('0.purges_at'));
    }

    public function test_show_does_not_resolve_a_trashed_server(): void
    {
        $server = Server::factory()->create();
        $server->delete();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$server->id}")
            ->assertNotFound();
    }

    public function test_restoring_returns_the_server_with_its_children_intact(): void
    {
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $daemon = Daemon::factory()->create(['server_id' => $server->id]);

        $server->delete();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/restore")
            ->assertOk()
            ->assertJsonPath('id', $server->id);

        $this->assertDatabaseHas('servers', ['id' => $server->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('databases', ['id' => $database->id]);
        $this->assertDatabaseHas('daemons', ['id' => $daemon->id]);
    }

    public function test_cannot_restore_a_server_that_is_not_trashed(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/restore")
            ->assertUnprocessable();
    }

    public function test_server_with_applications_is_not_trashed(): void
    {
        $server = Server::factory()->hasApplications(1)->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('servers', ['id' => $server->id, 'deleted_at' => null]);
    }

    public function test_force_delete_removes_the_row_and_cascades_to_children(): void
    {
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $server->delete();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}/force")
            ->assertNoContent();

        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseMissing('databases', ['id' => $database->id]);
    }

    public function test_cannot_force_delete_a_server_that_is_not_trashed(): void
    {
        $server = Server::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$server->id}/force")
            ->assertUnprocessable();

        $this->assertDatabaseHas('servers', ['id' => $server->id]);
    }

    public function test_members_of_the_same_organization_cannot_delete_restore_or_purge(): void
    {
        $organization = $this->user->currentOrganization;

        $member = User::factory()->create();
        $member->organizations()->attach($organization, ['role' => Organization::ROLE_MEMBER]);
        $member->forceFill(['current_organization_id' => $organization->id])->save();

        $live = Server::factory()->create();
        $trashed = Server::factory()->create();
        $trashed->delete();

        $this->actingAs($member)
            ->deleteJson("/api/servers/{$live->id}")
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson("/api/servers/{$trashed->id}/restore")
            ->assertForbidden();

        $this->actingAs($member)
            ->deleteJson("/api/servers/{$trashed->id}/force")
            ->assertForbidden();

        $this->assertDatabaseHas('servers', ['id' => $live->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('servers', ['id' => $trashed->id]);
    }

    public function test_another_organization_cannot_see_or_touch_trashed_servers(): void
    {
        // Org B's trashed server, created while the context is bound to B,
        // then a fresh org A owner so the context ends bound to A.
        $this->createOrgUser();
        $serverB = Server::factory()->create();
        $serverB->delete();

        $userA = $this->createOrgUser();

        $this->actingAs($userA)
            ->getJson('/api/servers/trashed')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($userA)
            ->postJson("/api/servers/{$serverB->id}/restore")
            ->assertNotFound();

        $this->actingAs($userA)
            ->deleteJson("/api/servers/{$serverB->id}/force")
            ->assertNotFound();

        $this->assertSoftDeleted('servers', ['id' => $serverB->id]);
    }
}
