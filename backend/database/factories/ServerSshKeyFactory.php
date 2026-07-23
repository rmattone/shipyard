<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerSshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerSshKeyFactory extends Factory
{
    protected $model = ServerSshKey::class;

    /**
     * Real ed25519 keypair generated for fixture purposes only
     * (`ssh-keygen -t ed25519 -C 'fixture@test'`); the private half was
     * discarded, only the public key is embedded here.
     */
    private const PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJXbp/Y2XN4epry86K+ta2J0crTp0A+rhEf3Q8MdIbcj fixture@test';

    public function definition(): array
    {
        $blob = explode(' ', self::PUBLIC_KEY)[1];

        return [
            'server_id' => Server::factory(),
            'name' => fake()->unique()->word().' key',
            'public_key' => self::PUBLIC_KEY,
            'fingerprint' => hash('sha256', base64_decode($blob)),
            'username' => 'deploy',
            'status' => 'installed',
        ];
    }

    public function installing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'installing',
        ]);
    }

    public function removing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'removing',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error' => 'Permission denied (publickey).',
        ]);
    }
}
