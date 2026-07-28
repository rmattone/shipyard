<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\DeploymentService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-place (non-atomic) deployment coverage. Kept separate from
 * AtomicDeploymentTest, which exercises the atomic strategy exclusively via
 * its makeAtomicApp() helper and never runs DeploymentService's
 * runInPlaceDeployment()/fixLaravelPermissions() path.
 */
class InPlaceDeploymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * All SSH commands issued during the test, in order.
     *
     * @var array<int, string>
     */
    private array $executedCommands = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeInPlaceApp(string $type, array $serverAttributes = []): Application
    {
        $server = Server::factory()->create($serverAttributes);

        return Application::factory()->create([
            'server_id' => $server->id,
            'type' => $type,
            'deployment_strategy' => 'in_place',
            'git_provider_id' => null,
        ]);
    }

    private function makeDeployment(Application $app): Deployment
    {
        $releaseId = Deployment::generateReleaseId();

        return Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
            'type' => 'deploy',
            'release_id' => $releaseId,
            'release_path' => "{$app->getReleasesPath()}/{$releaseId}",
        ]);
    }

    /**
     * Task 5b: DeploymentService::fixLaravelPermissions (in-place strategy)
     * must follow the same rule as the atomic path's setPermissions.
     */
    public function test_in_place_laravel_deployment_fixes_permissions_for_the_deploy_user_on_provisioned_servers(): void
    {
        $this->mockSsh();

        $app = $this->makeInPlaceApp('laravel', ['deploy_user' => 'shipyard']);
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $chownCommands = array_values(array_filter($this->executedCommands, fn ($c) => str_contains($c, 'chown -R')));

        $this->assertNotEmpty($chownCommands, 'Expected fixLaravelPermissions to issue a chown command.');
        foreach ($chownCommands as $command) {
            $this->assertStringContainsString("chown -R 'shipyard:www-data'", $command);
            $this->assertStringNotContainsString('www-data:www-data', $command);
        }
    }

    public function test_in_place_laravel_deployment_fixes_permissions_for_www_data_on_legacy_servers(): void
    {
        $this->mockSsh();

        $app = $this->makeInPlaceApp('laravel');
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $chownCommands = array_values(array_filter($this->executedCommands, fn ($c) => str_contains($c, 'chown -R')));

        $this->assertNotEmpty($chownCommands, 'Expected fixLaravelPermissions to issue a chown command.');
        foreach ($chownCommands as $command) {
            $this->assertStringContainsString("chown -R 'www-data:www-data'", $command);
            $this->assertStringNotContainsString("chown -R 'shipyard", $command);
        }
    }
}
