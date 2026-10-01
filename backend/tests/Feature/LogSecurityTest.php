<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Organization;
use App\Models\Server;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LogSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidFilenames(): array
    {
        return array_map(fn ($name) => [$name], [
            'missing.log;echo SHIPYARD_AUDIT_MARKER;#',
            'a;echo SHIPYARD_AUDIT_MARKER;.log',
            '$(echo SHIPYARD_AUDIT_MARKER).log',
            '`echo SHIPYARD_AUDIT_MARKER`.log',
            "a\nwhoami.log", 'a|whoami.log', "a'quote.log", '-option.log',
            '..', '.env', 'laravel.log.gz', 'space name.log',
        ]);
    }

    #[DataProvider('invalidFilenames')]
    public function test_member_cannot_send_unsupported_filenames_to_ssh(string $filename): void
    {
        $user = $this->createOrgUser('member');
        $app = Application::factory()->create();
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldNotReceive('connect');
            $mock->shouldNotReceive('execute');
        });

        $this->actingAs($user)
            ->getJson("/api/applications/{$app->id}/logs/".rawurlencode($filename))
            ->assertNotFound()->assertJsonPath('message', 'Invalid filename');
    }

    public function test_member_can_list_and_read_logs_with_shell_characters_in_base_path(): void
    {
        $user = $this->createOrgUser('member');
        // The literal substitution must stay part of the directory name.
        $directory = sys_get_temp_dir()."/shipyard-log-'quote' \$(echo INJECTED)-".bin2hex(random_bytes(6));
        $logs = $directory.'/storage/logs';
        mkdir($logs, 0700, true);
        $content = "first\nERROR -needle\nlast\n";
        file_put_contents($logs.'/laravel-2026-10-01.log', $content);
        file_put_contents($logs.'/unsafe;name.log', 'hidden');

        try {
            $app = Application::factory()->create([
                'deploy_path' => $directory,
                'deployment_strategy' => 'in_place',
            ]);
            $this->mock(SSHService::class, function ($mock) {
                $mock->shouldReceive('connect')->times(3)->andReturnSelf();
                $mock->shouldReceive('disconnect')->times(3);
                // Only disposable fixture operations execute locally; no server is contacted.
                $mock->shouldReceive('execute')->andReturnUsing(function ($command) {
                    $process = Process::fromShellCommandline($command);
                    $process->mustRun();

                    return ['success' => true, 'output' => $process->getOutput(), 'exit_code' => 0];
                });
            });

            $url = "/api/applications/{$app->id}/logs";
            $this->actingAs($user)->getJson($url)->assertOk()
                ->assertJsonCount(1, 'files')
                ->assertJsonPath('files.0.name', 'laravel-2026-10-01.log');
            $this->getJson($url.'/laravel-2026-10-01.log?lines=2')->assertOk()
                ->assertJsonPath('content', "ERROR -needle\nlast\n")
                ->assertJsonPath('total_lines', 3)
                ->assertJsonPath('file_size', strlen($content));
            $this->getJson($url.'/laravel-2026-10-01.log?search=-needle')->assertOk()
                ->assertJsonPath('content', "ERROR -needle\n");
        } finally {
            unlink($logs.'/laravel-2026-10-01.log');
            unlink($logs.'/unsafe;name.log');
            rmdir($logs);
            rmdir($directory.'/storage');
            rmdir($directory);
        }
    }

    public function test_member_cannot_read_another_organizations_logs(): void
    {
        $user = $this->createOrgUser('member');
        $app = Application::factory()->create([
            'server_id' => Server::factory()->create([
                'organization_id' => Organization::factory()->create()->id,
            ])->id,
        ]);
        $this->mock(SSHService::class, fn ($mock) => $mock->shouldNotReceive('connect'));

        $this->actingAs($user)->getJson("/api/applications/{$app->id}/logs/laravel.log")
            ->assertNotFound();
    }
}
