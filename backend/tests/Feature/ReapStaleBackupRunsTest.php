<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\BackupRestoreService;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReapStaleBackupRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reaps_running_backup_runs_older_than_the_job_timeout(): void
    {
        // MAX_RESTORE_SECONDS + 300 is comfortably under an hour at the time
        // of writing (3000 + 300 = 3300s), so an hour-old row is unambiguously
        // stale without hardcoding the threshold here too.
        $this->assertLessThan(3600, BackupRestoreService::MAX_RESTORE_SECONDS + 300);

        $stale = BackupRun::factory()->create([
            'status' => 'running',
            'started_at' => now()->subHour(),
            'safety_dump_path' => '/var/backups/shipyard/shop-20260730-000000.sql.gz',
        ]);

        $this->artisan('backup-runs:reap-stale')->assertSuccessful();

        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertNull($stale->failed_step);
        $this->assertStringContainsString('stale-backup-run reaper', $stale->log);
        $this->assertStringContainsString('unknown state', $stale->log);
        $this->assertStringContainsString('/var/backups/shipyard/shop-20260730-000000.sql.gz', $stale->log);
    }

    public function test_leaves_fresh_running_backup_runs_alone(): void
    {
        $fresh = BackupRun::factory()->create([
            'status' => 'running',
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('backup-runs:reap-stale')->assertSuccessful();

        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_reaps_pending_backup_runs_that_were_never_picked_up(): void
    {
        $abandoned = BackupRun::factory()->create(['status' => 'pending']);
        $abandoned->created_at = now()->subHours(3);
        $abandoned->save();

        $this->artisan('backup-runs:reap-stale')->assertSuccessful();

        $abandoned->refresh();
        $this->assertSame('failed', $abandoned->status);
        $this->assertNull($abandoned->failed_step);
    }

    public function test_leaves_an_already_terminal_run_untouched(): void
    {
        $finished = BackupRun::factory()->create([
            'status' => 'success',
            'started_at' => now()->subHour(),
            'log' => "done\n",
        ]);

        $this->artisan('backup-runs:reap-stale')->assertSuccessful();

        $finished->refresh();
        $this->assertSame('success', $finished->status);
        $this->assertSame("done\n", $finished->log);
    }

    public function test_reaps_across_organizations(): void
    {
        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignRun = BackupRun::factory()->create(['status' => 'running', 'started_at' => now()->subHour()]);
        CurrentOrganization::forget();

        $this->createOrgUser();
        $ownRun = BackupRun::factory()->create(['status' => 'running', 'started_at' => now()->subHour()]);

        // A real artisan invocation never binds an organization context at
        // all; forget the one createOrgUser() just bound so the reaper runs
        // exactly the way it does in production, rather than inheriting a
        // context that would scope its query back down to one organization.
        CurrentOrganization::forget();

        $this->artisan('backup-runs:reap-stale')->assertSuccessful();

        $this->assertSame('failed', $foreignRun->fresh()->status);
        $this->assertSame('failed', $ownRun->fresh()->status);
    }
}
