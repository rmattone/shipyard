<?php

namespace Tests\Feature;

use App\Jobs\RunSystemUpdate;
use App\Models\User;
use App\Services\SystemUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class InstallationAdminTest extends TestCase
{
    use RefreshDatabase;

    private function assertSystemForbidden(): void
    {
        foreach (['version', 'environment', 'update-status'] as $endpoint) {
            $this->getJson('/api/system/'.$endpoint)->assertForbidden();
        }
        $this->postJson('/api/system/update')->assertForbidden();
    }

    public function test_creating_an_organization_cannot_grant_system_access(): void
    {
        Bus::fake();
        $this->mock(SystemUpdateService::class, function ($mock) {
            $mock->shouldNotReceive('start', 'versionInfo', 'markStaleIfNeeded', 'logTail');
        });
        $user = $this->createOrgUser('member');
        $this->actingAs($user);
        $this->assertSystemForbidden();
        $this->postJson('/api/organizations', [
            'name' => 'New organization', 'is_installation_admin' => true,
        ])->assertCreated();
        $this->assertFalse($user->fresh()->is_installation_admin);
        $this->assertSystemForbidden();
        Bus::assertNotDispatched(RunSystemUpdate::class);
    }

    public function test_profile_and_mass_assignment_cannot_grant_admin_access(): void
    {
        $user = $this->createOrgUser();
        $user->fill(['is_installation_admin' => true])->save();
        $this->assertFalse($user->fresh()->is_installation_admin);
        $this->actingAs($user)->putJson('/api/auth/profile', [
            'name' => $user->name, 'email' => $user->email, 'is_installation_admin' => true,
        ])->assertOk()->assertJsonPath('user.is_installation_admin', false);
        $this->assertSystemForbidden();
    }

    public function test_host_console_can_grant_and_revoke_independently_of_organization_role(): void
    {
        $user = $this->createOrgUser('member');
        $this->artisan('shipyard:installation-admin', ['user' => $user->id])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_installation_admin);
        $this->actingAs($user->fresh())->getJson('/api/system/environment')->assertOk();
        $this->getJson('/api/auth/user')->assertOk()->assertJsonPath('is_installation_admin', true);
        $this->artisan('shipyard:installation-admin', ['user' => $user->id, '--revoke' => true])->assertSuccessful();
        $this->actingAs($user->fresh());
        $this->assertSystemForbidden();
    }

    public function test_unknown_user_cannot_be_granted_access(): void
    {
        $this->artisan('shipyard:installation-admin', ['user' => 999999])->assertFailed();
        $this->assertSame(0, User::where('is_installation_admin', true)->count());
    }

    public function test_system_routes_require_authentication(): void
    {
        foreach (['version', 'environment', 'update-status'] as $endpoint) {
            $this->getJson('/api/system/'.$endpoint)->assertUnauthorized();
        }
        $this->postJson('/api/system/update')->assertUnauthorized();
    }
}
