<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeTrashedServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOrgUser();
    }

    public function test_purges_servers_past_the_retention_window(): void
    {
        $expired = Server::factory()->create();
        $expired->delete();
        $expired->forceFill([
            'deleted_at' => now()->subDays(Server::TRASH_RETENTION_DAYS + 1),
        ])->saveQuietly();

        $this->artisan('servers:purge-trashed')->assertSuccessful();

        $this->assertDatabaseMissing('servers', ['id' => $expired->id]);
    }

    public function test_spares_servers_still_inside_the_retention_window(): void
    {
        $recent = Server::factory()->create();
        $recent->delete();
        $recent->forceFill([
            'deleted_at' => now()->subDays(Server::TRASH_RETENTION_DAYS - 1),
        ])->saveQuietly();

        $this->artisan('servers:purge-trashed')->assertSuccessful();

        $this->assertSoftDeleted('servers', ['id' => $recent->id]);
    }

    public function test_leaves_live_servers_alone(): void
    {
        $live = Server::factory()->create();

        $this->artisan('servers:purge-trashed')->assertSuccessful();

        $this->assertDatabaseHas('servers', ['id' => $live->id, 'deleted_at' => null]);
    }

    public function test_purging_cascades_to_child_rows(): void
    {
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);

        $server->delete();
        $server->forceFill([
            'deleted_at' => now()->subDays(Server::TRASH_RETENTION_DAYS + 1),
        ])->saveQuietly();

        $this->artisan('servers:purge-trashed')->assertSuccessful();

        $this->assertDatabaseMissing('databases', ['id' => $database->id]);
    }
}
