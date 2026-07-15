<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function pushPayload(string $branch = 'main'): array
    {
        return [
            'object_kind' => 'push',
            'ref' => "refs/heads/{$branch}",
            'after' => str_repeat('a', 40),
            'commits' => [
                [
                    'message' => 'Test commit',
                    'author' => ['name' => 'Tester'],
                ],
            ],
            'repository' => ['url' => 'git@gitlab.com:test/repo.git'],
        ];
    }

    public function test_webhook_with_invalid_token_is_rejected(): void
    {
        Queue::fake();

        $app = Application::factory()->create(['branch' => 'main']);

        $response = $this->postJson("/api/webhook/{$app->id}", $this->pushPayload(), [
            'X-Gitlab-Token' => 'wrong-token',
        ]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_webhook_with_missing_token_is_rejected(): void
    {
        Queue::fake();

        $app = Application::factory()->create(['branch' => 'main']);

        $response = $this->postJson("/api/webhook/{$app->id}", $this->pushPayload());

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_webhook_with_valid_token_queues_deployment(): void
    {
        Queue::fake();

        $app = Application::factory()->create(['branch' => 'main']);

        $response = $this->postJson("/api/webhook/{$app->id}", $this->pushPayload(), [
            'X-Gitlab-Token' => $app->webhook_secret,
        ]);

        $response->assertOk()->assertJsonStructure(['message', 'commit', 'deployment_id']);

        Queue::assertPushed(ProcessDeployment::class, 1);

        $this->assertDatabaseHas('deployments', [
            'application_id' => $app->id,
            'status' => 'pending',
            'commit_hash' => str_repeat('a', 40),
        ]);
    }

    public function test_webhook_for_other_branch_does_not_deploy(): void
    {
        Queue::fake();

        $app = Application::factory()->create(['branch' => 'main']);

        $response = $this->postJson("/api/webhook/{$app->id}", $this->pushPayload('develop'), [
            'X-Gitlab-Token' => $app->webhook_secret,
        ]);

        $response->assertOk();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('deployments', 0);
    }
}
