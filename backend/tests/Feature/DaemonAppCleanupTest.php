<?php

namespace Tests\Feature;

use App\Jobs\ProcessDaemonRemoval;
use App\Models\Application;
use App\Models\Daemon;
use App\Models\Server;
use App\Models\User;
use App\Services\NginxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deleting an app must schedule removal of its daemons or the server keeps
 * running workers against a dead deploy path forever.
 */
class DaemonAppCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_application_schedules_removal_of_its_linked_daemons(): void
    {
        Queue::fake();
        $this->mock(NginxService::class, function ($mock) {
            $mock->shouldReceive('remove');
        });

        $user = User::factory()->create();
        $server = Server::factory()->create();
        $app = Application::factory()->create(['server_id' => $server->id]);
        $linked = Daemon::factory()->create(['server_id' => $server->id, 'application_id' => $app->id]);
        $unrelated = Daemon::factory()->create(['server_id' => $server->id]);

        $this->actingAs($user)
            ->deleteJson("/api/applications/{$app->id}")
            ->assertNoContent();

        Queue::assertPushed(ProcessDaemonRemoval::class, 1);

        // The row must survive the app deletion (unlinked, marked removing)
        // so the removal job can still clean the units off the server.
        $fresh = $linked->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('removing', $fresh->status);
        $this->assertNull($fresh->application_id);

        $this->assertSame('installed', $unrelated->fresh()->status);
    }
}
