<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\DatabaseService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSchemasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Database $database;

    private array $commands = [];

    private array $sshResult = ['success' => true, 'output' => '[]', 'exit_code' => 0];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createOrgUser();
        $this->database = Database::factory()->postgresql()->create();
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $this->commands[] = $command;

                return $this->sshResult;
            });
        });
    }

    private function url(?int $serverId = null): string
    {
        return '/api/servers/'.($serverId ?? $this->database->server_id).'/databases/'.$this->database->id.'/schemas';
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['database_name' => 'dsc', 'name' => 'ticketing', 'owner' => 'dsc_app'], $overrides);
    }

    public function test_lists_schema_names_and_owners_in_selected_database(): void
    {
        $this->sshResult['output'] = '[{"name":"public","owner":"postgres"},{"name":"ticketing","owner":"dsc_app"}]';
        $this->actingAs($this->user)->getJson($this->url().'?database_name=dsc')
            ->assertOk()->assertJsonPath('schemas.1.owner', 'dsc_app')->assertJsonCount(2, 'schemas');
        $this->assertStringContainsString("-d 'dsc'", $this->commands[0]);
        $this->assertStringContainsString('pg_catalog.pg_namespace', $this->commands[0]);
    }

    public function test_creates_schema_as_admin_with_explicit_application_owner(): void
    {
        $this->actingAs($this->user)->postJson($this->url(), $this->payload())->assertCreated();
        $this->assertCount(1, $this->commands);
        $this->assertStringContainsString('CREATE SCHEMA \\"ticketing\\" AUTHORIZATION \\"dsc_app\\"', $this->commands[0]);
        $this->assertStringContainsString("-d 'dsc'", $this->commands[0]);
        $this->assertStringContainsString("-U 'postgres'", $this->commands[0]);
        $this->assertStringNotContainsString('GRANT', $this->commands[0]);
        $this->assertStringNotContainsString('IF NOT EXISTS', $this->commands[0]);
    }

    public function test_invalid_or_reserved_identifiers_never_reach_ssh(): void
    {
        foreach ([['name' => 'pg_internal'], ['name' => 'public'], ['name' => 'information_schema'],
            ['name' => str_repeat('a', 64)], ['name' => 'bad; DROP DATABASE dsc'],
            ['owner' => 'app"; SELECT 1'], ['owner' => ''], ['database_name' => 'postgres'],
            ['database_name' => 'template1'], ['database_name' => 'dbname=dsc user=postgres'],
        ] as $invalid) {
            $this->actingAs($this->user)->postJson($this->url(), $this->payload($invalid))->assertUnprocessable();
        }
        $this->assertSame([], $this->commands);
    }

    public function test_mysql_connection_is_rejected_without_connecting(): void
    {
        $this->database->update(['type' => 'mysql']);
        $this->actingAs($this->user)->getJson($this->url().'?database_name=dsc')->assertUnprocessable();
        $this->actingAs($this->user)->postJson($this->url(), $this->payload())->assertUnprocessable();
        $this->assertSame([], $this->commands);
    }

    public function test_mismatched_server_is_rejected_without_connecting(): void
    {
        $server = Server::factory()->create();
        $this->actingAs($this->user)->getJson($this->url($server->id).'?database_name=dsc')->assertNotFound();
        $this->actingAs($this->user)->postJson($this->url($server->id), $this->payload())->assertNotFound();
        $this->assertSame([], $this->commands);
    }

    public function test_other_organization_cannot_access_schemas(): void
    {
        $other = $this->createOrgUser();
        $this->actingAs($other)->getJson($this->url().'?database_name=dsc')->assertNotFound();
        $this->actingAs($other)->postJson($this->url(), $this->payload())->assertNotFound();
        $this->assertSame([], $this->commands);
    }

    public function test_member_can_list_but_cannot_create_schemas(): void
    {
        $this->user->organizations()->updateExistingPivot($this->user->current_organization_id, ['role' => Organization::ROLE_MEMBER]);
        $this->actingAs($this->user)->getJson($this->url().'?database_name=dsc')->assertOk();
        $this->commands = [];
        $this->actingAs($this->user)->postJson($this->url(), $this->payload())->assertForbidden();
        $this->assertSame([], $this->commands);
    }

    public function test_guest_cannot_connect(): void
    {
        $this->getJson($this->url().'?database_name=dsc')->assertUnauthorized();
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->assertSame([], $this->commands);
    }

    public function test_remote_errors_do_not_silently_succeed_or_change_existing_owner(): void
    {
        $this->sshResult = ['success' => false, 'output' => 'ERROR: schema "ticketing" already exists', 'exit_code' => 1];
        $this->actingAs($this->user)->postJson($this->url(), $this->payload())->assertUnprocessable()
            ->assertJsonPath('message', 'Failed to create schema: ERROR: schema "ticketing" already exists');
        $this->assertCount(1, $this->commands);
        $this->assertStringNotContainsString('ALTER', $this->commands[0]);
    }

    public function test_malformed_remote_output_is_not_displayed_as_an_empty_schema_list(): void
    {
        foreach (['unexpected output', 'null', '{"name":"public"}', '[{"name":"public"}]'] as $output) {
            $this->sshResult['output'] = $output;
            $this->actingAs($this->user)->getJson($this->url().'?database_name=dsc')->assertUnprocessable();
        }
    }

    public function test_driver_quotes_identifiers_and_shell_arguments(): void
    {
        app(DatabaseService::class)->createSchema($this->database, 'app-db', 'a"b', 'app"owner');
        $this->assertStringContainsString('CREATE SCHEMA \\"a\\"\\"b\\" AUTHORIZATION \\"app\\"\\"owner\\"', $this->commands[0]);
        $this->assertStringContainsString("-d 'app-db'", $this->commands[0]);
    }
}
