<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitProvider;
use App\Models\User;
use App\Services\DeploymentService;
use App\Services\GitProviderService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DEPLOY-8: git credential scripts must not be world-readable or predictable.
 * DEPLOY-9: values interpolated into remote shell commands must be quoted.
 */
class DeploymentShellSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /** @var array<int, array{content: string, path: string}> */
    private array $uploads = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

    private function mockSsh(): void
    {
        $this->executedCommands = [];
        $this->uploads = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploads[] = ['content' => $content, 'path' => $path];

                return true;
            });
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

    /**
     * @return array{0: Application, 1: Deployment}
     */
    private function makeAtomicApp(array $attributes = []): array
    {
        $app = Application::factory()->create(array_merge([
            'type' => 'laravel',
            'deployment_strategy' => 'atomic',
        ], $attributes));

        $deployment = Deployment::factory()->create([
            'application_id' => $app->id,
            'release_id' => '20260716120000-abc123',
            'release_path' => $app->getReleasesPath().'/20260716120000-abc123',
        ]);

        return [$app->fresh(), $deployment->fresh()];
    }

    public function test_git_credential_scripts_use_random_names_and_owner_only_permissions(): void
    {
        $this->mockSsh();
        $provider = GitProvider::factory()->create();
        [, $deployment] = $this->makeAtomicApp(['git_provider_id' => $provider->id]);

        app(DeploymentService::class)->runDeployment($deployment);

        $scriptUploads = array_filter(
            $this->uploads,
            fn ($u) => str_contains($u['content'], 'ASKPASS_EOF') || str_contains($u['content'], 'SSH_KEY_EOF')
        );
        $this->assertNotEmpty($scriptUploads, 'Expected scripts with embedded credentials to be uploaded.');

        foreach ($scriptUploads as $upload) {
            $this->assertMatchesRegularExpression(
                '#^/tmp/shipyard-script-[A-Za-z0-9]+\.sh$#',
                $upload['path'],
                'Credential scripts must use unpredictable names, not git-clone-{appId}-{time}.sh.'
            );

            $quoted = escapeshellarg($upload['path']);
            $this->assertContains(
                "touch {$quoted} && chmod 600 {$quoted}",
                $this->executedCommands,
                'The script file must be created owner-only before credentials are written into it.'
            );
            $this->assertContains(
                "rm -f {$quoted}",
                $this->executedCommands,
                'The credentials script must be deleted after execution.'
            );
        }
    }

    public function test_credential_script_is_deleted_even_when_the_script_fails(): void
    {
        $this->mockSsh();
        $this->fakeResults['bash '] = ['output' => 'fatal: could not read from remote', 'exit_code' => 128, 'success' => false];
        $provider = GitProvider::factory()->create();
        [, $deployment] = $this->makeAtomicApp(['git_provider_id' => $provider->id]);

        try {
            app(DeploymentService::class)->runDeployment($deployment);
            $this->fail('Expected the deployment to fail when the clone script fails.');
        } catch (\RuntimeException) {
            // expected
        }

        $scriptUploads = array_filter(
            $this->uploads,
            fn ($u) => str_contains($u['content'], 'ASKPASS_EOF') || str_contains($u['content'], 'SSH_KEY_EOF')
        );
        $this->assertNotEmpty($scriptUploads);

        foreach ($scriptUploads as $upload) {
            $quoted = escapeshellarg($upload['path']);
            $this->assertContains(
                "rm -f {$quoted}",
                $this->executedCommands,
                'The credentials script must be removed even when it fails.'
            );
        }
    }

    public function test_remote_commands_quote_paths_and_branches(): void
    {
        $this->mockSsh();
        [, $deployment] = $this->makeAtomicApp([
            'git_provider_id' => null,
            'deploy_path' => '/var/www/my app',
            'branch' => 'main',
        ]);

        app(DeploymentService::class)->runDeployment($deployment);

        $releasesQ = escapeshellarg('/var/www/my app/releases');
        $this->assertContains(
            "mkdir -p {$releasesQ}",
            $this->executedCommands,
            'A deploy path containing a space must be quoted or mkdir creates the wrong directories.'
        );

        $releaseQ = escapeshellarg($deployment->release_path);
        $currentQ = escapeshellarg('/var/www/my app/current');
        $this->assertContains(
            "ln -nfs {$releaseQ} {$currentQ}",
            $this->executedCommands,
            'The symlink swap must quote both paths.'
        );

        $cloneCommands = array_filter($this->executedCommands, fn ($c) => str_contains($c, 'git clone'));
        $this->assertNotEmpty($cloneCommands);
        foreach ($cloneCommands as $command) {
            $this->assertStringContainsString($releaseQ, $command, 'The clone target path must be quoted.');
        }
    }

    public function test_unsafe_shared_paths_abort_the_deployment(): void
    {
        $this->mockSsh();
        [, $deployment] = $this->makeAtomicApp(['shared_paths' => ['../../etc']]);

        try {
            app(DeploymentService::class)->runDeployment($deployment);
            $this->fail('Expected the deployment to fail on the traversal shared path.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('path', strtolower($e->getMessage()));
        }

        $traversals = array_filter(
            $this->executedCommands,
            fn ($c) => str_starts_with($c, 'rm -rf') && str_contains($c, '..')
        );
        $this->assertSame(
            [],
            array_values($traversals),
            'rm -rf must never run against a traversal path from shared_paths.'
        );
    }

    public function test_api_rejects_unsafe_shared_and_writable_paths(): void
    {
        $this->mockSsh();
        $user = User::factory()->create();
        [$app] = $this->makeAtomicApp();

        foreach ([['..'], ['../../etc'], ['/etc'], ['']] as $paths) {
            $this->actingAs($user)
                ->putJson("/api/applications/{$app->id}", ['shared_paths' => $paths])
                ->assertStatus(422);

            $this->actingAs($user)
                ->putJson("/api/applications/{$app->id}", ['writable_paths' => $paths])
                ->assertStatus(422);
        }

        $this->actingAs($user)
            ->putJson("/api/applications/{$app->id}", ['shared_paths' => ['storage', '.env']])
            ->assertOk();
    }

    public function test_api_rejects_node_versions_with_shell_metacharacters(): void
    {
        $this->mockSsh();
        $user = User::factory()->create();
        [$app] = $this->makeAtomicApp();

        $this->actingAs($user)
            ->putJson("/api/applications/{$app->id}", ['node_version' => '18; rm -rf /'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->putJson("/api/applications/{$app->id}", ['node_version' => 'v18.20.4'])
            ->assertOk();
    }

    public function test_git_credentials_with_quotes_are_safely_embedded(): void
    {
        $provider = GitProvider::factory()->create([
            'type' => 'bitbucket',
            'username' => "user'name",
            'access_token' => "to'ken",
        ]);

        $script = app(GitProviderService::class)->generateHTTPSCloneCommand(
            $provider,
            'https://bitbucket.org/acme/site.git',
            'main',
            '/var/www/site'
        );

        $this->assertStringContainsString(
            'echo '.escapeshellarg("user'name"),
            $script,
            'A username containing a quote must be shell-escaped in the askpass script.'
        );
        $this->assertStringContainsString(
            'echo '.escapeshellarg("to'ken"),
            $script,
            'A token containing a quote must be shell-escaped in the askpass script.'
        );
        $this->assertStringContainsString(
            "git clone -b 'main'",
            $script,
            'The branch must be quoted in the generated clone command.'
        );
    }
}
