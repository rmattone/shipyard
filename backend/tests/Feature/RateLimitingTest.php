<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_throttled_after_five_attempts(): void
    {
        User::factory()->create(['email' => 'admin@shipyard.test']);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'admin@shipyard.test',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@shipyard.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429);
    }

    public function test_api_routes_carry_the_rate_limit_headers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/servers');

        $response->assertOk();
        $response->assertHeader('X-RateLimit-Limit', 120);
    }
}
