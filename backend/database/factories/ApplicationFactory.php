<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        $name = fake()->company();
        $slug = Str::slug($name);

        return [
            'server_id' => Server::factory(),
            'git_provider_id' => null,
            'name' => $name,
            'type' => fake()->randomElement(['laravel', 'nodejs', 'static']),
            'domain' => $slug . '.example.com',
            'repository_url' => 'git@gitlab.com:test/' . $slug . '.git',
            'branch' => 'main',
            'deploy_path' => '/var/www/' . $slug,
            'build_command' => null,
            'post_deploy_commands' => null,
            'ssl_enabled' => false,
            'status' => 'active',
            'webhook_secret' => Str::random(40),
        ];
    }

    public function laravel(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'laravel',
        ]);
    }

    public function nodejs(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'nodejs',
            'build_command' => 'npm run build',
        ]);
    }

    public function static(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'static',
            'build_command' => 'npm run build',
        ]);
    }
}
