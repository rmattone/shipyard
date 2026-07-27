<?php

namespace Tests\Feature;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationChannelApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createOrgUser();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/notification-channels')->assertUnauthorized();
    }

    public function test_create_discord_channel(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/notification-channels', [
                'name' => 'Ops Discord',
                'type' => 'discord',
                'events' => ['deployment_failed'],
                'config' => ['webhook_url' => 'https://discord.com/api/webhooks/123/abc'],
            ])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Ops Discord', 'type' => 'discord'])
            ->assertJsonPath('config_display.has_webhook_url', true)
            ->assertJsonMissingPath('config');
    }

    public function test_create_telegram_and_email_channels(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/notification-channels', [
                'name' => 'TG',
                'type' => 'telegram',
                'events' => ['deployment_succeeded', 'deployment_failed'],
                'config' => ['bot_token' => '123456789:AAFakeBotToken_abcdefghijklmnop', 'chat_id' => '@mychannel'],
            ])
            ->assertCreated()
            ->assertJsonPath('config_display.chat_id', '@mychannel');

        $this->actingAs($this->user)
            ->postJson('/api/notification-channels', [
                'name' => 'Mail',
                'type' => 'email',
                'events' => ['deployment_failed'],
                'config' => ['api_key' => 're_abc123', 'from_email' => 'a@b.com', 'to_email' => 'c@d.com'],
            ])
            ->assertCreated()
            ->assertJsonPath('config_display.from_email', 'a@b.com');
    }

    public function test_per_type_config_validation(): void
    {
        $cases = [
            ['type' => 'discord', 'config' => ['webhook_url' => 'https://evil.example.com/hook'], 'error' => 'config.webhook_url'],
            ['type' => 'telegram', 'config' => ['bot_token' => 'not-a-token', 'chat_id' => '123'], 'error' => 'config.bot_token'],
            ['type' => 'telegram', 'config' => ['bot_token' => '123456789:AAFakeBotToken_abcdefghijklmnop', 'chat_id' => 'no at sign'], 'error' => 'config.chat_id'],
            ['type' => 'email', 'config' => ['api_key' => 'sk_wrong_prefix', 'from_email' => 'a@b.com', 'to_email' => 'c@d.com'], 'error' => 'config.api_key'],
            ['type' => 'email', 'config' => ['api_key' => 're_abc', 'from_email' => 'not-an-email', 'to_email' => 'c@d.com'], 'error' => 'config.from_email'],
        ];

        foreach ($cases as $case) {
            $this->actingAs($this->user)
                ->postJson('/api/notification-channels', [
                    'name' => 'x',
                    'type' => $case['type'],
                    'events' => ['deployment_failed'],
                    'config' => $case['config'],
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$case['error']]);
        }
    }

    public function test_events_are_validated(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/notification-channels', [
                'name' => 'x',
                'type' => 'discord',
                'events' => [],
                'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events']);

        $this->actingAs($this->user)
            ->postJson('/api/notification-channels', [
                'name' => 'x',
                'type' => 'discord',
                'events' => ['server_exploded'],
                'config' => ['webhook_url' => 'https://discord.com/api/webhooks/1/a'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0']);
    }

    public function test_update_keeps_the_secret_when_left_blank_and_type_is_immutable(): void
    {
        $channel = NotificationChannel::factory()->telegram()->create();
        $originalToken = $channel->config['bot_token'];

        $this->actingAs($this->user)
            ->putJson("/api/notification-channels/{$channel->id}", [
                'name' => 'Renamed bot',
                'type' => 'discord',
                'events' => ['deployment_failed'],
                'config' => ['chat_id' => '@newchannel'],
            ])
            ->assertOk()
            ->assertJsonFragment(['name' => 'Renamed bot', 'type' => 'telegram']);

        $fresh = $channel->fresh();
        $this->assertSame($originalToken, $fresh->config['bot_token']);
        $this->assertSame('@newchannel', $fresh->config['chat_id']);
        $this->assertSame(['deployment_failed'], $fresh->events);
    }

    public function test_update_replaces_the_secret_when_provided(): void
    {
        $channel = NotificationChannel::factory()->telegram()->create();

        $this->actingAs($this->user)
            ->putJson("/api/notification-channels/{$channel->id}", [
                'config' => ['bot_token' => '987654321:BBNewBotToken_abcdefghijklmnopq', 'chat_id' => '42'],
            ])
            ->assertOk();

        $this->assertSame('987654321:BBNewBotToken_abcdefghijklmnopq', $channel->fresh()->config['bot_token']);
    }

    public function test_index_and_destroy(): void
    {
        NotificationChannel::factory()->count(2)->create();

        $this->actingAs($this->user)
            ->getJson('/api/notification-channels')
            ->assertOk()
            ->assertJsonCount(2);

        $channel = NotificationChannel::first();
        $this->actingAs($this->user)
            ->deleteJson("/api/notification-channels/{$channel->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('notification_channels', ['id' => $channel->id]);
    }

    public function test_test_endpoint_sends_and_reports_success(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);
        $channel = NotificationChannel::factory()->disabled()->create();

        $this->actingAs($this->user)
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSentCount(1); // disabled channels can still be tested
    }

    public function test_test_endpoint_maps_delivery_failure_to_422(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);
        $channel = NotificationChannel::factory()->telegram()->create();

        $this->actingAs($this->user)
            ->postJson("/api/notification-channels/{$channel->id}/test")
            ->assertUnprocessable()
            ->assertJson(['success' => false])
            ->assertJsonFragment(['message' => 'Telegram API error: chat not found']);
    }
}
