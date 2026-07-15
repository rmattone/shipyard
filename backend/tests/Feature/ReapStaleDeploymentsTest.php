<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReapStaleDeploymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reaps_running_deployments_older_than_the_job_timeout(): void
    {
        $app = Application::factory()->create(['status' => 'deploying']);
        $stale = Deployment::factory()->running()->create([
            'application_id' => $app->id,
            'started_at' => now()->subHour(),
        ]);

        $this->artisan('deployments:reap-stale')->assertSuccessful();

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertStringContainsString('stale-deployment reaper', $stale->log);
        $this->assertSame('failed', $app->fresh()->status);
    }

    public function test_leaves_fresh_running_deployments_alone(): void
    {
        $app = Application::factory()->create(['status' => 'deploying']);
        $fresh = Deployment::factory()->running()->create([
            'application_id' => $app->id,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('deployments:reap-stale')->assertSuccessful();

        $this->assertSame('running', $fresh->fresh()->status);
        $this->assertSame('deploying', $app->fresh()->status);
    }

    public function test_reaps_pending_deployments_that_were_never_picked_up(): void
    {
        $app = Application::factory()->create(['status' => 'active']);
        $abandoned = Deployment::factory()->create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);
        $abandoned->created_at = now()->subHours(3);
        $abandoned->save();

        $this->artisan('deployments:reap-stale')->assertSuccessful();

        $this->assertSame('failed', $abandoned->fresh()->status);
    }

    public function test_does_not_clobber_an_app_that_deployed_successfully_since(): void
    {
        $app = Application::factory()->create(['status' => 'active']);
        $stale = Deployment::factory()->running()->create([
            'application_id' => $app->id,
            'started_at' => now()->subHour(),
        ]);

        $this->artisan('deployments:reap-stale')->assertSuccessful();

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('active', $app->fresh()->status, 'The reaper must not change the status of an app that is not deploying.');
    }
}
