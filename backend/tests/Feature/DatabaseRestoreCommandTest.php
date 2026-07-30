<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Services\MySQLService;
use App\Services\PostgreSQLService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use RuntimeException;
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
     * `bash -c '<escaped set -o pipefail; { pipeline; } 2>&1>'` (see
     * withPipefail() in App\Services\Concerns\WrapsShellPipeline) so
     * pipefail can be set and stderr captured without corrupting a
     * redirected archive. That means the pipeline's own single quotes get
     * escaped a second time by the outer escapeshellarg call, so a test
     * asserting on the raw wrapped string would have to match a doubly
     * escaped literal.
     *
     * escapeshellarg()'s scheme is a deterministic, reversible substitution
     * cipher: wrap the argument in single quotes, and replace every
     * embedded ' with the four bytes '\''. This undoes exactly that in pure
     * PHP (no shell_exec, which hardened php.ini configs commonly disable),
     * then strips the pipefail/group-redirect scaffolding to get back the
     * plain inner pipeline that is what actually executes.
     */
    private function unwrapPipefail(string $command): string
    {
        $this->assertStringStartsWith('bash -c ', $command);

        $quoted = substr($command, strlen('bash -c '));
        $unescaped = str_replace("'\\''", "'", substr($quoted, 1, -1));

        $this->assertStringStartsWith('set -o pipefail; { ', $unescaped);
        $this->assertStringEndsWith('; } 2>&1', $unescaped);

        return substr($unescaped, strlen('set -o pipefail; { '), -strlen('; } 2>&1'));
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

    public function test_postgres_table_count_command_targets_the_restored_database(): void
    {
        $command = (new PostgreSQLService)->buildTableCountCommand($this->pgConnection(), 'shop');

        // Must run against the restored database, not the default postgres one,
        // and return a bare value the service can parse into an integer.
        $this->assertStringContainsString("-d 'shop'", $command);
        $this->assertStringContainsString('-t -A', $command);
        $this->assertStringContainsString('pg_tables', $command);
    }

    public function test_mysql_table_count_command_queries_information_schema(): void
    {
        $command = (new MySQLService)->buildTableCountCommand($this->mysqlConnection(), 'shop');

        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('information_schema.tables', $command);
        $this->assertStringContainsString("table_schema = 'shop'", $command);
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
        // now use (App\Services\Concerns\WrapsShellPipeline::withPipefail(),
        // shared by both, so one execution test covers both drivers),
        // through a real shell, and proves it changes that outcome rather
        // than merely appearing in the generated string.
        //
        // withPipefail() is protected and exercised here via reflection
        // rather than through the public buildRestoreCommand()/
        // buildDumpCommand() seam, deliberately: driving it through
        // buildDumpCommand() would pipe into a real pg_dump/mysqldump,
        // making the test's exit code depend on whether those binaries
        // happen to be installed on the machine running the suite, which
        // would let it pass or fail for the wrong reason. `false` and
        // `gzip` are used instead so the assertion is about the wrapper's
        // own behaviour, not about the environment.
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

    public function test_postgres_describe_database_parses_owner_charset_and_collation(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => "app_owner|UTF8|en_US.UTF-8\n",
            'exit_code' => 0,
            'success' => true,
        ]);

        $attributes = (new PostgreSQLService)->describeDatabase($ssh, $this->pgConnection(), 'shop');

        $this->assertSame([
            'owner' => 'app_owner',
            'charset' => 'UTF8',
            'collation' => 'en_US.UTF-8',
        ], $attributes);
    }

    public function test_postgres_describe_database_throws_on_ssh_failure(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => 'connection reset by peer',
            'exit_code' => 255,
            'success' => false,
        ]);

        $this->expectException(RuntimeException::class);

        (new PostgreSQLService)->describeDatabase($ssh, $this->pgConnection(), 'shop');
    }

    public function test_postgres_describe_database_normalizes_blank_output_to_nulls(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => "\n",
            'exit_code' => 0,
            'success' => true,
        ]);

        $attributes = (new PostgreSQLService)->describeDatabase($ssh, $this->pgConnection(), 'shop');

        $this->assertSame(['owner' => null, 'charset' => null, 'collation' => null], $attributes);
    }

    public function test_postgres_apply_database_attributes_sets_owner_with_escaped_identifiers(): void
    {
        $captured = null;

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturnUsing(function (string $command) use (&$captured) {
            $captured = $command;

            return ['output' => '', 'exit_code' => 0, 'success' => true];
        });

        (new PostgreSQLService)->applyDatabaseAttributes(
            $ssh, $this->pgConnection(), 'sho"p', ['owner' => 'ow"ner', 'charset' => null, 'collation' => null]
        );

        // escapeName() doubles an embedded double quote so an identifier
        // cannot break out of its own quoting: sho"p -> sho""p. The whole
        // SQL string is then addcslashes()'d for embedding inside
        // buildCommand()'s bash -c "..." wrapper, same as every other
        // driver method.
        $this->assertStringContainsString('ALTER DATABASE', $captured);
        $this->assertStringContainsString(addcslashes('"sho""p"', '"`$\\'), $captured);
        $this->assertStringContainsString(addcslashes('"ow""ner"', '"`$\\'), $captured);
    }

    public function test_postgres_apply_database_attributes_throws_on_failure(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => 'permission denied',
            'exit_code' => 1,
            'success' => false,
        ]);

        $this->expectException(RuntimeException::class);

        (new PostgreSQLService)->applyDatabaseAttributes(
            $ssh, $this->pgConnection(), 'shop', ['owner' => 'newowner', 'charset' => null, 'collation' => null]
        );
    }

    public function test_postgres_apply_database_attributes_does_nothing_without_an_owner(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('execute');

        (new PostgreSQLService)->applyDatabaseAttributes(
            $ssh, $this->pgConnection(), 'shop', ['owner' => null, 'charset' => 'UTF8', 'collation' => 'en_US.UTF-8']
        );

        $this->assertTrue(true, 'no SSH call and no exception for a database with no recorded owner');
    }

    public function test_mysql_describe_database_parses_charset_and_collation(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => "utf8mb4\tutf8mb4_unicode_ci\n",
            'exit_code' => 0,
            'success' => true,
        ]);

        $attributes = (new MySQLService)->describeDatabase($ssh, $this->mysqlConnection(), 'shop');

        $this->assertSame([
            'owner' => null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ], $attributes);
    }

    public function test_mysql_describe_database_throws_on_ssh_failure(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => 'access denied for user',
            'exit_code' => 1,
            'success' => false,
        ]);

        $this->expectException(RuntimeException::class);

        (new MySQLService)->describeDatabase($ssh, $this->mysqlConnection(), 'shop');
    }

    public function test_mysql_describe_database_normalizes_blank_output_to_nulls(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('execute')->once()->andReturn([
            'output' => "\n",
            'exit_code' => 0,
            'success' => true,
        ]);

        $attributes = (new MySQLService)->describeDatabase($ssh, $this->mysqlConnection(), 'shop');

        $this->assertSame(['owner' => null, 'charset' => null, 'collation' => null], $attributes);
    }

    public function test_mysql_apply_database_attributes_executes_nothing(): void
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldNotReceive('execute');

        (new MySQLService)->applyDatabaseAttributes(
            $ssh, $this->mysqlConnection(), 'shop', ['owner' => null, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']
        );

        $this->assertTrue(true, 'MySQL has no database owner; createDatabase() already applies charset/collation');
    }
}
