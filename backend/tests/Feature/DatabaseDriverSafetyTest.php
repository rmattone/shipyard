<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Services\DatabaseService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 5 of the audit TODO: MySQL/PostgreSQL driver correctness.
 */
class DatabaseDriverSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->executedCommands[] = $command;

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    // DB-1: passwords containing a single quote used to produce \' inside
    // bash single quotes, which bash does not unescape.

    public function test_mysql_admin_password_with_quotes_is_shell_safe(): void
    {
        $this->mockSsh();
        $database = Database::factory()->create(['admin_password' => "it's-secret"]);

        app(DatabaseService::class)->testConnection($database);

        $command = end($this->executedCommands);
        $this->assertStringContainsString(
            'MYSQL_PWD='.escapeshellarg("it's-secret"),
            $command,
            'The MySQL admin password must be passed via a properly quoted environment variable.'
        );
    }

    // DB-2: the MySQL driver discarded all stderr, so every failure surfaced
    // as "Failed to ...: " with an empty reason.

    public function test_mysql_commands_capture_error_output(): void
    {
        $this->mockSsh();
        $database = Database::factory()->create();

        app(DatabaseService::class)->testConnection($database);

        $command = end($this->executedCommands);
        $this->assertStringNotContainsString('2>/dev/null', $command, 'Discarding stderr hides every MySQL error reason.');
        $this->assertStringContainsString('2>&1', $command);
    }

    public function test_mysql_failures_surface_the_error_reason(): void
    {
        $this->mockSsh();
        $this->fakeResults['CREATE DATABASE'] = [
            'output' => "ERROR 1044 (42000): Access denied for user 'root'@'%'",
            'exit_code' => 1,
            'success' => false,
        ];
        $database = Database::factory()->create();

        try {
            app(DatabaseService::class)->createDatabase($database, 'newdb');
            $this->fail('Expected the create to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ERROR 1044', $e->getMessage());
        }
    }

    public function test_postgres_admin_password_with_quotes_is_shell_safe(): void
    {
        $this->mockSsh();
        $database = Database::factory()->postgresql()->create(['admin_password' => "it's-secret"]);

        app(DatabaseService::class)->testConnection($database);

        $command = end($this->executedCommands);
        $this->assertStringContainsString(
            'PGPASSWORD='.escapeshellarg("it's-secret"),
            $command,
            'The PostgreSQL admin password must be a properly quoted shell argument.'
        );
    }
}
