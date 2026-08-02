<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Models\Database;
use App\Services\BackupRestoreService;
use App\Services\SSHService;
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

        // Not 'restore': failed() cannot know which step the job was on when
        // its worker died or its own timeout fired, so it must not guess.
        $this->assertNull($run->failed_step);
    }

    public function test_failed_appends_the_safety_dump_recovery_note_when_one_exists(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create([
            'status' => 'running',
            'safety_dump_path' => '/var/backups/shipyard/shop-20260730-120000.sql.gz',
        ]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('timed out'));

        $run->refresh();

        $this->assertStringContainsString('unknown state', $run->log);
        $this->assertStringContainsString('/var/backups/shipyard/shop-20260730-120000.sql.gz', $run->log);
    }

    public function test_failed_does_not_mention_a_safety_dump_that_was_never_taken(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'running', 'safety_dump_path' => null]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('timed out'));

        $this->assertStringNotContainsString('unknown state', $run->fresh()->log);
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

    public function test_two_deliveries_of_the_same_job_produce_exactly_one_restore(): void
    {
        // This is the single property the whole claim() guard exists for,
        // exercised end to end through the real job and the real service
        // (not a mock of either), with only SSH faked. A queue redelivering
        // the same job (e.g. a reservation reclaimed under a too-low
        // retry_after while the first attempt is still running) must result
        // in exactly one restore, not two.
        $this->createOrgUser();

        Storage::fake('local');
        Storage::disk('local')->put('restores/dump.sql.gz', gzencode('SELECT 1;'));

        $database = Database::factory()->create([
            'type' => 'postgresql',
            'admin_user' => 'postgres',
            'admin_password' => 'secret',
        ]);

        $run = BackupRun::factory()->create([
            'database_id' => $database->id,
            'database_name' => 'shop',
            'format' => BackupRun::FORMAT_SQL_GZ,
            'upload_path' => 'restores/dump.sql.gz',
            'status' => 'pending',
        ]);

        $executed = [];
        $this->mock(SSHService::class, function ($mock) use (&$executed) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('upload')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command, int $timeout = 300) use (&$executed) {
                $executed[] = $command;

                if (str_contains($command, 'pg_tables')) {
                    return ['output' => '5', 'exit_code' => 0, 'success' => true];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });

        $service = app(BackupRestoreService::class);

        (new ProcessDatabaseRestore($run->id, true))->handle($service);

        $this->assertSame('success', $run->fresh()->status);
        $firstDeliveryCommandCount = count($executed);
        $this->assertGreaterThan(0, $firstDeliveryCommandCount, 'expected the first delivery to actually run SSH commands');

        // A second delivery of the exact same job: the run is now already
        // 'success', so claim() must lose and handle() must return before
        // issuing a single SSH command.
        (new ProcessDatabaseRestore($run->id, true))->handle($service);

        $this->assertSame(
            $firstDeliveryCommandCount,
            count($executed),
            'the second delivery must not have run any additional SSH command'
        );
        $this->assertSame('success', $run->fresh()->status);
    }
}
