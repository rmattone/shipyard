<?php

namespace Tests\Feature;

use App\Jobs\ProcessDaemonInstall;
use App\Jobs\ProcessDaemonRemoval;
use App\Models\Application;
use App\Models\Daemon;
use App\Models\Server;
use App\Models\User;
use App\Services\SystemdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DaemonApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->server = Server::factory()->create();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/daemons")
            ->assertUnauthorized();
    }

    public function test_index_lists_only_the_servers_daemons_and_filters_by_application(): void
    {
        $app = Application::factory()->create(['server_id' => $this->server->id]);
        Daemon::factory()->create(['server_id' => $this->server->id, 'application_id' => $app->id]);
        Daemon::factory()->create(['server_id' => $this->server->id]);
        Daemon::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/daemons")
            ->assertOk()
            ->assertJsonCount(2);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/daemons?application_id={$app->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['application_id' => $app->id]);
    }

    public function test_create_dispatches_the_install_job(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons", [
                'command' => 'php8.3 artisan queue:work redis --tries=3',
                'user' => 'www-data',
                'directory' => '/var/www/app/current',
                'processes' => 2,
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['status' => 'installing', 'processes' => 2]);

        Queue::assertPushed(ProcessDaemonInstall::class);
    }

    public function test_create_defaults_the_directory_from_the_linked_application(): void
    {
        Queue::fake();

        $atomicApp = Application::factory()->create([
            'server_id' => $this->server->id,
            'deployment_strategy' => 'atomic',
            'deploy_path' => '/var/www/shipyard/atomic-app',
        ]);
        $inPlaceApp = Application::factory()->create([
            'server_id' => $this->server->id,
            'deployment_strategy' => 'in_place',
            'deploy_path' => '/var/www/shipyard/inplace-app',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons", [
                'command' => 'php artisan queue:work',
                'user' => 'www-data',
                'application_id' => $atomicApp->id,
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['directory' => '/var/www/shipyard/atomic-app/current']);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons", [
                'command' => 'php artisan queue:work',
                'user' => 'www-data',
                'application_id' => $inPlaceApp->id,
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['directory' => '/var/www/shipyard/inplace-app']);
    }

    public function test_create_requires_a_directory_without_an_application(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons", [
                'command' => 'node worker.js',
                'user' => 'www-data',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['directory']);

        Queue::assertNothingPushed();
    }

    public function test_create_rejects_an_application_of_another_server(): void
    {
        Queue::fake();
        $foreignApp = Application::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons", [
                'command' => 'php artisan queue:work',
                'user' => 'www-data',
                'application_id' => $foreignApp->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['application_id']);
    }

    public function test_child_routes_404_for_daemons_of_another_server(): void
    {
        $foreign = Daemon::factory()->create();

        foreach ([
            fn () => $this->getJson("/api/servers/{$this->server->id}/daemons/{$foreign->id}"),
            fn () => $this->getJson("/api/servers/{$this->server->id}/daemons/{$foreign->id}/status"),
            fn () => $this->getJson("/api/servers/{$this->server->id}/daemons/{$foreign->id}/output"),
            fn () => $this->postJson("/api/servers/{$this->server->id}/daemons/{$foreign->id}/restart"),
            fn () => $this->deleteJson("/api/servers/{$this->server->id}/daemons/{$foreign->id}"),
        ] as $request) {
            $this->actingAs($this->user);
            $request()->assertNotFound();
        }

        $this->assertNotNull($foreign->fresh());
    }

    public function test_delete_dispatches_the_removal_job_and_conflicts_while_removing(): void
    {
        Queue::fake();
        $daemon = Daemon::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}")
            ->assertStatus(202);

        Queue::assertPushed(ProcessDaemonRemoval::class);
        $this->assertSame('removing', $daemon->fresh()->status);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}")
            ->assertStatus(409);
    }

    public function test_restart_succeeds_for_installed_daemons(): void
    {
        $daemon = Daemon::factory()->create(['server_id' => $this->server->id]);

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('restartDaemon')->once();
        });

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}/restart")
            ->assertOk();
    }

    public function test_restart_conflicts_unless_the_daemon_is_installed(): void
    {
        foreach (['installing', 'removing', 'failed'] as $status) {
            $daemon = Daemon::factory()->create(['server_id' => $this->server->id, 'status' => $status]);

            $this->actingAs($this->user)
                ->postJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}/restart")
                ->assertStatus(409);
        }
    }

    public function test_restart_maps_ssh_failures_to_500(): void
    {
        $daemon = Daemon::factory()->create(['server_id' => $this->server->id]);

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('restartDaemon')
                ->andThrow(new \RuntimeException('Failed to restart daemon: unit not found'));
        });

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}/restart")
            ->assertStatus(500)
            ->assertJsonFragment(['message' => 'Failed to restart daemon: unit not found']);
    }

    public function test_status_endpoint_returns_the_live_state(): void
    {
        $daemon = Daemon::factory()->create(['server_id' => $this->server->id]);

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('getStatus')
                ->once()
                ->andReturn(['state' => 'degraded', 'instances' => [1 => 'active', 2 => 'failed']]);
        });

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}/status")
            ->assertOk()
            ->assertJson(['state' => 'degraded', 'instances' => ['1' => 'active', '2' => 'failed']]);
    }

    public function test_output_endpoint_returns_journal_logs(): void
    {
        $daemon = Daemon::factory()->create(['server_id' => $this->server->id]);

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('readLogs')
                ->once()
                ->andReturn(['output' => 'job processed', 'exists' => true]);
        });

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/daemons/{$daemon->id}/output")
            ->assertOk()
            ->assertJson(['output' => 'job processed', 'exists' => true]);
    }
}
