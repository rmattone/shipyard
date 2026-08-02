<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TerminalSessionFactory extends Factory
{
    protected $model = TerminalSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'server_id' => Server::factory(),
            'status' => 'pending',
            'cols' => 80,
            'rows' => 24,
        ];
    }
}
