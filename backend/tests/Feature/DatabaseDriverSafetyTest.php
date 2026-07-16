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
