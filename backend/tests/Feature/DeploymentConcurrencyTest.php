<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Jobs\ProcessRollback;
use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeploymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $this->actingAs($this->createOrgUser());
    }

    public function test_deploy_is_rejected_while_another_deployment_is_running(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $app = Application::factory()->create();
        Deployment::factory()->running()->create(['application_id' => $app->id]);

        $response = $this->postJson("/api/applications/{$app->id}/deploy");

        $response->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_deploy_is_rejected_while_another_deployment_is_pending(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $app = Application::factory()->create();
        Deployment::factory()->create(['application_id' => $app->id, 'status' => 'pending']);

        $response = $this->postJson("/api/applications/{$app->id}/deploy");

        $response->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_webhook_skips_with_200_while_deployment_in_progress(): void
    {
        Queue::fake();

        $app = Application::factory()->create(['branch' => 'main']);
        Deployment::factory()->running()->create(['application_id' => $app->id]);

        $response = $this->postJson("/api/webhook/{$app->id}", [
            'object_kind' => 'push',
            'ref' => 'refs/heads/main',
            'after' => str_repeat('b', 40),
            'commits' => [['message' => 'Race', 'author' => ['name' => 'Tester']]],
        ], [
            'X-Gitlab-Token' => $app->webhook_secret,
        ]);

        $response->assertOk()->assertJsonFragment([
            'message' => 'A deployment is already pending or running, skipping this push.',
        ]);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('deployments', 1);
    }

    public function test_rollback_is_rejected_while_deployment_in_progress(): void
    {
        Queue::fake();
        $this->actingAsAdmin();

        $app = Application::factory()->create(['deployment_strategy' => 'atomic']);
        $target = Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'release_id' => '20260715010000-aaa111',
            'release_path' => "{$app->getReleasesPath()}/20260715010000-aaa111",
            'is_active' => false,
        ]);
        Deployment::factory()->running()->create(['application_id' => $app->id]);

        $response = $this->postJson("/api/applications/{$app->id}/rollback", [
            'deployment_id' => $target->id,
        ]);

        $response->assertStatus(409);
        Queue::assertNothingPushed();
    }

    public function test_deploy_and_rollback_jobs_share_the_same_lock(): void
    {
        $app = Application::factory()->create();
        $deployment = Deployment::factory()->create(['application_id' => $app->id]);
        $rollback = Deployment::factory()->create(['application_id' => $app->id, 'type' => 'rollback']);

        $deployMiddleware = (new ProcessDeployment($deployment))->middleware()[0];
        $rollbackMiddleware = (new ProcessRollback($rollback))->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $deployMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $rollbackMiddleware);

        $probe = new \stdClass;
        $this->assertSame(
            $deployMiddleware->getLockKey($probe),
            $rollbackMiddleware->getLockKey($probe),
            'Deploy and rollback jobs must contend for the same per-application lock.'
        );
    }
}
