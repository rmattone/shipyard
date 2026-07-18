<?php

namespace Tests\Feature;

use App\Jobs\ProcessDeployment;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\DeploymentService;
use App\Services\RollbackService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEPLOY-11: the current symlink swap must be atomic (staged link + rename)
 * and must refuse to replace a real directory. RB-4: post-rollback task
 * failures must fail the rollback while keeping is_active truthful.
 * DEPLOY-14/15: script timeout aligned with the job, no duplicate failure
 * handling in the job's failed() hook.
 */
class RollbackReliabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{command: string, timeout: int}> */
    private array $executed = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

    private function mockSsh(): void
    {
        $this->executed = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command, int $timeout = 300) {
                $this->executed[] = ['command' => $command, 'timeout' => $timeout];

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function commands(): array
    {
        return array_column($this->executed, 'command');
    }

    private function firstCommandContaining(string $needle): ?string
    {
        foreach ($this->commands() as $command) {
            if (str_contains($command, $needle)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * @return array{0: Application, 1: Deployment}
     */
    private function makeAtomicApp(array $attributes = []): array
    {
        $app = Application::factory()->create(array_merge([
            'type' => 'laravel',
            'deployment_strategy' => 'atomic',
            'git_provider_id' => null,
        ], $attributes));

        $deployment = Deployment::factory()->create([
            'application_id' => $app->id,
            'release_id' => '20260716120000-abc123',
            'release_path' => $app->getReleasesPath().'/20260716120000-abc123',
        ]);

        return [$app->fresh(), $deployment->fresh()];
    }

    public function test_release_activation_swaps_via_staged_symlink_and_atomic_rename(): void
    {
        $this->mockSsh();
        [$app, $deployment] = $this->makeAtomicApp();

        app(DeploymentService::class)->runDeployment($deployment);

        $swap = $this->firstCommandContaining('mv -T');
        $this->assertNotNull($swap, 'Activation must rename a staged symlink over current (ln -nfs has a window with no current at all).');
        $this->assertStringContainsString('ln -sfn', $swap);
        $this->assertStringContainsString(escapeshellarg($deployment->release_path), $swap);
        $this->assertStringContainsString(escapeshellarg($app->getCurrentPath()), $swap);
        $this->assertStringContainsString(
            '! -L',
            $swap,
            'The swap must refuse to replace a real directory (ln -nfs silently creates the link inside it).'
        );
    }

    public function test_rollback_uses_the_same_guarded_atomic_swap(): void
    {
        $this->mockSsh();
        $this->fakeResults['test -d'] = ['output' => 'exists', 'exit_code' => 0, 'success' => true];

        [$app] = $this->makeAtomicApp();
        $target = Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'release_id' => '20260715110000-old111',
            'release_path' => $app->getReleasesPath().'/20260715110000-old111',
        ]);
        $rollback = Deployment::factory()->create(['application_id' => $app->id]);

        app(RollbackService::class)->rollback($app, $target, $rollback);

        $swap = $this->firstCommandContaining('mv -T');
        $this->assertNotNull($swap, 'Rollback must use the same atomic staged swap as activation.');
        $this->assertStringContainsString(escapeshellarg($target->release_path), $swap);
    }

    public function test_failed_post_rollback_task_fails_the_rollback_but_keeps_it_active(): void
    {
        $this->mockSsh();
        $this->fakeResults['test -d'] = ['output' => 'exists', 'exit_code' => 0, 'success' => true];
        $this->fakeResults['php artisan optimize 2>&1'] = ['output' => 'config error', 'exit_code' => 1, 'success' => false];

        [$app] = $this->makeAtomicApp();
        $target = Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'release_id' => '20260715110000-old111',
            'release_path' => $app->getReleasesPath().'/20260715110000-old111',
        ]);
        $rollback = Deployment::factory()->create(['application_id' => $app->id]);

        try {
            app(RollbackService::class)->rollback($app, $target, $rollback);
            $this->fail('A failing post-rollback task must fail the rollback (the app may be 500ing).');
        } catch (\RuntimeException) {
            // expected
        }

        $rollback->refresh();
        $this->assertSame('failed', $rollback->status);
        $this->assertTrue(
            (bool) $rollback->is_active,
            'The symlink already swapped, so the rollback release is live and must be tracked as active.'
        );
    }

    public function test_deploy_script_timeout_is_aligned_with_the_job_timeout(): void
    {
        $this->mockSsh();
        [, $deployment] = $this->makeAtomicApp();

        app(DeploymentService::class)->runDeployment($deployment);

        $scriptRuns = array_values(array_filter(
            $this->executed,
            fn ($e) => str_starts_with($e['command'], "bash '/tmp/shipyard-script-")
        ));
        $this->assertNotEmpty($scriptRuns, 'Expected the deploy script to run.');
        $this->assertGreaterThanOrEqual(
            ProcessDeployment::TIMEOUT_SECONDS - 600,
            $scriptRuns[0]['timeout'],
            'A 600s script timeout under a 1800s job timeout fails long composer/npm builds spuriously.'
        );
    }

    public function test_failed_hook_does_not_duplicate_service_side_failure_handling(): void
    {
        [$app] = $this->makeAtomicApp();
        $deployment = Deployment::factory()->failed()->create([
            'application_id' => $app->id,
            'log' => 'ERROR: original failure',
        ]);

        (new ProcessDeployment($deployment))->failed(new \RuntimeException('job crashed'));

        $deployment->refresh();
        $this->assertSame(
            1,
            substr_count($deployment->log, 'ERROR:'),
            'failed() must not re-log a failure the service already recorded.'
        );
    }

    public function test_failed_hook_still_covers_worker_death(): void
    {
        [$app] = $this->makeAtomicApp();
        $deployment = Deployment::factory()->running()->create(['application_id' => $app->id]);

        (new ProcessDeployment($deployment))->failed(new \RuntimeException('worker killed'));

        $deployment->refresh();
        $this->assertSame('failed', $deployment->status);
        $this->assertStringContainsString('worker killed', (string) $deployment->log);
    }
}
