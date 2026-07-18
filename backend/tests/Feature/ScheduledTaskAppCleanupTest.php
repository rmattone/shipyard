<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledTaskRemoval;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\User;
use App\Services\NginxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A cron entry outlives the app it points at unless deleting the app also
 * schedules its removal; otherwise the server keeps firing a command
 * against a dead deploy path forever.
 */
class ScheduledTaskAppCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_application_schedules_removal_of_its_linked_tasks(): void
    {
        Queue::fake();
        $this->mock(NginxService::class, function ($mock) {
            $mock->shouldReceive('remove');
        });

        $user = User::factory()->create();
        $server = Server::factory()->create();
        $app = Application::factory()->create(['server_id' => $server->id]);
        $linked = ScheduledTask::factory()->create(['server_id' => $server->id, 'application_id' => $app->id]);
        $unrelated = ScheduledTask::factory()->create(['server_id' => $server->id]);

        $this->actingAs($user)
            ->deleteJson("/api/applications/{$app->id}")
            ->assertNoContent();

        Queue::assertPushed(ProcessScheduledTaskRemoval::class, 1);

        // The row must survive the app deletion (unlinked, marked removing)
        // so the removal job can still clean the remote crontab.
        $fresh = $linked->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('removing', $fresh->status);
        $this->assertNull($fresh->application_id);

        $this->assertSame('installed', $unrelated->fresh()->status);
    }
}
