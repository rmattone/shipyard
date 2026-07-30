<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Services\MySQLService;
use App\Services\PostgreSQLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class DatabaseRestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private function pgConnection(): Database
    {
        $this->createOrgUser();

        return Database::factory()->create([
            'type' => 'postgresql',
            'host' => 'localhost',
            'port' => 5432,
            'admin_user' => 'postgres',
            'admin_password' => "pa'ss",
        ]);
    }

    private function mysqlConnection(): Database
    {
        $this->createOrgUser();

        return Database::factory()->create([
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'admin_user' => 'root',
            'admin_password' => "pa'ss",
        ]);
    }

    /**
     * buildRestoreCommand()/buildDumpCommand() wrap their pipeline as
     * `bash -c '<escaped pipeline>'` so `set -o pipefail` can be set (see
     * withPipefail() on both drivers). That means the pipeline's own single
     * quotes get escaped a second time by the outer escapeshellarg call, so
     * a test asserting on the raw wrapped string would have to match a
     * doubly escaped literal. Instead we hand the outer quoted argument to
     * a real shell and let it undo the escaping, then assert against the
     * plain inner pipeline, which is what is actually executed inside the
     * bash -c.
     */
    private function unwrapPipefail(string $command): string
    {
        $this->assertStringStartsWith('bash -c ', $command);

        $quotedPipeline = substr($command, strlen('bash -c '));
        $inner = shell_exec("printf '%s' $quotedPipeline");

        $this->assertNotNull($inner, 'failed to unwrap the bash -c pipeline through a real shell');
        $this->assertStringStartsWith('set -o pipefail; ', $inner);

        return substr($inner, strlen('set -o pipefail; '));
    }

    public function test_postgres_restore_pipes_gunzip_into_psql_and_stops_on_error(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $pipeline = $this->unwrapPipefail($command);

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $pipeline);
        $this->assertStringContainsString('ON_ERROR_STOP=1', $pipeline);
        $this->assertStringContainsString("-d 'shop'", $pipeline);
        $this->assertStringContainsString("PGPASSWORD='pa'\\''ss'", $pipeline);
    }

    public function test_postgres_restore_cats_an_uncompressed_dump(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql', false
        );

        $pipeline = $this->unwrapPipefail($command);

        $this->assertStringContainsString("cat '/var/tmp/dump.sql'", $pipeline);
        $this->assertStringNotContainsString('gunzip', $pipeline);
    }

    public function test_postgres_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new PostgreSQLService)->buildDumpCommand(
            $this->pgConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $pipeline = $this->unwrapPipefail($command);

        $this->assertStringContainsString('pg_dump', $pipeline);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $pipeline);
    }

    public function test_mysql_restore_pipes_into_the_mysql_client(): void
    {
        $command = (new MySQLService)->buildRestoreCommand(
            $this->mysqlConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $pipeline = $this->unwrapPipefail($command);

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $pipeline);
        $this->assertStringContainsString("MYSQL_PWD='pa'\\''ss'", $pipeline);
        $this->assertStringContainsString("'shop'", $pipeline);
    }

    public function test_mysql_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new MySQLService)->buildDumpCommand(
            $this->mysqlConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $pipeline = $this->unwrapPipefail($command);

        $this->assertStringContainsString('mysqldump', $pipeline);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $pipeline);
    }

    public function test_postgres_verify_command_targets_the_restored_database(): void
    {
        $command = (new PostgreSQLService)->buildRestoreVerifyCommand(
            $this->pgConnection(), 'shop', 'SELECT count(*) FROM pg_tables'
        );

        // Must run against the restored database, not the default postgres one,
        // and return a bare value the service can parse into an integer.
        $this->assertStringContainsString("-d 'shop'", $command);
        $this->assertStringContainsString('-t -A', $command);
    }

    public function test_mysql_verify_command_is_built_for_the_connection(): void
    {
        $command = (new MySQLService)->buildRestoreVerifyCommand(
            $this->mysqlConnection(), 'shop', 'SELECT count(*) FROM information_schema.tables'
        );

        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('information_schema.tables', $command);
    }

    public function test_postgres_restore_and_dump_commands_are_wrapped_with_bash_pipefail(): void
    {
        $restore = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );
        $dump = (new PostgreSQLService)->buildDumpCommand(
            $this->pgConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        foreach ([$restore, $dump] as $command) {
            $this->assertStringStartsWith('bash -c ', $command);
            $this->assertStringContainsString('set -o pipefail;', $command);
        }
    }

    public function test_mysql_restore_and_dump_commands_are_wrapped_with_bash_pipefail(): void
    {
        $restore = (new MySQLService)->buildRestoreCommand(
            $this->mysqlConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );
        $dump = (new MySQLService)->buildDumpCommand(
            $this->mysqlConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        foreach ([$restore, $dump] as $command) {
            $this->assertStringStartsWith('bash -c ', $command);
            $this->assertStringContainsString('set -o pipefail;', $command);
        }
    }

    public function test_pipefail_wrapper_surfaces_a_failing_upstream_command(): void
    {
        // Without `set -o pipefail`, `false | gzip > file` exits 0, because
        // the shell reports gzip's exit status, not false's. That is
        // precisely the bug that let buildDumpCommand() report a truncated
        // safety dump as a success. This runs the real wrapper both drivers
        // now use, through a real shell, and proves it changes that outcome
        // rather than merely appearing in the generated string.
        $outputPath = tempnam(sys_get_temp_dir(), 'pipefail-test-');

        $wrapper = new ReflectionMethod(PostgreSQLService::class, 'withPipefail');
        $wrapper->setAccessible(true);
        $wrapped = $wrapper->invoke(new PostgreSQLService, 'false | gzip > '.escapeshellarg($outputPath));

        try {
            exec($wrapped, $outputLines, $exitCode);
            $this->assertNotSame(0, $exitCode);
        } finally {
            @unlink($outputPath);
        }
    }
}
