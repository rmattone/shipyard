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

        $lastUpload = end($this->uploads);
        $this->assertSame(
            "/etc/nginx/sites-available/shipyard-app-{$app->id}",
            $lastUpload['path'],
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

    public function test_set_primary_redeploys_the_nginx_config(): void
    {
        $this->mockSsh();
        $app = $this->makeApp();
        $secondary = Domain::factory()->create([
            'application_id' => $app->id,
            'domain' => 'www.example.test',
        ]);

        app(DomainService::class)->setPrimary($secondary);

        $lastUpload = end($this->uploads);
        $this->assertNotFalse($lastUpload, 'Changing the primary domain must redeploy the nginx config.');
        $this->assertSame("/etc/nginx/sites-available/shipyard-app-{$app->id}", $lastUpload['path']);
        $this->assertContains('systemctl reload nginx', $this->executedCommands);
    }
}
