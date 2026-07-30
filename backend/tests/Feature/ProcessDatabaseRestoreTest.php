<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Services\BackupRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessDatabaseRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_never_retries(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $this->assertSame(1, (new ProcessDatabaseRestore($run->id, true))->tries);
    }

    public function test_the_job_delegates_to_the_service(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $service = Mockery::mock(BackupRestoreService::class);
        $service->shouldReceive('restoreFromUpload')
            ->once()
            ->withArgs(fn (BackupRun $passed, bool $overwrite) => $passed->id === $run->id && $overwrite === true);

        (new ProcessDatabaseRestore($run->id, true))->handle($service);
    }

    public function test_failed_marks_the_run_and_deletes_the_upload(): void
    {
        $this->createOrgUser();

        Storage::fake('local');
        Storage::disk('local')->put('restores/orphan.sql.gz', 'x');

        $run = BackupRun::factory()->create([
            'status' => 'running',
            'upload_path' => 'restores/orphan.sql.gz',
        ]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('worker killed'));

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('worker killed', $run->log);
        Storage::disk('local')->assertMissing('restores/orphan.sql.gz');
    }

    public function test_failed_is_a_no_op_for_an_already_finished_run(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'success', 'log' => "done\n"]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('late failure'));

        $this->assertSame('success', $run->fresh()->status);
        $this->assertStringNotContainsString('late failure', $run->fresh()->log);
    }

    public function test_the_job_timeout_covers_the_services_full_sequential_budget(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        // The safety dump and the load run one after another on the overwrite
        // path, not concurrently, so the job must survive their sum, not just
        // whichever of the two is larger. A job timeout that only covered the
        // bigger of the two would still get a well-behaved large restore
        // killed mid-load. This is the assertion that would have caught the
        // job carrying its own hardcoded 1800 while the service's budgets
        // summed to more than that.
        $sequentialBudget = BackupRestoreService::SAFETY_DUMP_TIMEOUT
            + BackupRestoreService::LOAD_TIMEOUT
            + BackupRestoreService::OVERHEAD_TIMEOUT;

        $this->assertGreaterThanOrEqual(
            $sequentialBudget,
            (new ProcessDatabaseRestore($run->id, true))->timeout
        );
    }
}
