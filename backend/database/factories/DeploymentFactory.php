<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'commit_hash' => fake()->sha1(),
            'commit_message' => fake()->sentence(),
            'status' => 'pending',
            'log' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
            'log' => "Deployment completed successfully.",
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(1),
            'finished_at' => now(),
            'log' => "Deployment failed: " . fake()->sentence(),
        ]);
    }
}
