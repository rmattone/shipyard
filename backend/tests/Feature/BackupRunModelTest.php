<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
