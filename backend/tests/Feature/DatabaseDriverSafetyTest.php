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

    // DB-3: a PostgreSQL "ALL" grant without sequences fails Laravel apps on
    // their first INSERT (permission denied for sequence xxx_id_seq), and
    // without default privileges, tables created later are inaccessible.

    public function test_postgres_all_grant_covers_sequences_and_future_objects(): void
    {
        $this->mockSsh();
        $database = Database::factory()->postgresql()->create();

        app(DatabaseService::class)->grantPrivileges($database, 'appuser', '*', 'appdb', ['ALL']);

        $joined = implode("\n", $this->executedCommands);
        $this->assertStringContainsString('ON ALL SEQUENCES IN SCHEMA public', $joined);
        $this->assertStringContainsString('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES', $joined);
        $this->assertStringContainsString('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON SEQUENCES', $joined);
    }

    public function test_postgres_all_revoke_covers_sequences_and_future_objects(): void
    {
        $this->mockSsh();
        $database = Database::factory()->postgresql()->create();

        app(DatabaseService::class)->revokePrivileges($database, 'appuser', '*', 'appdb', ['ALL']);

        $joined = implode("\n", $this->executedCommands);
        $this->assertStringContainsString('ON ALL SEQUENCES IN SCHEMA public', $joined);
        $this->assertStringContainsString('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL PRIVILEGES ON TABLES', $joined);
    }

    // DB-4: when command 2 of 3 fails, command 1 was applied on the server;
    // the stored privileges must be re-read from reality, not left stale.

    public function test_partial_grant_failure_resyncs_stored_privileges_from_the_server(): void
    {
        $this->mockSsh();
        // The schema-level grant (command 2 of the ALL sequence) fails
        $this->fakeResults['GRANT ALL ON SCHEMA public'] = [
            'output' => 'ERROR: permission denied for schema public', 'exit_code' => 1, 'success' => false,
        ];
        // The effective-privileges re-read reports what actually applies
        $this->fakeResults['has_database_privilege'] = [
            'output' => 'appdb|{CONNECT,CREATE}', 'exit_code' => 0, 'success' => true,
        ];

        $user = \App\Models\User::factory()->create();
        $database = Database::factory()->postgresql()->create();
        $dbUser = \App\Models\DatabaseUser::factory()->create([
            'database_id' => $database->id,
            'username' => 'appuser',
            'privileges' => null,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$database->server_id}/databases/{$database->id}/users/{$dbUser->id}/grant",
            ['database' => 'appdb', 'privileges' => ['ALL']]
        );

        $response->assertStatus(500);

        $dbUser->refresh();
        $this->assertSame(
            ['appdb' => ['CONNECT', 'CREATE']],
            $dbUser->privileges,
            'After a partial failure the stored privileges must reflect what the server actually reports.'
        );
    }

    // DB-5: privilege names, charset, and collation are interpolated into
    // SQL and must be validated against known-good patterns.

    public function test_api_rejects_unknown_privilege_names(): void
    {
        $this->mockSsh();
        $user = \App\Models\User::factory()->create();
        $database = Database::factory()->create();
        $dbUser = \App\Models\DatabaseUser::factory()->create(['database_id' => $database->id]);

        $url = "/api/servers/{$database->server_id}/databases/{$database->id}/users/{$dbUser->id}/grant";

        $this->actingAs($user)
            ->postJson($url, ['database' => 'appdb', 'privileges' => ['DROP TABLE users; --']])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson($url, ['database' => 'appdb', 'privileges' => ['select', 'INSERT']])
            ->assertOk();
    }

    public function test_api_rejects_malformed_charset_and_collation(): void
    {
        $this->mockSsh();
        $user = \App\Models\User::factory()->create();
        $database = Database::factory()->create();

        $url = "/api/servers/{$database->server_id}/databases/{$database->id}/remote-databases";

        $this->actingAs($user)
            ->postJson($url, ['name' => 'newdb', 'charset' => 'utf8mb4; DROP DATABASE x'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson($url, ['name' => 'newdb', 'collation' => "utf8mb4_unicode_ci' --"])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson($url, ['name' => 'newdb', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'])
            ->assertStatus(201);
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
