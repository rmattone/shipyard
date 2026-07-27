<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledTaskInstall;
use App\Jobs\ProcessScheduledTaskRemoval;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\User;
use App\Services\CrontabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createOrgUser();
        $this->server = Server::factory()->create();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/scheduled-tasks")
            ->assertUnauthorized();
    }

    public function test_index_lists_only_the_servers_tasks(): void
    {
        ScheduledTask::factory()->count(2)->create(['server_id' => $this->server->id]);
        ScheduledTask::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks")
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_create_dispatches_the_install_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'php8.3 /var/www/app/current/artisan schedule:run',
                'user' => 'www-data',
                'frequency' => 'minutely',
            ]);

        $response->assertStatus(202)
            ->assertJsonFragment([
                'status' => 'installing',
                'cron_expression' => '* * * * *',
            ]);

        Queue::assertPushed(ProcessScheduledTaskInstall::class);
        $this->assertDatabaseHas('scheduled_tasks', [
            'server_id' => $this->server->id,
            'status' => 'installing',
        ]);
    }

    public function test_custom_frequency_requires_all_five_fields(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'echo hi',
                'user' => 'root',
                'frequency' => 'custom',
                'hour' => '*',
                'day' => '*',
                'month' => '*',
                'weekday' => '*',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['minute']);

        Queue::assertNothingPushed();
    }

    public function test_custom_frequency_builds_the_expression_from_the_fields(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'echo hi',
                'user' => 'root',
                'frequency' => 'custom',
                'minute' => '*/15',
                'hour' => '2',
                'day' => '*',
                'month' => '*',
                'weekday' => '1-5',
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['cron_expression' => '*/15 2 * * 1-5']);
    }

    public function test_preset_frequency_discards_custom_fields(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'echo hi',
                'user' => 'root',
                'frequency' => 'reboot',
                'minute' => '5',
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['cron_expression' => '@reboot']);

        $this->assertDatabaseHas('scheduled_tasks', [
            'frequency' => 'reboot',
            'minute' => null,
        ]);
    }

    public function test_show_returns_the_task(): void
    {
        $task = ScheduledTask::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $task->id]);
    }

    public function test_child_routes_404_for_tasks_of_another_server(): void
    {
        $foreign = ScheduledTask::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks/{$foreign->id}/output")
            ->assertNotFound();

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/scheduled-tasks/{$foreign->id}")
            ->assertNotFound();

        $this->assertNotNull($foreign->fresh());
    }

    public function test_delete_dispatches_the_removal_job(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}")
            ->assertStatus(202);

        Queue::assertPushed(ProcessScheduledTaskRemoval::class);
        $this->assertSame('removing', $task->fresh()->status);
    }

    public function test_delete_while_already_removing_conflicts(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->create([
            'server_id' => $this->server->id,
            'status' => 'removing',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}")
            ->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_failed_tasks_can_still_be_deleted(): void
    {
        Queue::fake();
        $task = ScheduledTask::factory()->failed()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}")
            ->assertStatus(202);

        Queue::assertPushed(ProcessScheduledTaskRemoval::class);
    }

    public function test_create_can_link_the_task_to_an_application_on_the_same_server(): void
    {
        Queue::fake();
        $app = Application::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'php8.3 artisan schedule:run',
                'user' => 'www-data',
                'frequency' => 'minutely',
                'application_id' => $app->id,
            ])
            ->assertStatus(202)
            ->assertJsonFragment(['application_id' => $app->id]);
    }

    public function test_create_rejects_an_application_of_another_server(): void
    {
        Queue::fake();
        $foreignApp = Application::factory()->create();

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/scheduled-tasks", [
                'command' => 'echo hi',
                'user' => 'www-data',
                'frequency' => 'minutely',
                'application_id' => $foreignApp->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['application_id']);

        Queue::assertNothingPushed();
    }

    public function test_index_can_filter_by_application(): void
    {
        $app = Application::factory()->create(['server_id' => $this->server->id]);
        ScheduledTask::factory()->create(['server_id' => $this->server->id, 'application_id' => $app->id]);
        ScheduledTask::factory()->create(['server_id' => $this->server->id]);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks?application_id={$app->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['application_id' => $app->id]);
    }

    public function test_output_endpoint_returns_the_tail_of_the_log(): void
    {
        $task = ScheduledTask::factory()->create(['server_id' => $this->server->id]);

        $this->mock(CrontabService::class, function ($mock) {
            $mock->shouldReceive('readTaskOutput')
                ->once()
                ->andReturn(['output' => 'ran fine', 'exists' => true]);
        });

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}/output")
            ->assertOk()
            ->assertJson(['output' => 'ran fine', 'exists' => true]);
    }

    public function test_output_endpoint_maps_ssh_failures_to_500(): void
    {
        $task = ScheduledTask::factory()->create(['server_id' => $this->server->id]);

        $this->mock(CrontabService::class, function ($mock) {
            $mock->shouldReceive('readTaskOutput')
                ->andThrow(new \RuntimeException('connection refused'));
        });

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/scheduled-tasks/{$task->id}/output")
            ->assertStatus(500)
            ->assertJsonFragment(['message' => 'connection refused']);
    }
}
