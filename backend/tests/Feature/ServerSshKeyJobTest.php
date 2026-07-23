<?php

namespace Tests\Feature;

use App\Jobs\ProcessServerSshKeyInstall;
use App\Jobs\ProcessServerSshKeyRemoval;
use App\Models\ServerSshKey;
use App\Services\AuthorizedKeysService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Tests\TestCase;

class ServerSshKeyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_job_marks_the_key_installed_on_success(): void
    {
        $key = ServerSshKey::factory()->installing()->create();

        $this->mock(AuthorizedKeysService::class, function ($mock) {
            $mock->shouldReceive('installKey')->once();
        });

        (new ProcessServerSshKeyInstall($key))->handle(app(AuthorizedKeysService::class));

        $this->assertSame('installed', $key->fresh()->status);
    }

    public function test_install_job_failure_marks_the_key_failed_with_the_error(): void
    {
        $key = ServerSshKey::factory()->installing()->create();

        (new ProcessServerSshKeyInstall($key))->failed(new RuntimeException('Permission denied (publickey).'));

        $fresh = $key->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('Permission denied (publickey).', $fresh->error);
    }

    public function test_removal_job_deletes_the_row_only_after_the_key_is_removed(): void
    {
        $key = ServerSshKey::factory()->create(['status' => 'removing']);

        $this->mock(AuthorizedKeysService::class, function ($mock) {
            $mock->shouldReceive('removeKey')->once();
        });

        (new ProcessServerSshKeyRemoval($key))->handle(app(AuthorizedKeysService::class));

        $this->assertDatabaseMissing('server_ssh_keys', ['id' => $key->id]);
    }

    public function test_removal_job_failure_keeps_the_row_and_marks_it_failed(): void
    {
        $key = ServerSshKey::factory()->create(['status' => 'removing']);

        (new ProcessServerSshKeyRemoval($key))->failed(new RuntimeException('ssh unreachable'));

        $fresh = $key->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('ssh unreachable', $fresh->error);
    }

    public function test_both_jobs_share_one_overlap_lock_per_server_and_username(): void
    {
        $key = ServerSshKey::factory()->create(['username' => 'deploy']);

        foreach ([new ProcessServerSshKeyInstall($key), new ProcessServerSshKeyRemoval($key)] as $job) {
            $middleware = $job->middleware();

            $this->assertCount(1, $middleware);
            $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
            $this->assertSame("authorized-keys:{$key->server_id}:deploy", $middleware[0]->key);
            $this->assertTrue($middleware[0]->shareKey);
        }
    }

    public function test_jobs_survive_the_key_row_disappearing_while_queued(): void
    {
        $key = ServerSshKey::factory()->create();
        $install = new ProcessServerSshKeyInstall($key);

        $key->delete();

        $this->assertTrue($install->deleteWhenMissingModels);
        $install->failed(new RuntimeException('late failure'));
        $this->assertDatabaseMissing('server_ssh_keys', ['id' => $key->id]);
    }
}
