<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackupRunModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_log_accumulates_timestamped_lines(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['log' => null]);

        $run->appendLog('Starting restore');
        $run->appendLog('Done');

        $this->assertStringContainsString('Starting restore', $run->fresh()->log);
        $this->assertStringContainsString('Done', $run->fresh()->log);
        $this->assertSame(2, substr_count($run->fresh()->log, "\n"));
    }

    public function test_runs_from_another_organization_are_scoped_out(): void
    {
        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignRun = BackupRun::factory()->create();
        CurrentOrganization::forget();

        $this->createOrgUser();
        $ownRun = BackupRun::factory()->create();

        $visible = BackupRun::pluck('id')->all();

        $this->assertContains($ownRun->id, $visible);
        $this->assertNotContains($foreignRun->id, $visible);
    }

    public function test_upload_path_is_hidden_from_serialization(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['upload_path' => 'restores/secret.sql.gz']);

        $this->assertArrayNotHasKey('upload_path', $run->toArray());
    }

    public function test_mark_as_success_records_positive_duration_since_start(): void
    {
        $this->createOrgUser();
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

        $run = BackupRun::factory()->create(['status' => 'pending']);
        $run->markAsRunning();

        $this->travel(45)->seconds();
        $run->markAsSuccess();

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(45, $run->duration_seconds);
    }

    public function test_mark_as_failed_records_positive_duration_since_start(): void
    {
        $this->createOrgUser();
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

        $run = BackupRun::factory()->create(['status' => 'pending']);
        $run->markAsRunning();

        $this->travel(30)->seconds();
        $run->markAsFailed('dump');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('dump', $run->failed_step);
        $this->assertSame(30, $run->duration_seconds);
    }

    public function test_mark_as_success_without_start_time_leaves_duration_null(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'pending', 'started_at' => null]);

        $run->markAsSuccess();

        $this->assertNull($run->fresh()->duration_seconds);
    }

    public function test_mark_as_failed_without_start_time_leaves_duration_null(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'pending', 'started_at' => null]);

        $run->markAsFailed();

        $this->assertNull($run->fresh()->duration_seconds);
    }
}
