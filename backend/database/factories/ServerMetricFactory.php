<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerMetricFactory extends Factory
{
    protected $model = ServerMetric::class;

    public function definition(): array
    {
        $memoryTotal = 4 * 1024 ** 3;
        $memoryUsed = fake()->numberBetween(1 * 1024 ** 3, 3 * 1024 ** 3);
        $diskTotal = 80 * 1024 ** 3;
        $diskUsed = fake()->numberBetween(20 * 1024 ** 3, 60 * 1024 ** 3);

        return [
            'server_id' => Server::factory(),
            'collected_at' => now(),
            'cpu_percent' => fake()->randomFloat(1, 0, 100),
            'cpu_cores' => 2,
            'memory_total' => $memoryTotal,
            'memory_used' => $memoryUsed,
            'memory_percent' => round($memoryUsed / $memoryTotal * 100, 1),
            'swap_total' => 0,
            'swap_used' => 0,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'disk_percent' => round($diskUsed / $diskTotal * 100, 1),
            'load_1' => fake()->randomFloat(2, 0, 4),
            'load_5' => fake()->randomFloat(2, 0, 4),
            'load_15' => fake()->randomFloat(2, 0, 4),
        ];
    }
}
