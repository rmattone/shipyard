<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Daemon;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\DeploymentService;
use App\Services\RollbackService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * With atomic deploys, workers keep executing the previous release's code
 * until restarted, so a successful deploy (and rollback) must bounce every
 * daemon linked to the app. A failed restart must NOT fail the deploy: by
 * then the symlink has already swapped, so the release is live regardless.
 */
class DeploymentDaemonRestartTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<string, array> */
    private array $fakeResults = [];

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

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function indexOfCommandContaining(string $needle): ?int
    {
        foreach ($this->executedCommands as $index => $command) {
            if (str_contains($command, $needle)) {
                return $index;
            }
        }

        return null;
    }

    private function makeApp(string $strategy = 'atomic'): Application
    {
        return Application::factory()->create([
            'server_id' => Server::factory(),
            'type' => 'laravel',
            'deployment_strategy' => $strategy,
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

    public function test_atomic_deploy_restarts_linked_daemons_after_activation(): void
    {
        $this->mockSsh();

        $app = $this->makeApp();
        $daemon = Daemon::factory()->multiProcess(2)->create([
            'server_id' => $app->server_id,
            'application_id' => $app->id,
        ]);
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $activationIndex = $this->indexOfCommandContaining('mv -T');
        $restartIndex = $this->indexOfCommandContaining(
            "systemctl restart shipyard-daemon-{$daemon->id}@1.service shipyard-daemon-{$daemon->id}@2.service"
        );

        $this->assertNotNull($activationIndex, 'Expected a symlink activation command.');
        $this->assertNotNull($restartIndex, 'Expected linked daemons to be restarted.');
        $this->assertGreaterThan($activationIndex, $restartIndex, 'Daemon restart must happen after activation.');
        $this->assertSame('success', $deployment->fresh()->status);
        $this->assertStringContainsString('Restarting 1 linked daemon(s)', $deployment->fresh()->log);
    }

    public function test_daemon_restart_failure_does_not_fail_the_deploy(): void
    {
        $this->mockSsh();
        $this->fakeResults['systemctl restart'] = ['output' => 'unit not found', 'exit_code' => 1, 'success' => false];

        $app = $this->makeApp();
        Daemon::factory()->create(['server_id' => $app->server_id, 'application_id' => $app->id]);
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $fresh = $deployment->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertStringContainsString('WARNING: daemon restart failed', $fresh->log);
    }

    public function test_unlinked_and_non_installed_daemons_are_not_restarted(): void
    {
        $this->mockSsh();

        $app = $this->makeApp();
        $unlinked = Daemon::factory()->create(['server_id' => $app->server_id]);
        $installing = Daemon::factory()->installing()->create([
            'server_id' => $app->server_id,
            'application_id' => $app->id,
        ]);
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $this->assertNull($this->indexOfCommandContaining("shipyard-daemon-{$unlinked->id}@"));
        $this->assertNull($this->indexOfCommandContaining("shipyard-daemon-{$installing->id}@"));
        $this->assertSame('success', $deployment->fresh()->status);
    }

    public function test_in_place_deploy_restarts_linked_daemons(): void
    {
        $this->mockSsh();

        $app = $this->makeApp('in_place');
        $daemon = Daemon::factory()->create(['server_id' => $app->server_id, 'application_id' => $app->id]);
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $this->assertNotNull($this->indexOfCommandContaining("systemctl restart shipyard-daemon-{$daemon->id}@1.service"));
        $this->assertSame('success', $deployment->fresh()->status);
    }

    public function test_rollback_restarts_linked_daemons(): void
    {
        $this->mockSsh();
        $this->fakeResults['test -d'] = ['output' => 'exists', 'exit_code' => 0, 'success' => true];

        $app = $this->makeApp();
        $daemon = Daemon::factory()->create(['server_id' => $app->server_id, 'application_id' => $app->id]);

        $target = Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'release_id' => '20260715110000-old111',
            'release_path' => $app->getReleasesPath().'/20260715110000-old111',
        ]);
        $rollback = Deployment::factory()->create(['application_id' => $app->id]);

        app(RollbackService::class)->rollback($app, $target, $rollback);

        $this->assertNotNull($this->indexOfCommandContaining("systemctl restart shipyard-daemon-{$daemon->id}@1.service"));
    }
}
