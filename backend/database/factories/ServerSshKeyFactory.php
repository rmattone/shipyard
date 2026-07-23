<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerSshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerSshKeyFactory extends Factory
{
    protected $model = ServerSshKey::class;

    public function definition(): array
    {
        // Real ed25519 keypair generated per instance so factory-created
        // keys don't collide on unique(server_id, username, fingerprint).
        // The "blob" is the same wire format ssh-keygen produces: it's
        // what fingerprint is hashed from and what's base64-encoded into
        // the public key line's second field.
        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).$publicKey;

        return [
            'server_id' => Server::factory(),
            'name' => fake()->words(2, true),
            'public_key' => 'ssh-ed25519 '.base64_encode($blob).' fixture@test',
            'fingerprint' => hash('sha256', $blob),
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
