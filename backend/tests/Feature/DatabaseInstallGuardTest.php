<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseInstallation;
use App\Models\Database;
use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Models\User;
use App\Services\DatabaseInstallationService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Re-running an engine install against a server that already has that engine
 * used to run the whole install again (resetting the root password on the
 * server) and then blow up on the databases unique key, leaving the stored
 * credentials pointing at a password that no longer exists.
 */
class DatabaseInstallGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_mysql_install_is_rejected_when_server_already_has_mysql(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        Database::factory()->create(['server_id' => $server->id, 'type' => 'mysql']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/databases/install", ['engine' => 'mysql']);

        $response->assertStatus(409);
        $this->assertStringContainsStringIgnoringCase(
            'already installed',
            $response->json('message')
        );
        $this->assertSame(0, DatabaseInstallation::count());
        Queue::assertNothingPushed();
    }

    public function test_postgresql_install_is_rejected_when_server_already_has_postgresql(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        Database::factory()->postgresql()->create(['server_id' => $server->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/databases/install", ['engine' => 'postgresql']);

        $response->assertStatus(409);
        $this->assertSame(0, DatabaseInstallation::count());
    }

    public function test_install_is_allowed_for_a_different_engine(): void
    {
        Queue::fake();
        $server = Server::factory()->create();
        Database::factory()->create(['server_id' => $server->id, 'type' => 'mysql']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/databases/install", ['engine' => 'postgresql']);

        $response->assertStatus(202);
        Queue::assertPushed(ProcessDatabaseInstallation::class);
    }

    public function test_install_is_allowed_on_another_server_with_the_same_engine(): void
    {
        Queue::fake();
        $other = Server::factory()->create();
        Database::factory()->create(['server_id' => $other->id, 'type' => 'mysql']);
        $server = Server::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$server->id}/databases/install", ['engine' => 'mysql']);

        $response->assertStatus(202);
    }

    // Even if an install slips past the endpoint guard (race, record created
    // by detect), a completed server install must sync the fresh credentials
    // into the existing record rather than fail on the unique key.
    public function test_completed_install_updates_existing_record_instead_of_failing(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                if (str_contains($command, 'os-release')) {
                    return ['output' => 'ID=ubuntu', 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, 'is-active')) {
                    return ['output' => 'active', 'exit_code' => 0, 'success' => true];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });

        $server = Server::factory()->create();
        $existing = Database::factory()->create([
            'server_id' => $server->id,
            'name' => 'MySQL',
            'type' => 'mysql',
            'admin_password' => 'stale-password',
        ]);
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => $server->id,
            'engine' => 'mysql',
            'status' => 'pending',
        ]);

        app(DatabaseInstallationService::class)->install($installation);

        $this->assertSame('success', $installation->fresh()->status);
        $this->assertSame(1, Database::where('server_id', $server->id)->count());
        $this->assertNotSame('stale-password', $existing->fresh()->admin_password);
    }
}
