<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Organization::ROLE_MEMBER,
            'token' => OrganizationInvitation::generateToken(),
            'expires_at' => now()->addDays(OrganizationInvitation::EXPIRES_AFTER_DAYS),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }
}
