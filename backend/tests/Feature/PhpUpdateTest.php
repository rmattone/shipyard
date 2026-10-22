<?php

namespace Tests\Feature;

use App\Jobs\ProcessPhpUpdate;
use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\PhpUpdateService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PhpUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_an_update_but_duplicate_and_other_install_requests_are_rejected(): void
    {
        Queue::fake();
        $user = $this->createOrgUser('admin');
        $server = Server::factory()->create(['php_version' => '8.3']);

        $response = $this->actingAs($user)->postJson("/api/servers/{$server->id}/php-updates", ['branch' => '8.4'])
            ->assertAccepted()->assertJsonPath('version_requested', '8.4');
        Queue::assertPushed(ProcessPhpUpdate::class, fn ($job) => $job->installation->id === $response->json('id'));
        $this->assertSame('8.3', $server->fresh()->php_version);

        $this->postJson("/api/servers/{$server->id}/php-updates", ['branch' => '8.3'])->assertConflict();
        $this->postJson("/api/servers/{$server->id}/databases/install", ['engine' => 'nginx'])->assertConflict();
        $this->assertSame(1, $server->databaseInstallations()->count());
    }

    public function test_an_existing_installation_blocks_a_php_update(): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $installation = DatabaseInstallation::factory()->create(['engine' => 'nginx']);
        $this->actingAs($user)->postJson("/api/servers/{$installation->server_id}/php-updates", ['branch' => '8.4'])
            ->assertConflict();
        Queue::assertNothingPushed();
    }

    public function test_members_and_unauthenticated_users_cannot_update_php(): void
    {
        Queue::fake();
        $member = $this->createOrgUser('member');
        $server = Server::factory()->create();
        $this->postJson("/api/servers/{$server->id}/php-updates", ['branch' => '8.4'])->assertUnauthorized();
        $this->actingAs($member)->postJson("/api/servers/{$server->id}/php-updates", ['branch' => '8.4'])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_another_organizations_server_and_operation_are_not_accessible(): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $this->createOrgUser();
        $installation = DatabaseInstallation::factory()->create(['engine' => 'php', 'version_requested' => '8.4']);

        $this->actingAs($user)->getJson("/api/servers/{$installation->server_id}/php-updates")->assertNotFound();
        $this->postJson("/api/servers/{$installation->server_id}/php-updates", ['branch' => '8.4'])->assertNotFound();
        $this->getJson("/api/database-installations/{$installation->id}")->assertNotFound();
        Queue::assertNothingPushed();
    }

    #[DataProvider('invalidBranches')]
    public function test_only_major_minor_branches_are_accepted(string $branch): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $this->actingAs($user)->postJson("/api/servers/{$server->id}/php-updates", ['branch' => $branch])
            ->assertUnprocessable()->assertJsonValidationErrors('branch');
        Queue::assertNothingPushed();
    }

    public static function invalidBranches(): array
    {
        return [['8.4.27'], ['8.4;id'], ["8.\n4"], ['--latest'], ['8x4']];
    }

    public function test_inventory_includes_all_branches_and_package_revisions_without_changing_default_php(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['php_version' => '8.3']);
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->once()->andReturnSelf();
            $mock->shouldReceive('disconnect')->once();
            $mock->shouldReceive('execute')->once()->withArgs(fn ($command) => str_contains($command, '/etc/os-release'))
                ->andReturn(['success' => true, 'output' => '', 'exit_code' => 0]);
            $mock->shouldReceive('execute')->once()->withArgs(fn ($command) => str_starts_with($command, 'dpkg-query'))
                ->andReturn(['success' => true, 'exit_code' => 0, 'output' => implode("\n", [
                    "php8.4-common:amd64\t8.4.24-1ubuntu1.2\tinstalled",
                    "php8.4-fpm\t8.4.24-1ubuntu1.2\tinstalled",
                    "php8.4-redis\t6.2.0-1\tinstalled",
                    "libapache2-mod-php8.2\t8.2.35-1\tinstalled",
                    "php8.1-fpm\t8.1.2-1\tconfig-files",
                    "php-fpm\t2:8.4\tinstalled",
                    "nginx\t1.24\tinstalled",
                ])]);
        });

        $this->actingAs($user)->getJson("/api/servers/{$server->id}/php-updates")->assertOk()
            ->assertJsonCount(2, 'branches')
            ->assertJsonPath('branches.0.branch', '8.4')
            ->assertJsonCount(3, 'branches.0.packages')
            ->assertJsonPath('branches.0.packages.0.version', '8.4.24-1ubuntu1.2')
            ->assertJsonPath('branches.1.branch', '8.2');
        $this->assertSame('8.3', $server->fresh()->php_version);
    }

    public function test_success_records_verified_package_version_and_uses_noninteractive_sudo(): void
    {
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => Server::factory()->create(['username' => 'deploy', 'php_version' => '8.4'])->id,
            'engine' => 'php', 'version_requested' => '8.4',
        ]);
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->once()->andReturnSelf();
            $mock->shouldReceive('disconnect')->once();
            $mock->shouldReceive('execute')->once()->withArgs(fn ($command, $timeout) => str_starts_with($command, 'sudo -n bash -c ') && str_ends_with($command, " -- '8.4'") && $timeout === 1500
            )->andReturn(['success' => true, 'exit_code' => 0, 'output' => "Verified active: php8.4-fpm.service\nSHIPYARD_PHP_UPDATED=8.4.27-1ubuntu1\n"]);
        });

        (new ProcessPhpUpdate($installation))->handle(app(PhpUpdateService::class));
        $this->assertSame('success', $installation->fresh()->status);
        $this->assertSame('8.4.27-1ubuntu1', $installation->fresh()->version_installed);
    }

    #[DataProvider('failedResults')]
    public function test_failed_or_unverified_remote_updates_are_never_marked_successful(array $result): void
    {
        $installation = DatabaseInstallation::factory()->create(['engine' => 'php', 'version_requested' => '8.4']);
        $this->mock(SSHService::class, function ($mock) use ($result) {
            $mock->shouldReceive('connect')->once()->andReturnSelf();
            $mock->shouldReceive('disconnect')->once();
            $mock->shouldReceive('execute')->once()->andReturn($result);
        });
        $job = new ProcessPhpUpdate($installation);
        try {
            $job->handle(app(PhpUpdateService::class));
            $this->fail('An unverified update must fail.');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }
        $this->assertSame('failed', $installation->fresh()->status);
        $this->assertNull($installation->fresh()->version_installed);
        $this->assertStringContainsString('ERROR:', $installation->fresh()->log);
    }

    public static function failedResults(): array
    {
        return [
            [['success' => false, 'exit_code' => 1, 'output' => 'FPM restart failed.']],
            [['success' => false, 'exit_code' => -1, 'output' => 'command timed out']],
            [['success' => true, 'exit_code' => 0, 'output' => 'No verification marker.']],
        ];
    }
}
