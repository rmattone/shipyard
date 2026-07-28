<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDeployUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_user_persists_and_defaults_to_null(): void
    {
        $this->createOrgUser();

        $legacy = Server::factory()->create();
        $provisioned = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->assertNull($legacy->fresh()->deploy_user);
        $this->assertSame('shipyard', $provisioned->fresh()->deploy_user);
    }

    public function test_blank_deploy_user_falls_back_to_the_legacy_base(): void
    {
        $this->createOrgUser();

        $server = Server::factory()->create();
        $server->deploy_user = '';

        $this->assertSame('/var/www/shipyard', $server->default_deploy_base);
    }

    public function test_server_json_exposes_default_deploy_base(): void
    {
        $user = $this->createOrgUser();

        $legacy = Server::factory()->create();
        $provisioned = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->actingAs($user)->getJson("/api/servers/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('default_deploy_base', '/var/www/shipyard');

        $this->actingAs($user)->getJson("/api/servers/{$provisioned->id}")
            ->assertOk()
            ->assertJsonPath('deploy_user', 'shipyard')
            ->assertJsonPath('default_deploy_base', '/home/shipyard');
    }
}
