<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Database;
use App\Services\BackupRestoreService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupRestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
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
            $mock->shouldReceive('upload')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command, int $timeout = 300) {
                $this->executed[] = $command;

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        // A 'throw' entry simulates a dead SSH connection (e.g.
                        // the server vanished mid-restore) rather than a command
                        // that ran and reported failure.
                        if ($result['throw'] ?? false) {
                            throw new \RuntimeException($result['output'] ?? 'SSH command could not be executed');
                        }

                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function indexOfCommandContaining(string $needle): ?int
    {
        foreach ($this->executed as $index => $command) {
            if (str_contains($command, $needle)) {
                return $index;
            }
        }

        return null;
    }

    private function firstCommandContaining(string $needle): ?string
    {
        foreach ($this->executed as $command) {
            if (str_contains($command, $needle)) {
                return $command;
            }
        }

        return null;
    }

    private function makeRun(array $attributes = [], array $databaseAttributes = []): BackupRun
    {
        $this->createOrgUser();

        Storage::fake('local');
        Storage::disk('local')->put('restores/dump.sql.gz', gzencode('SELECT 1;'));

        $database = Database::factory()->create(array_merge([
            'type' => 'postgresql',
            'admin_user' => 'postgres',
            'admin_password' => 'secret',
        ], $databaseAttributes));

        return BackupRun::factory()->create(array_merge([
            'database_id' => $database->id,
            'database_name' => 'shop',
            'format' => BackupRun::FORMAT_SQL_GZ,
            'upload_path' => 'restores/dump.sql.gz',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_the_safety_dump_directory_is_owned_by_the_connecting_user(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $mkdir = $this->firstCommandContaining('mkdir -p');

        // The dump is written by a plain shell redirect as the SSH user, so a
        // root-owned directory makes it fail with "Permission denied" before
        // pg_dump runs. Caught only on a real server, never by a mock.
        $this->assertNotNull($mkdir, 'expected the safety dump directory to be created');
        $this->assertStringContainsString('chown "$(id -un)":"$(id -gn)"', $mkdir);
        $this->assertStringContainsString('chmod 700', $mkdir);
    }

    public function test_pruning_does_not_need_sudo(): void
    {
        $this->mockSsh();
        // Pruning only runs on the success path, and verification is assertive,
        // so the table count has to come back non-zero to get there.
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $prune = $this->firstCommandContaining('tail -n +');

        // The connecting user owns the directory and every dump in it, so
        // elevating here would only mask an ownership mistake.
        $this->assertNotNull($prune, 'expected a prune command');
        $this->assertStringNotContainsString('sudo', $prune);
    }

    public function test_safety_dump_runs_before_the_drop(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $dumpAt = $this->indexOfCommandContaining('pg_dump');
        $dropAt = $this->indexOfCommandContaining('DROP DATABASE');

        $this->assertNotNull($dumpAt, 'expected a safety dump command');
        $this->assertNotNull($dropAt, 'expected a drop command');
        $this->assertLessThan($dropAt, $dumpAt, 'the safety dump must precede the drop');
    }

    public function test_overwrite_records_the_safety_dump_path_and_succeeds(): void
    {
        $this->mockSsh();
        // Without a real count, verify()'s own default fake result (empty
        // output, success) is indistinguishable from a query that failed to
        // return anything, and verify() must treat that as unverified rather
        // than as success (see test_a_load_that_produces_no_tables_fails_the_run).
        // A genuinely successful restore needs a genuine, non-zero count.
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('success', $run->status);
        $this->assertStringContainsString('/var/backups/shipyard/', $run->safety_dump_path);
        $this->assertStringContainsString('Restore completed', $run->log);
    }

    public function test_restore_to_a_new_name_skips_the_safety_dump(): void
    {
        $this->mockSsh();
        // See the comment in test_overwrite_records_the_safety_dump_path_and_succeeds:
        // verify() needs a genuine non-zero count to report success.
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun(['database_name' => 'shop_copy']);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: false);

        $this->assertNull($this->indexOfCommandContaining('pg_dump'));
        $this->assertNull($this->indexOfCommandContaining('DROP DATABASE'));
        $this->assertNotNull($this->indexOfCommandContaining('CREATE DATABASE'));
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_a_failed_load_fails_the_run_and_names_the_safety_dump(): void
    {
        $this->mockSsh();
        $this->fakeResults['ON_ERROR_STOP'] = [
            'output' => 'ERROR:  syntax error at or near "GARBAGE"',
            'exit_code' => 3,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertStringContainsString('unknown state', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_load_that_produces_no_tables_fails_the_run(): void
    {
        $this->mockSsh();
        // The load command succeeds (an empty dump exits 0), but verification
        // finds nothing. This must fail rather than report success, because the
        // target was already dropped.
        $this->fakeResults['pg_tables'] = ['output' => '0', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('produced no tables', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_failed_load_to_a_new_name_is_still_labelled_a_restore_failure(): void
    {
        $this->mockSsh();
        $this->fakeResults['ON_ERROR_STOP'] = [
            'output' => 'ERROR:  syntax error',
            'exit_code' => 3,
            'success' => false,
        ];
        $run = $this->makeRun(['database_name' => 'shop_copy']);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: false);

        $run->refresh();

        // This path never takes a safety dump, so failed_step must come from
        // the tracked step rather than being inferred from its absence.
        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertNull($run->safety_dump_path);
    }

    public function test_the_uploaded_dump_is_removed_from_the_server_and_the_host(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $this->assertNotNull($this->indexOfCommandContaining('rm -f'));
        Storage::disk('local')->assertMissing('restores/dump.sql.gz');
    }

    public function test_a_failed_safety_dump_prevents_the_drop(): void
    {
        $this->mockSsh();
        $this->fakeResults['pg_dump'] = [
            'output' => 'pg_dump: error: connection to server was lost',
            'exit_code' => 1,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertNull($this->indexOfCommandContaining('DROP DATABASE'), 'the drop must not run when the safety dump fails');
        $this->assertSame('failed', $run->status);
        $this->assertSame('dump', $run->failed_step);
        $this->assertNull($run->safety_dump_path, 'no safety dump path was ever produced');
    }

    public function test_cleanup_deletes_the_local_file_even_when_the_remote_delete_fails(): void
    {
        $this->mockSsh();
        // A genuinely successful restore needs a genuine, non-zero count (see
        // the comment on test_overwrite_records_the_safety_dump_path_and_succeeds).
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        // The server disappears right at the end: the final "rm -f" of the
        // uploaded dump can no longer reach it. Keyed on the exact temp path
        // (not a bare "rm -f") so this does not also match the safety-dump
        // prune command, which happens earlier and also shells out to rm -f.
        $this->fakeResults['rm -f '.escapeshellarg("/var/tmp/shipyard-restore-{$run->id}.sql.gz")] = ['throw' => true];

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        // The remote delete failing must not fail the restore: it already
        // succeeded, and disk usage on a possibly-dead server is secondary to
        // the host-side delete, which is the one that matters.
        $this->assertSame('success', $run->fresh()->status);
        Storage::disk('local')->assertMissing('restores/dump.sql.gz');
    }

    public function test_a_failed_prune_does_not_fail_an_otherwise_successful_restore(): void
    {
        $this->mockSsh();
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        // Pruning old safety dumps is tidiness, not correctness: it must not
        // be able to turn an otherwise-successful restore into a failure.
        $this->fakeResults['xargs -r sudo rm -f'] = ['throw' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_disconnect_does_not_mask_a_connect_failure(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andThrow(new \RuntimeException('SSH authentication failed for db1.example.com'));
            $mock->shouldReceive('disconnect')->once();
        });

        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('upload', $run->failed_step);
        $this->assertStringContainsString('SSH authentication failed for db1.example.com', $run->log);
        Storage::disk('local')->assertMissing('restores/dump.sql.gz');
    }

    public function test_mysql_overwrite_restore_succeeds(): void
    {
        $this->mockSsh();
        $this->fakeResults['information_schema.tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun(databaseAttributes: [
            'type' => 'mysql',
            'admin_user' => 'root',
            'admin_password' => 'secret',
        ]);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('success', $run->status);
        $this->assertNotNull($this->indexOfCommandContaining('mysqldump'), 'expected a safety dump via mysqldump');
        $this->assertNotNull($this->indexOfCommandContaining('DROP DATABASE'));
        $this->assertNotNull($this->indexOfCommandContaining('CREATE DATABASE'));
        $this->assertStringContainsString('/var/backups/shipyard/', $run->safety_dump_path);
        $this->assertStringContainsString('Restore completed', $run->log);
    }

    public function test_overwrite_recreates_the_database_with_the_described_attributes(): void
    {
        $this->mockSsh();
        // describeDatabase()'s SQL contains pg_get_userbyid; the pipe-separated
        // output is owner|charset|collation.
        $this->fakeResults['pg_get_userbyid'] = ['output' => 'alice|UTF8|en_US.UTF-8', 'exit_code' => 0, 'success' => true];
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $createAt = $this->indexOfCommandContaining('CREATE DATABASE');
        $this->assertNotNull($createAt, 'expected a create command');
        $this->assertStringContainsString("ENCODING 'UTF8'", $this->executed[$createAt]);
        $this->assertStringContainsString("LC_COLLATE 'en_US.UTF-8'", $this->executed[$createAt]);

        $ownerAt = $this->indexOfCommandContaining('OWNER TO');
        $this->assertNotNull($ownerAt, 'expected the owner to be reapplied');
        $this->assertStringContainsString('OWNER TO', $this->executed[$ownerAt]);
        $this->assertStringContainsString('alice', $this->executed[$ownerAt]);

        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_a_failed_create_after_a_successful_drop_names_the_safety_dump(): void
    {
        $this->mockSsh();
        $this->fakeResults['CREATE DATABASE'] = [
            'output' => 'ERROR:  could not create database directory',
            'exit_code' => 1,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        // The drop already succeeded by this point, so the target does not
        // exist at all; the failed_step and the neutral recovery wording must
        // not claim it is "partially loaded".
        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertNotNull($run->safety_dump_path);
        $this->assertStringContainsString('unknown state', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_failed_attribute_application_names_the_safety_dump(): void
    {
        $this->mockSsh();
        // An owner must be present or applyDatabaseAttributes() is a no-op for
        // Postgres and never calls execute() at all.
        $this->fakeResults['pg_get_userbyid'] = ['output' => 'alice|UTF8|en_US.UTF-8', 'exit_code' => 0, 'success' => true];
        $this->fakeResults['OWNER TO'] = [
            'output' => 'ERROR:  role "alice" does not exist',
            'exit_code' => 1,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        // The database exists and was just created (empty) at this point, not
        // "partially loaded" in any sense the old wording implied.
        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertNotNull($run->safety_dump_path);
        $this->assertStringContainsString('unknown state', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_lost_claim_never_touches_ssh_at_all(): void
    {
        $this->mockSsh();
        // Already past pending, as if another delivery of the same job (e.g.
        // one reclaimed from Redis's reserved queue while the first attempt
        // is still running) had already claimed it.
        $run = $this->makeRun(['status' => 'running']);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        // This is the assertion that matters: not merely that the status is
        // still 'running', but that nothing destructive was even attempted.
        // Asserting on $this->executed being empty means removing the
        // claim()/return-early guard would fail this test, because the
        // service would go on to connect, upload, and (with overwrite: true)
        // drop the target database.
        $this->assertSame([], $this->executed);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_a_normal_pending_run_still_proceeds_through_the_claim(): void
    {
        $this->mockSsh();
        $this->fakeResults['pg_tables'] = ['output' => '5', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        $this->assertSame('pending', $run->status);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $this->assertSame('success', $run->fresh()->status);
        $this->assertNotNull($this->indexOfCommandContaining('DROP DATABASE'));
    }

    public function test_a_failed_describe_is_labelled_a_describe_failure(): void
    {
        $this->mockSsh();
        $this->fakeResults['pg_get_userbyid'] = [
            'output' => 'connection reset by peer',
            'exit_code' => 255,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        // The upload already succeeded by this point; labelling this failure
        // 'upload' would be false. No safety dump was ever attempted either.
        $this->assertSame('failed', $run->status);
        $this->assertSame('describe', $run->failed_step);
        $this->assertNull($run->safety_dump_path);
        $this->assertNull($this->indexOfCommandContaining('pg_dump'));
    }
}
