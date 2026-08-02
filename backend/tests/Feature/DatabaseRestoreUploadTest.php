<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DatabaseRestoreController;
use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseRestoreUploadTest extends TestCase
{
    use RefreshDatabase;

    private function gzDump(string $name = 'dump.sql.gz'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, gzencode("--\n-- dump\n--\nSELECT 1;\n"));
    }

    private function sqlDump(string $name = 'dump.sql'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "--\n-- dump\n--\nSELECT 1;\n");
    }

    private function setup_target(): array
    {
        $user = $this->createOrgUser();
        $database = Database::factory()->create(['type' => 'postgresql']);

        return [$user, $database];
    }

    private function url(Database $database): string
    {
        return "/api/servers/{$database->server_id}/databases/{$database->id}/restores";
    }

    public function test_an_admin_can_upload_a_dump_and_a_run_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '1',
            'confirm_name' => 'shop',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');

        $run = BackupRun::first();
        $this->assertSame(BackupRun::KIND_RESTORE, $run->kind);
        $this->assertSame(BackupRun::SOURCE_UPLOAD, $run->source);
        $this->assertSame(BackupRun::TRIGGER_MANUAL, $run->trigger);
        $this->assertSame(BackupRun::FORMAT_SQL_GZ, $run->format);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame('shop', $run->database_name);

        Storage::disk('local')->assertExists($run->upload_path);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_overwrite_requires_the_confirmation_name_to_match(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '1',
            'confirm_name' => 'sho',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, BackupRun::count());
        Queue::assertNothingPushed();
    }

    public function test_a_new_target_does_not_need_a_confirmation_name(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop_copy',
            'overwrite' => '0',
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_a_custom_format_archive_is_rejected_with_guidance(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => UploadedFile::fake()->createWithContent('dump.dump', "PGDMP\x00binary"),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('pg_restore', $response->json('message'));
        Queue::assertNothingPushed();
    }

    public function test_a_member_cannot_upload_a_restore(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->createOrgUser(Organization::ROLE_MEMBER);
        $database = Database::factory()->create(['type' => 'postgresql']);

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_a_database_from_another_organization_is_not_found(): void
    {
        Queue::fake();
        Storage::fake('local');

        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignDatabase = Database::factory()->create(['type' => 'postgresql']);
        CurrentOrganization::forget();

        $user = $this->createOrgUser();
        $ownServer = Server::factory()->create();

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$ownServer->id}/databases/{$foreignDatabase->id}/restores",
            [
                'dump' => $this->gzDump(),
                'target_database' => 'shop',
                'overwrite' => '0',
            ]
        );

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_target_name_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop; DROP DATABASE postgres',
            'overwrite' => '0',
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_a_second_upload_is_rejected_while_one_is_in_flight(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $inFlight = BackupRun::factory()->create([
            'database_id' => $database->id,
            'status' => 'running',
        ]);

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString((string) $inFlight->id, $response->json('message'));
        $this->assertSame(1, BackupRun::count());
        Queue::assertNothingPushed();
    }

    public function test_a_new_upload_is_accepted_once_the_earlier_run_is_terminal(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        BackupRun::factory()->create([
            'database_id' => $database->id,
            'status' => 'success',
        ]);

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(202);
        $this->assertSame(2, BackupRun::count());
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_an_uncompressed_sql_dump_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->sqlDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(202);

        $run = BackupRun::first();
        $this->assertSame(BackupRun::FORMAT_SQL, $run->format);
        Storage::disk('local')->assertExists($run->upload_path);
        $this->assertStringEndsWith('.sql', $run->upload_path);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    /**
     * Regression test for the disk-mismatch bug: the controller used to
     * write via $file->storeAs(...), which resolves config('filesystems.
     * default'). That agreed with BackupRestoreService's hardcoded
     * Storage::disk('local') reads only by coincidence, because the app's
     * default disk happens to be 'local' too. Flipping the default here
     * proves the write is pinned to BackupRun::UPLOAD_DISK regardless of
     * what the default disk is configured to.
     */
    public function test_the_upload_is_stored_on_the_upload_disk_regardless_of_the_default_disk(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::fake('local');
        config(['filesystems.default' => 'public']);

        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(202);

        $run = BackupRun::first();

        Storage::disk(BackupRun::UPLOAD_DISK)->assertExists($run->upload_path);
        Storage::disk('public')->assertMissing($run->upload_path);

        // This is what BackupRestoreService actually calls to build the SFTP
        // source path; proving it resolves is what proves the service could
        // really read what the controller wrote.
        $this->assertFileExists(Storage::disk(BackupRun::UPLOAD_DISK)->path($run->upload_path));
    }

    public function test_index_returns_restore_runs_for_the_database_newest_first(): void
    {
        [$user, $database] = $this->setup_target();

        $older = BackupRun::factory()->create([
            'database_id' => $database->id,
            'status' => 'success',
            'created_at' => now()->subHour(),
        ]);
        $newer = BackupRun::factory()->create([
            'database_id' => $database->id,
            'status' => 'success',
            'created_at' => now(),
        ]);

        // A run for a different database must not leak into this list.
        $otherDatabase = Database::factory()->create(['server_id' => $database->server_id]);
        BackupRun::factory()->create(['database_id' => $otherDatabase->id]);

        $response = $this->actingAs($user)->getJson($this->url($database));

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    public function test_index_guards_against_a_database_from_another_organization(): void
    {
        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignDatabase = Database::factory()->create(['type' => 'postgresql']);
        CurrentOrganization::forget();

        $user = $this->createOrgUser();
        $ownServer = Server::factory()->create();

        $response = $this->actingAs($user)->getJson(
            "/api/servers/{$ownServer->id}/databases/{$foreignDatabase->id}/restores"
        );

        $response->assertStatus(404);
    }

    public function test_store_returns_507_when_there_is_not_enough_disk_space(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->app->bind(DatabaseRestoreController::class, DiskFullDatabaseRestoreController::class);
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->postJson($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(507);
        $this->assertSame(0, BackupRun::count());
        Queue::assertNothingPushed();
    }
}

/**
 * Forces the "not enough disk space" branch without touching a real
 * filesystem: disk_free_space() is a raw PHP function Storage::fake()
 * cannot intercept, so the only clean way to exercise that branch is to
 * override the seam the controller calls it through and bind this in the
 * container for the one test that needs it.
 */
class DiskFullDatabaseRestoreController extends DatabaseRestoreController
{
    protected function availableDiskSpace(string $path): int|float
    {
        return 0;
    }
}
