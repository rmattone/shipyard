<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Services\RollbackService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RollbackTargetSelectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    private ?string $currentSymlinkTarget = null;

    private function mockSsh(): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                if (str_starts_with($command, 'readlink')) {
                    return ['output' => $this->currentSymlinkTarget."\n", 'exit_code' => 0, 'success' => true];
                }
                if (str_contains($command, "echo 'exists'") || str_contains($command, 'test -d')) {
                    return ['output' => 'exists', 'exit_code' => 0, 'success' => true];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeRelease(Application $app, string $id, bool $active = false): Deployment
    {
        return Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'release_id' => $id,
            'release_path' => "{$app->getReleasesPath()}/{$id}",
            'is_active' => $active,
            'type' => 'deploy',
        ]);
    }

    public function test_rollback_to_previous_selects_the_release_before_the_live_one(): void
    {
        $this->mockSsh();

        $app = Application::factory()->create(['type' => 'laravel', 'deployment_strategy' => 'atomic']);
        $r1 = $this->makeRelease($app, '20260715010000-aaa');
        $r2 = $this->makeRelease($app, '20260715020000-bbb');
        $r3 = $this->makeRelease($app, '20260715030000-ccc', active: true);

        $this->currentSymlinkTarget = $r3->release_path;

        $rollbackRecord = Deployment::factory()->create([
            'application_id' => $app->id,
            'type' => 'rollback',
            'status' => 'pending',
        ]);

        app(RollbackService::class)->rollbackToPrevious($app, $rollbackRecord);

        $rollbackRecord->refresh();
        $this->assertSame($r2->release_id, $rollbackRecord->release_id, 'Rollback should go to the release immediately before the live one.');
    }

    public function test_rollback_to_previous_does_not_ping_pong_back_to_the_newest_release(): void
    {
        $this->mockSsh();

        $app = Application::factory()->create(['type' => 'laravel', 'deployment_strategy' => 'atomic']);
        $r1 = $this->makeRelease($app, '20260715010000-aaa');
        $r2 = $this->makeRelease($app, '20260715020000-bbb');
        $r3 = $this->makeRelease($app, '20260715030000-ccc');

        // Simulate that we already rolled back from R3 to R2: a rollback record
        // is the active deployment and the symlink points at R2.
        Deployment::factory()->success()->create([
            'application_id' => $app->id,
            'type' => 'rollback',
            'release_id' => $r2->release_id,
            'release_path' => $r2->release_path,
            'is_active' => true,
        ]);
        $this->currentSymlinkTarget = $r2->release_path;

        $rollbackRecord = Deployment::factory()->create([
            'application_id' => $app->id,
            'type' => 'rollback',
            'status' => 'pending',
        ]);

        app(RollbackService::class)->rollbackToPrevious($app, $rollbackRecord);

        $rollbackRecord->refresh();
        $this->assertSame($r1->release_id, $rollbackRecord->release_id, 'A second rollback-to-previous must continue backwards to R1, not jump forward to R3.');
    }
}
