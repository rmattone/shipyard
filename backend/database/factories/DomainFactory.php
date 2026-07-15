<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'domain' => fake()->unique()->domainName(),
            'is_primary' => false,
            'ssl_enabled' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    public function withSsl(): static
    {
        return $this->state(fn (array $attributes) => [
            'ssl_enabled' => true,
            'ssl_expires_at' => now()->addDays(90),
            'ssl_issuer' => "Let's Encrypt",
        ]);
    }
}
