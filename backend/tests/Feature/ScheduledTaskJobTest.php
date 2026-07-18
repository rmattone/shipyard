<?php

namespace Tests\Feature;

use App\Jobs\ProcessScheduledTaskInstall;
use App\Jobs\ProcessScheduledTaskRemoval;
use App\Models\ScheduledTask;
use App\Services\CrontabService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class ScheduledTaskJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_job_marks_the_task_installed_on_success(): void
    {
        $task = ScheduledTask::factory()->installing()->create();

        $this->mock(CrontabService::class, function ($mock) {
            $mock->shouldReceive('installTask')->once();
        });

        (new ProcessScheduledTaskInstall($task))->handle(app(CrontabService::class));

        $this->assertSame('installed', $task->fresh()->status);
    }

    public function test_install_job_failure_marks_the_task_failed_with_the_error(): void
    {
        $task = ScheduledTask::factory()->installing()->create();

        (new ProcessScheduledTaskInstall($task))->failed(new \RuntimeException('chown: invalid user'));

        $fresh = $task->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('chown: invalid user', $fresh->log);
    }

    public function test_removal_job_deletes_the_row_only_after_the_sync_succeeds(): void
    {
        $task = ScheduledTask::factory()->create(['status' => 'removing']);

        $this->mock(CrontabService::class, function ($mock) {
            $mock->shouldReceive('removeTask')->once();
        });

        (new ProcessScheduledTaskRemoval($task))->handle(app(CrontabService::class));

        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $task->id]);
    }

    public function test_removal_job_failure_keeps_the_row_and_marks_it_failed(): void
    {
        $task = ScheduledTask::factory()->create(['status' => 'removing']);

        (new ProcessScheduledTaskRemoval($task))->failed(new \RuntimeException('ssh unreachable'));

        $fresh = $task->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('ssh unreachable', $fresh->log);
    }

    public function test_both_jobs_share_one_overlap_lock_per_server_and_user(): void
    {
        $task = ScheduledTask::factory()->create(['user' => 'www-data']);

        foreach ([new ProcessScheduledTaskInstall($task), new ProcessScheduledTaskRemoval($task)] as $job) {
            $middleware = $job->middleware();

            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
            $this->assertSame("crontab:{$task->server_id}:www-data", $middleware[0]->key);
            $this->assertTrue($middleware[0]->shareKey);
        }
    }

    public function test_jobs_survive_the_task_row_disappearing_while_queued(): void
    {
        $task = ScheduledTask::factory()->create();
        $install = new ProcessScheduledTaskInstall($task);

        $task->delete();

        $this->assertTrue($install->deleteWhenMissingModels);
        // failed() after the row is gone must not blow up.
        $install->failed(new \RuntimeException('late failure'));
        $this->assertDatabaseMissing('scheduled_tasks', ['id' => $task->id]);
    }
}
