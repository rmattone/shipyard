<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Domain;
use App\Services\DomainService;
use App\Services\NginxService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NGINX-4: config files must be named after an immutable key (the app id),
 * not the mutable primary domain, and stale domain-named files must be
 * cleaned up so changed or deleted apps stop serving.
 */
class NginxConfigNamingTest extends TestCase
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

    private function makeApp(): Application
    {
        $app = Application::factory()->create(['type' => 'laravel']);
        Domain::factory()->primary()->create([
            'application_id' => $app->id,
            'domain' => 'example.test',
        ]);

        return $app->fresh();
    }

    private function commandsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->executedCommands,
            fn ($c) => str_contains($c, $needle)
        ));
    }

    public function test_deploy_names_the_config_after_the_app_id_not_the_domain(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        app(NginxService::class)->deploy($app);

        $moves = array_filter(
            $this->commandsContaining("/etc/nginx/sites-available/shipyard-app-{$app->id}"),
            fn ($c) => str_contains($c, 'mv -f')
        );
        $this->assertNotEmpty(
            $moves,
            'Config files must be named after the immutable app id, not the mutable primary domain.'
        );

        $symlinks = $this->commandsContaining("sites-enabled/shipyard-app-{$app->id}");
        $this->assertNotEmpty($symlinks, 'The enabled symlink must also use the app-id name.');
    }

    public function test_deploy_removes_stale_domain_named_configs(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        app(NginxService::class)->deploy($app);

        $removals = array_filter(
            $this->commandsContaining('rm -f'),
            fn ($c) => str_contains($c, 'sites-enabled/example.test')
        );
        $this->assertNotEmpty(
            $removals,
            'Deploy must clean up configs named after the domain (the pre-app-id naming convention).'
        );
    }

    public function test_deploy_cleans_extra_legacy_names(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        app(NginxService::class)->deploy($app, ['old-domain.test']);

        $removals = array_filter(
            $this->commandsContaining('rm -f'),
            fn ($c) => str_contains($c, 'sites-enabled/old-domain.test')
        );
        $this->assertNotEmpty(
            $removals,
            'Deploy must remove configs named after domains the app no longer has.'
        );
    }

    public function test_remove_deletes_the_app_config_and_stale_domain_named_files(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();

        app(NginxService::class)->remove($app);

        $this->assertNotEmpty(
            array_filter(
                $this->commandsContaining('rm -f'),
                fn ($c) => str_contains($c, "sites-enabled/shipyard-app-{$app->id}")
            ),
            'Removing an app must delete its app-id-named config.'
        );
        $this->assertNotEmpty(
            array_filter(
                $this->commandsContaining('rm -f'),
                fn ($c) => str_contains($c, 'sites-enabled/example.test')
            ),
            'Removing an app must also delete configs under the old domain-based naming.'
        );
    }

    /**
     * NGINX-7: after removing an app's config, a failing nginx -t means some
     * other config is broken; reloading anyway would take every site down.
     */
    public function test_remove_does_not_reload_nginx_when_the_config_test_fails(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();
        $this->fakeResults['nginx -t'] = ['output' => 'nginx: test failed', 'exit_code' => 1, 'success' => false];

        try {
            app(NginxService::class)->remove($app);
            $this->fail('Expected remove to throw when nginx -t fails.');
        } catch (\RuntimeException) {
            // expected
        }

        $reloads = $this->commandsContaining('systemctl reload nginx');
        $this->assertSame([], $reloads, 'Reloading with a broken config would take every site on the server down.');
    }

    public function test_set_primary_redeploys_the_nginx_config(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();
        $secondary = Domain::factory()->create([
            'application_id' => $app->id,
            'domain' => 'www.example.test',
        ]);

        app(DomainService::class)->setPrimary($secondary);

        $this->assertNotEmpty($this->uploads, 'Changing the primary domain must redeploy the nginx config.');
        $moves = array_filter(
            $this->commandsContaining("/etc/nginx/sites-available/shipyard-app-{$app->id}"),
            fn ($c) => str_contains($c, 'mv -f')
        );
        $this->assertNotEmpty($moves, 'The redeployed config must land under the app-id name.');
        $this->assertContains('systemctl reload nginx', $this->executedCommands);
    }
}
