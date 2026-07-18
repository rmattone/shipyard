<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\GitProvider;
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

    public function test_github_webhook_with_valid_signature_queues_deployment(): void
    {
        Queue::fake();

        $provider = GitProvider::factory()->github()->create();
        $app = Application::factory()->create(['branch' => 'main', 'git_provider_id' => $provider->id]);

        $body = json_encode([
            'ref' => 'refs/heads/main',
            'after' => str_repeat('c', 40),
            'head_commit' => ['id' => str_repeat('c', 40), 'message' => 'GH commit', 'author' => ['name' => 'Octocat']],
            'repository' => ['clone_url' => 'https://github.com/test/repo.git'],
        ]);
        $signature = 'sha256='.hash_hmac('sha256', $body, $app->webhook_secret);

        $response = $this->call('POST', "/api/webhook/{$app->id}", [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertOk();
        Queue::assertPushed(ProcessDeployment::class, 1);
        $this->assertDatabaseHas('deployments', [
            'application_id' => $app->id,
            'commit_hash' => str_repeat('c', 40),
        ]);
    }

    public function test_github_webhook_with_bad_signature_is_rejected(): void
    {
        Queue::fake();

        $provider = GitProvider::factory()->github()->create();
        $app = Application::factory()->create(['branch' => 'main', 'git_provider_id' => $provider->id]);

        $body = json_encode(['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)]);

        $response = $this->call('POST', "/api/webhook/{$app->id}", [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_github_ping_event_is_acknowledged_without_deploying(): void
    {
        Queue::fake();

        $provider = GitProvider::factory()->github()->create();
        $app = Application::factory()->create(['branch' => 'main', 'git_provider_id' => $provider->id]);

        $body = json_encode(['zen' => 'Keep it simple.']);
        $signature = 'sha256='.hash_hmac('sha256', $body, $app->webhook_secret);

        $response = $this->call('POST', "/api/webhook/{$app->id}", [], [], [], [
            'HTTP_X_GITHUB_EVENT' => 'ping',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_bitbucket_webhook_with_valid_token_queues_deployment(): void
    {
        Queue::fake();

        $provider = GitProvider::factory()->create(['type' => 'bitbucket', 'host' => 'https://bitbucket.org']);
        $app = Application::factory()->create(['branch' => 'main', 'git_provider_id' => $provider->id]);

        $payload = [
            'push' => ['changes' => [[
                'new' => [
                    'type' => 'branch',
                    'name' => 'main',
                    'target' => ['hash' => str_repeat('d', 40), 'message' => 'BB commit', 'author' => ['raw' => 'Dev <dev@test>']],
                ],
            ]]],
            'repository' => ['links' => ['html' => ['href' => 'https://bitbucket.org/test/repo']]],
        ];

        $response = $this->withHeaders(['X-Event-Key' => 'repo:push'])
            ->postJson("/api/webhook/{$app->id}?token={$app->webhook_secret}", $payload);

        $response->assertOk();
        Queue::assertPushed(ProcessDeployment::class, 1);
        $this->assertDatabaseHas('deployments', [
            'application_id' => $app->id,
            'commit_hash' => str_repeat('d', 40),
        ]);
    }

    public function test_bitbucket_webhook_with_wrong_token_is_rejected(): void
    {
        Queue::fake();

        $provider = GitProvider::factory()->create(['type' => 'bitbucket', 'host' => 'https://bitbucket.org']);
        $app = Application::factory()->create(['branch' => 'main', 'git_provider_id' => $provider->id]);

        $response = $this->withHeaders(['X-Event-Key' => 'repo:push'])
            ->postJson("/api/webhook/{$app->id}?token=wrong", ['push' => ['changes' => []]]);

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }
}
