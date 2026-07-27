<?php

namespace Database\Factories;

use App\Models\GitProvider;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

class GitProviderFactory extends Factory
{
    protected $model = GitProvider::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn () => CurrentOrganization::id() ?? Organization::factory(),
            'name' => fake()->company().' GitLab',
            'type' => 'gitlab',
            'host' => 'https://gitlab.com',
            'access_token' => 'glpat-test-token',
            'username' => fake()->userName(),
            'is_default' => false,
        ];
    }

    public function github(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'github',
            'host' => 'https://github.com',
            'access_token' => 'ghp_test-token',
        ]);
    }
}
