<?php

namespace Database\Factories;

use App\Models\Database;
use App\Models\DatabaseUser;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatabaseUserFactory extends Factory
{
    protected $model = DatabaseUser::class;

    public function definition(): array
    {
        return [
            'database_id' => Database::factory(),
            'username' => fake()->unique()->userName(),
            'password' => 'secret-user-password',
            'host' => '%',
            'privileges' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE'],
            'status' => 'active',
        ];
    }
}
