<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Services\MySQLService;
use App\Services\PostgreSQLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_postgres_restore_pipes_gunzip_into_psql_and_stops_on_error(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $command);
        $this->assertStringContainsString('ON_ERROR_STOP=1', $command);
        $this->assertStringContainsString("-d 'shop'", $command);
        $this->assertStringContainsString("PGPASSWORD='pa'\\''ss'", $command);
    }

    public function test_postgres_restore_cats_an_uncompressed_dump(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql', false
        );

        $this->assertStringContainsString("cat '/var/tmp/dump.sql'", $command);
        $this->assertStringNotContainsString('gunzip', $command);
    }

    public function test_postgres_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new PostgreSQLService)->buildDumpCommand(
            $this->pgConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $this->assertStringContainsString('pg_dump', $command);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $command);
    }

    public function test_mysql_restore_pipes_into_the_mysql_client(): void
    {
        $command = (new MySQLService)->buildRestoreCommand(
            $this->mysqlConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $command);
        $this->assertStringContainsString("MYSQL_PWD='pa'\\''ss'", $command);
        $this->assertStringContainsString("'shop'", $command);
    }

    public function test_mysql_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new MySQLService)->buildDumpCommand(
            $this->mysqlConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $command);
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
}
