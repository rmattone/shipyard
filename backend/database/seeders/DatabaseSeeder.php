<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('ADMIN_NAME', 'Admin');
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD');

        // Never fall back to a known default password: this panel stores SSH
        // keys and git tokens for every managed server, and a predictable
        // admin credential is the first thing a scanner tries.
        if (empty($password)) {
            throw new RuntimeException(
                'ADMIN_PASSWORD is not set. Refusing to seed an admin user with a default password. '
                .'Set ADMIN_NAME, ADMIN_EMAIL and ADMIN_PASSWORD in backend/.env and run the seeder again '
                .'(install.sh does this automatically).'
            );
        }

        // Only create if user doesn't already exist
        if (! User::where('email', $email)->exists()) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
        }
    }
}
