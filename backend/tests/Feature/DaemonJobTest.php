<?php

namespace Tests\Feature;

use App\Jobs\ProcessDaemonInstall;
use App\Jobs\ProcessDaemonRemoval;
use App\Models\Daemon;
use App\Services\SystemdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class DaemonJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_job_marks_the_daemon_installed_on_success(): void
    {
        $daemon = Daemon::factory()->installing()->create();

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('installDaemon')->once();
        });

        (new ProcessDaemonInstall($daemon))->handle(app(SystemdService::class));

        $this->assertSame('installed', $daemon->fresh()->status);
    }

    public function test_install_job_failure_marks_the_daemon_failed_with_the_error(): void
    {
        $daemon = Daemon::factory()->installing()->create();

        (new ProcessDaemonInstall($daemon))->failed(new \RuntimeException('tee: permission denied'));

        $fresh = $daemon->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('tee: permission denied', $fresh->log);
    }

    public function test_removal_job_deletes_the_row_only_after_the_units_are_removed(): void
    {
        $daemon = Daemon::factory()->create(['status' => 'removing']);

        $this->mock(SystemdService::class, function ($mock) {
            $mock->shouldReceive('removeDaemon')->once();
        });

        (new ProcessDaemonRemoval($daemon))->handle(app(SystemdService::class));

        $this->assertDatabaseMissing('daemons', ['id' => $daemon->id]);
    }

    public function test_removal_job_failure_keeps_the_row_and_marks_it_failed(): void
    {
        $daemon = Daemon::factory()->create(['status' => 'removing']);

        (new ProcessDaemonRemoval($daemon))->failed(new \RuntimeException('ssh unreachable'));

        $fresh = $daemon->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('ssh unreachable', $fresh->log);
    }

    public function test_both_jobs_share_one_overlap_lock_per_daemon(): void
    {
        $daemon = Daemon::factory()->create();

        foreach ([new ProcessDaemonInstall($daemon), new ProcessDaemonRemoval($daemon)] as $job) {
            $middleware = $job->middleware();

            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
            $this->assertSame("systemd-daemon:{$daemon->id}", $middleware[0]->key);
            $this->assertTrue($middleware[0]->shareKey);
        }
    }

    public function test_jobs_survive_the_daemon_row_disappearing_while_queued(): void
    {
        $daemon = Daemon::factory()->create();
        $install = new ProcessDaemonInstall($daemon);

        $daemon->delete();

        $this->assertTrue($install->deleteWhenMissingModels);
        $install->failed(new \RuntimeException('late failure'));
        $this->assertDatabaseMissing('daemons', ['id' => $daemon->id]);
    }
}
