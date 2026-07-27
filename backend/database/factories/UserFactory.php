<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Every user gets a personal organization they own, mirroring what
     * the seeder and the registration path guarantee in production.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if ($user->organizations()->exists()) {
                return;
            }

            $organization = Organization::create(['name' => "{$user->name}'s Organization"]);

            $user->organizations()->attach($organization, ['role' => Organization::ROLE_OWNER]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        });
    }

    /**
     * Undoes the personal organization from configure() (callbacks run
     * in registration order), leaving a user with zero memberships.
     */
    public function withoutOrganization(): static
    {
        return $this->afterCreating(function (User $user) {
            $organizations = $user->organizations()->get();

            $user->forceFill(['current_organization_id' => null])->save();
            $user->organizations()->detach();

            foreach ($organizations as $organization) {
                if ($organization->users()->doesntExist()) {
                    $organization->delete();
                }
            }
        });
    }
}
