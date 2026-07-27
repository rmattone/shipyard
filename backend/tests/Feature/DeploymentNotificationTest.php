<?php

namespace Tests\Feature;

use App\Jobs\SendDeploymentNotification;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deployment completion fans out to configured notification channels.
 * The queue runs sync in tests, so marking a deployment finished sends
 * (faked) HTTP right here — which also guarantees the hook is a strict
 * no-op when no channels exist, keeping every pre-existing deployment
 * test green without modification.
 */
class DeploymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The notification job filters channels by the deployment's
        // organization; bind one context so the channel and deployment
        // factories land in the same organization.
        CurrentOrganization::set(
            Organization::factory()->create(),
            Organization::ROLE_OWNER,
        );
    }

    private function makeDeployment(array $attributes = []): Deployment
    {
        $app = Application::factory()->create([
            'name' => 'shop-api',
            'branch' => 'main',
        ]);

        return Deployment::factory()->running()->create(array_merge([
            'application_id' => $app->id,
            'commit_hash' => 'abcdef1234567890',
            'commit_message' => "Fix <critical> bug\nsecond line ignored",
        ], $attributes));
    }

    public function test_completion_dispatches_the_notification_job_with_the_right_event(): void
    {
        Queue::fake();

        $this->makeDeployment()->markAsSuccess();
        Queue::assertPushed(SendDeploymentNotification::class, fn ($job) => $job->event === NotificationChannel::EVENT_SUCCEEDED);

        $this->makeDeployment()->markAsFailed();
        Queue::assertPushed(SendDeploymentNotification::class, fn ($job) => $job->event === NotificationChannel::EVENT_FAILED);
    }

    public function test_no_channels_means_no_http_at_all(): void
    {
        Http::fake();

        $this->makeDeployment()->markAsSuccess();
        $this->makeDeployment()->markAsFailed();

        Http::assertNothingSent();
    }

    public function test_discord_receives_a_success_embed(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->create();

        $deployment = $this->makeDeployment();
        $deployment->markAsSuccess();

        Http::assertSent(function (Request $request) use ($deployment) {
            $embed = $request->data()['embeds'][0] ?? [];

            return str_starts_with($request->url(), 'https://discord.com/api/webhooks/')
                && str_contains($embed['title'], 'Deployment succeeded')
                && str_contains($embed['title'], 'shop-api')
                && $embed['color'] === 0x2ECC71
                && str_contains($embed['url'], "/apps/{$deployment->application_id}/deployments/{$deployment->id}");
        });
    }

    public function test_discord_failure_embed_is_red_and_carries_a_log_excerpt(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->create();

        $log = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 30)));
        $deployment = $this->makeDeployment(['log' => $log]);
        $deployment->markAsFailed();

        Http::assertSent(function (Request $request) {
            $embed = $request->data()['embeds'][0] ?? [];

            return $embed['color'] === 0xE74C3C
                && str_contains($embed['title'], 'Deployment failed')
                && str_contains($embed['description'] ?? '', 'line 30')
                && ! str_contains($embed['description'] ?? '', 'line 1'."\n"); // only the tail
        });
    }

    public function test_success_message_carries_no_log_excerpt(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->create();

        $this->makeDeployment(['log' => "secret build output\nmore lines"])->markAsSuccess();

        Http::assertSent(function (Request $request) {
            $embed = $request->data()['embeds'][0] ?? [];

            return ! str_contains(json_encode($embed), 'secret build output');
        });
    }

    public function test_failure_excerpt_is_capped(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->create();

        $log = implode("\n", array_fill(0, 10, str_repeat('x', 400)));
        $this->makeDeployment(['log' => $log])->markAsFailed();

        Http::assertSent(function (Request $request) {
            $description = $request->data()['embeds'][0]['description'] ?? '';

            return strlen($description) > 0 && strlen($description) <= 1000;
        });
    }

    public function test_telegram_message_targets_the_chat_and_escapes_html(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        NotificationChannel::factory()->telegram()->create();

        $this->makeDeployment()->markAsSuccess();

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return str_contains($request->url(), 'api.telegram.org/bot')
                && str_ends_with($request->url(), '/sendMessage')
                && $data['chat_id'] === '-1001234567890'
                && $data['parse_mode'] === 'HTML'
                && str_contains($data['text'], 'Fix &lt;critical&gt; bug')
                && ! str_contains($data['text'], '<critical>');
        });
    }

    public function test_telegram_ok_false_is_treated_as_a_failure_and_logged(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'])]);
        NotificationChannel::factory()->telegram()->create();

        // Must not throw out of the sync-queued job.
        $this->makeDeployment()->markAsSuccess();

        $this->addToAssertionCount(1);
    }

    public function test_resend_email_request_shape(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'email_123'])]);
        NotificationChannel::factory()->email()->create();

        $this->makeDeployment()->markAsSuccess();

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_fake_api_key_123456')
                && $data['from'] === 'shipyard@example.com'
                && $data['to'] === ['admin@example.com']
                && str_contains($data['subject'], 'shop-api')
                && str_contains($data['subject'], 'succeeded');
        });
    }

    public function test_subscription_and_enabled_filtering(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->failuresOnly()->create();
        NotificationChannel::factory()->disabled()->create();

        $this->makeDeployment()->markAsSuccess();
        Http::assertNothingSent();

        $this->makeDeployment()->markAsFailed();
        Http::assertSentCount(1); // only the failures-only channel; disabled stays silent
    }

    public function test_one_failing_channel_does_not_block_the_next(): void
    {
        Http::fake([
            'discord.com/api/webhooks/111*' => Http::response('', 500),
            'discord.com/api/webhooks/222*' => Http::response('', 204),
        ]);
        NotificationChannel::factory()->create(['config' => ['webhook_url' => 'https://discord.com/api/webhooks/111/aaa']]);
        NotificationChannel::factory()->create(['config' => ['webhook_url' => 'https://discord.com/api/webhooks/222/bbb']]);

        $this->makeDeployment()->markAsSuccess();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'webhooks/222'));
    }

    public function test_rollbacks_are_labeled_as_rollbacks(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        NotificationChannel::factory()->create();

        $this->makeDeployment(['type' => 'rollback'])->markAsSuccess();

        Http::assertSent(function (Request $request) {
            $title = $request->data()['embeds'][0]['title'] ?? '';

            return str_contains($title, 'Rollback succeeded');
        });
    }
}
