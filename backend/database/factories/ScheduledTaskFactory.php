<?php

namespace Database\Factories;

use App\Models\ScheduledTask;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledTaskFactory extends Factory
{
    protected $model = ScheduledTask::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'command' => 'php8.3 /var/www/shipyard/app/current/artisan schedule:run',
            'user' => 'www-data',
            'frequency' => 'minutely',
            'status' => 'installed',
        ];
    }

    public function installing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'installing',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'custom',
            'minute' => '*/5',
            'hour' => '*',
            'day' => '*',
            'month' => '*',
            'weekday' => '*',
        ]);
    }

    public function reboot(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'reboot',
        ]);
    }
}
