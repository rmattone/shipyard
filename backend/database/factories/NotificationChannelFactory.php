<?php

namespace Database\Factories;

use App\Models\NotificationChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationChannelFactory extends Factory
{
    protected $model = NotificationChannel::class;

    public function definition(): array
    {
        return [
            'type' => NotificationChannel::TYPE_DISCORD,
            'name' => 'Ops Discord',
            'config' => [
                'webhook_url' => 'https://discord.com/api/webhooks/123456789/fake-token-abcdef',
            ],
            'events' => NotificationChannel::EVENTS,
            'is_enabled' => true,
        ];
    }

    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => NotificationChannel::TYPE_TELEGRAM,
            'name' => 'Deploy bot',
            'config' => [
                'bot_token' => '123456789:AAFakeBotToken_abcdefghijklmnop',
                'chat_id' => '-1001234567890',
            ],
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => NotificationChannel::TYPE_EMAIL,
            'name' => 'Admin email',
            'config' => [
                'api_key' => 're_fake_api_key_123456',
                'from_email' => 'shipyard@example.com',
                'to_email' => 'admin@example.com',
            ],
        ]);
    }

    public function failuresOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'events' => [NotificationChannel::EVENT_FAILED],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }
}
