<?php

namespace Database\Factories;

use App\Models\Database;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatabaseFactory extends Factory
{
    protected $model = Database::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => 'db_'.fake()->unique()->word(),
            'type' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'admin_user' => 'root',
            'admin_password' => 'secret-admin-password',
            'status' => 'active',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }

    public function postgresql(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'postgresql',
            'port' => 5432,
            'admin_user' => 'postgres',
            'charset' => null,
            'collation' => null,
        ]);
    }
}
