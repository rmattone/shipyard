<?php

namespace Database\Factories;

use App\Models\Daemon;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class DaemonFactory extends Factory
{
    protected $model = Daemon::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'command' => 'php8.3 artisan queue:work redis --sleep=3 --tries=3',
            'user' => 'www-data',
            'directory' => '/var/www/shipyard/app/current',
            'processes' => 1,
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

    public function multiProcess(int $count = 3): static
    {
        return $this->state(fn (array $attributes) => [
            'processes' => $count,
        ]);
    }
}
