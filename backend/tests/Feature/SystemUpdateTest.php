<?php

namespace Tests\Feature;

use App\Jobs\RunSystemUpdate;
use App\Models\Organization;
use App\Services\SystemUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $installDir;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(SystemUpdateService::STATE_KEY);
        Cache::forget(SystemUpdateService::LATEST_KEY);

        // A fake checkout the service can resolve deterministically.
        $this->installDir = sys_get_temp_dir().'/shipyard-test-'.uniqid();
        mkdir($this->installDir.'/backend', 0777, true);
        mkdir($this->installDir.'/.git');
        file_put_contents($this->installDir.'/docker-compose.yml', "services: {}\n");
        file_put_contents($this->installDir.'/VERSION', "1.2.0\n");
        file_put_contents($this->installDir.'/update.sh', "#!/bin/bash\necho ok\n");
        config(['shipyard.install_dir' => $this->installDir]);

        @unlink(app(SystemUpdateService::class)->logPath());
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->installDir));
        @unlink(app(SystemUpdateService::class)->logPath());

        parent::tearDown();
    }

    private function fakeGit(string $commit = 'aaaaaaa1', string $branch = 'main'): void
    {
        Process::fake([
            '*rev-parse HEAD*' => Process::result($commit."\n"),
            '*rev-parse --abbrev-ref HEAD*' => Process::result($branch."\n"),
            '*remote get-url origin*' => Process::result("git@github.com:acme/shipyard-fork.git\n"),
            '*' => Process::result(''),
        ]);
    }

    public function test_version_reads_the_file_and_compares_commits_with_origin(): void
    {
        $this->fakeGit('aaaaaaa1');
        Http::fake([
            'api.github.com/repos/acme/shipyard-fork/commits/main' => Http::response(['sha' => 'bbbbbbb2']),
            'raw.githubusercontent.com/acme/shipyard-fork/main/VERSION' => Http::response("1.3.0\n"),
        ]);

        $this->actingAs($this->createOrgUser())
            ->getJson('/api/system/version')
            ->assertOk()
            ->assertJsonPath('current_version', '1.2.0')
            ->assertJsonPath('version_source', 'file')
            ->assertJsonPath('current_commit', 'aaaaaaa1')
            ->assertJsonPath('branch', 'main')
            ->assertJsonPath('latest_commit', 'bbbbbbb2')
            ->assertJsonPath('latest_version', '1.3.0')
            ->assertJsonPath('comparison', 'commit')
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('repo', 'acme/shipyard-fork')
            ->assertJsonPath('updater_available', true)
            ->assertJsonPath('on_target_branch', true);
    }

    public function test_same_commit_means_no_update_even_if_version_file_differs(): void
    {
        $this->fakeGit('same');
        Http::fake([
            'api.github.com/*' => Http::response(['sha' => 'same']),
            'raw.githubusercontent.com/*' => Http::response("9.9.9\n"),
        ]);

        $this->actingAs($this->createOrgUser())
            ->getJson('/api/system/version')
            ->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('comparison', 'commit');
    }

    public function test_version_falls_back_to_version_numbers_without_git(): void
    {
        rmdir($this->installDir.'/.git');
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'rate limited'], 403),
            'raw.githubusercontent.com/*' => Http::response("1.3.0\n"),
        ]);

        $this->actingAs($this->createOrgUser())
            ->getJson('/api/system/version')
            ->assertOk()
            ->assertJsonPath('current_commit', null)
            ->assertJsonPath('comparison', 'version')
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('check_error', 'GitHub API responded 403');
    }

    public function test_refresh_bypasses_the_cached_check(): void
    {
        $this->fakeGit();
        Http::fake(['*' => Http::response(['sha' => 'x'])]);
        $user = $this->createOrgUser();

        $this->actingAs($user)->getJson('/api/system/version')->assertOk();
        $this->actingAs($user)->getJson('/api/system/version')->assertOk();
        Http::assertSentCount(2);

        $this->actingAs($user)->getJson('/api/system/version?refresh=1')->assertOk();
        Http::assertSentCount(4);
    }

    public function test_non_owners_cannot_reach_system_routes(): void
    {
        $member = $this->createOrgUser(Organization::ROLE_MEMBER);

        $this->actingAs($member)->getJson('/api/system/version')->assertForbidden();
        $this->actingAs($member)->postJson('/api/system/update')->assertForbidden();
    }

    public function test_update_queues_the_job_and_reports_running(): void
    {
        Bus::fake();
        $user = $this->createOrgUser();

        $this->actingAs($user)->postJson('/api/system/update')
            ->assertStatus(202)
            ->assertJsonPath('state.status', 'running');

        Bus::assertDispatched(RunSystemUpdate::class);

        $this->actingAs($user)->getJson('/api/system/update-status')
            ->assertOk()
            ->assertJsonPath('running', true)
            ->assertJsonPath('status', 'running');

        $this->assertStringContainsString('Update queued', $this->actingAs($user)->getJson('/api/system/update-status')->json('log'));
    }

    public function test_second_update_is_rejected_while_one_runs(): void
    {
        Bus::fake();
        $user = $this->createOrgUser();

        $this->actingAs($user)->postJson('/api/system/update')->assertStatus(202);
        $this->actingAs($user)->postJson('/api/system/update')->assertStatus(409);

        Bus::assertDispatchedTimes(RunSystemUpdate::class, 1);
    }

    public function test_update_is_refused_when_the_script_is_not_reachable(): void
    {
        Bus::fake();
        unlink($this->installDir.'/update.sh');

        $this->actingAs($this->createOrgUser())->postJson('/api/system/update')
            ->assertNotFound()
            ->assertJsonPath('success', false);

        Bus::assertNothingDispatched();
    }

    public function test_a_running_state_with_no_result_goes_stale(): void
    {
        $updates = app(SystemUpdateService::class);
        $updates->setState(['status' => 'running', 'started_at' => now()->subHours(2)->toIso8601String()]);

        $this->actingAs($this->createOrgUser())->getJson('/api/system/update-status')
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('running', false);
    }

    public function test_job_records_success_from_the_script_exit_code(): void
    {
        Process::fake(['bash *' => Process::result("Update complete!\n", '', 0)]);
        $updates = app(SystemUpdateService::class);
        $updates->setState(['status' => 'running', 'started_at' => now()->toIso8601String()]);

        (new RunSystemUpdate)->handle($updates);

        $this->assertSame('completed', $updates->state()['status']);
        $this->assertSame(0, $updates->state()['exit_code']);
        $this->assertStringContainsString('Running '.$this->installDir.'/update.sh', $updates->logTail());
    }

    public function test_job_records_failure_from_the_script_exit_code(): void
    {
        Process::fake(['bash *' => Process::result('', "Error: nope\n", 1)]);
        $updates = app(SystemUpdateService::class);

        (new RunSystemUpdate)->handle($updates);

        $this->assertSame('failed', $updates->state()['status']);
        $this->assertSame(1, $updates->state()['exit_code']);
        $this->assertSame('update.sh exited with code 1', $updates->state()['message']);
    }

    public function test_log_tail_truncates_long_output(): void
    {
        $updates = app(SystemUpdateService::class);
        $updates->resetLog();
        $updates->appendLog(str_repeat("line\n", 20000));

        $tail = $updates->logTail(1024);

        $this->assertStringStartsWith('[... earlier output truncated ...]', $tail);
        $this->assertLessThan(1200, strlen($tail));
    }
}
