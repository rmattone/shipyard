<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Server;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    protected $model = Server::class;

    public function definition(): array
    {
        return [
            // Reuse the test's active organization when one is bound so
            // factory-made servers land in the acting user's org.
            'organization_id' => fn () => CurrentOrganization::id() ?? Organization::factory(),
            'name' => fake()->company() . ' Server',
            'host' => fake()->ipv4(),
            'port' => 22,
            'username' => 'root',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\ntest-key\n-----END RSA PRIVATE KEY-----",
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
