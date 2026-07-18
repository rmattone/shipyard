<?php

namespace Database\Factories;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatabaseInstallationFactory extends Factory
{
    protected $model = DatabaseInstallation::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'engine' => 'mysql',
            'status' => 'pending',
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'version_installed' => '8.0.36',
            'admin_password' => 'generated-admin-password',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
        ]);
    }
}
