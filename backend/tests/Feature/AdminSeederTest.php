<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private function setAdminEnv(?string $password): void
    {
        foreach (['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        if ($password !== null) {
            putenv("ADMIN_PASSWORD={$password}");
            $_ENV['ADMIN_PASSWORD'] = $password;
        }
    }

    protected function tearDown(): void
    {
        $this->setAdminEnv(null);

        parent::tearDown();
    }

    public function test_seeder_refuses_to_run_without_an_admin_password(): void
    {
        $this->setAdminEnv(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD is not set');

        $this->seed(DatabaseSeeder::class);
    }

    public function test_seeder_creates_the_admin_with_the_provided_password(): void
    {
        $this->setAdminEnv('a-strong-password');

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }
}
