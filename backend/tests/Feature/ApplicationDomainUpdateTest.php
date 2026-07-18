<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Models\User;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationDomainUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function mockSsh(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturn(['output' => '', 'exit_code' => 0, 'success' => true]);
        });
    }

    public function test_updating_domain_updates_the_primary_domain_row(): void
    {
        $this->mockSsh();
        $user = User::factory()->create();

        $app = Application::factory()->create(['type' => 'laravel', 'domain' => 'old.test']);
        Domain::factory()->primary()->create(['application_id' => $app->id, 'domain' => 'old.test']);

        $response = $this->actingAs($user)->putJson("/api/applications/{$app->id}", [
            'domain' => 'new.test',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('domains', [
            'application_id' => $app->id,
            'domain' => 'new.test',
            'is_primary' => true,
        ]);
        $this->assertDatabaseMissing('domains', [
            'application_id' => $app->id,
            'domain' => 'old.test',
        ]);
    }

    public function test_updating_domain_creates_a_primary_domain_when_none_exists(): void
    {
        $this->mockSsh();
        $user = User::factory()->create();

        $app = Application::factory()->create(['type' => 'laravel', 'domain' => 'legacy.test']);

        $this->actingAs($user)->putJson("/api/applications/{$app->id}", [
            'domain' => 'fresh.test',
        ])->assertOk();

        $this->assertDatabaseHas('domains', [
            'application_id' => $app->id,
            'domain' => 'fresh.test',
            'is_primary' => true,
        ]);
    }
}
