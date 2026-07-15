<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\AtomicDeploymentService;
use App\Services\DeploymentService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtomicDeploymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * All SSH commands issued during the test, in order.
     *
     * @var array<int, string>
     */
    private array $executedCommands = [];

    /**
     * Map of command-prefix => fake result, checked before the default success result.
     *
     * @var array<string, array>
     */
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

                foreach ($this->fakeResults as $prefix => $result) {
                    if (str_starts_with($command, $prefix)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeAtomicApp(string $type): Application
    {
        return Application::factory()->create([
            'server_id' => Server::factory(),
            'type' => $type,
            'deployment_strategy' => 'atomic',
            'releases_to_keep' => 5,
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

    public function test_atomic_nodejs_deployment_restarts_pm2_after_activation(): void
    {
        $this->mockSsh();

        $app = $this->makeAtomicApp('nodejs');
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $slug = Str::slug($app->name);

        $activationIndex = $this->indexOfCommandContaining('ln -nfs');
        $pm2Index = $this->indexOfCommandContaining("pm2 restart {$slug}");

        $this->assertNotNull($activationIndex, 'Expected a symlink activation command.');
        $this->assertNotNull($pm2Index, 'Expected a PM2 restart command for Node.js atomic deployments.');
        $this->assertGreaterThan($activationIndex, $pm2Index, 'PM2 restart must happen after symlink activation.');

        $pm2Command = $this->executedCommands[$pm2Index];
        $this->assertStringContainsString('nvm.sh', $pm2Command, 'PM2 command must source nvm for non-interactive SSH sessions.');
        $this->assertStringContainsString("cd {$app->getCurrentPath()}", $pm2Command);
        $this->assertStringContainsString("pm2 start npm --name \"{$slug}\" -- start", $pm2Command);

        $this->assertSame('success', $deployment->fresh()->status);
    }

    public function test_atomic_laravel_deployment_does_not_touch_pm2(): void
    {
        $this->mockSsh();

        $app = $this->makeAtomicApp('laravel');
        $deployment = $this->makeDeployment($app);

        app(DeploymentService::class)->runDeployment($deployment);

        $this->assertNull(
            $this->indexOfCommandContaining('pm2 '),
            'Laravel deployments must not issue PM2 commands.'
        );
        $this->assertSame('success', $deployment->fresh()->status);
    }

    public function test_cleanup_removes_oldest_releases_using_absolute_paths(): void
    {
        $this->mockSsh();

        $app = $this->makeAtomicApp('nodejs');
        $deployment = $this->makeDeployment($app);
        $releasesPath = $app->getReleasesPath();

        // 7 releases, newest first (mirrors `ls -1d ... | sort -r` output with trailing slashes)
        $ids = ['20260715070000', '20260715060000', '20260715050000', '20260715040000', '20260715030000', '20260715020000', '20260715010000'];
        $listing = implode("\n", array_map(fn ($id) => "{$releasesPath}/{$id}/", $ids))."\n";

        $this->fakeResults['ls -1d'] = ['output' => $listing, 'exit_code' => 0, 'success' => true];
        $this->fakeResults['readlink'] = ['output' => "{$releasesPath}/{$ids[0]}\n", 'exit_code' => 0, 'success' => true];

        app(AtomicDeploymentService::class)->cleanupOldReleases($app, $deployment);

        $removals = array_values(array_filter($this->executedCommands, fn ($c) => str_starts_with($c, 'rm -rf')));

        $this->assertSame([
            "rm -rf {$releasesPath}/20260715020000",
            "rm -rf {$releasesPath}/20260715010000",
        ], $removals, 'Cleanup must delete exactly the two oldest releases, by absolute path.');
    }

    public function test_cleanup_never_deletes_the_active_release(): void
    {
        $this->mockSsh();

        $app = $this->makeAtomicApp('nodejs');
        $deployment = $this->makeDeployment($app);
        $releasesPath = $app->getReleasesPath();

        $ids = ['20260715070000', '20260715060000', '20260715050000', '20260715040000', '20260715030000', '20260715020000', '20260715010000'];
        $listing = implode("\n", array_map(fn ($id) => "{$releasesPath}/{$id}/", $ids))."\n";

        $this->fakeResults['ls -1d'] = ['output' => $listing, 'exit_code' => 0, 'success' => true];
        // The current symlink points at a release that is beyond the retention window
        $this->fakeResults['readlink'] = ['output' => "{$releasesPath}/20260715010000\n", 'exit_code' => 0, 'success' => true];

        app(AtomicDeploymentService::class)->cleanupOldReleases($app, $deployment);

        $removals = array_values(array_filter($this->executedCommands, fn ($c) => str_starts_with($c, 'rm -rf')));

        $this->assertSame(
            ["rm -rf {$releasesPath}/20260715020000"],
            $removals,
            'Cleanup must skip the release the current symlink points to.'
        );
    }

    private function indexOfCommandContaining(string $needle): ?int
    {
        foreach ($this->executedCommands as $i => $command) {
            if (str_contains($command, $needle)) {
                return $i;
            }
        }

        return null;
    }
}
