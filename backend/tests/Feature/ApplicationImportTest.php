<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Server;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auto-import of projects that already live on a server (e.g. deployed
 * before the panel was connected): scan /var/www and /var/www/shipyard,
 * classify each project, and create Application records for them with
 * git_provider_id left null (surfaced as a flag in the UI).
 */
class ApplicationImportTest extends TestCase
{
    use RefreshDatabase;

    private const NGINX_SITES = <<<'NGINX'
server {
    listen 443 ssl;
    server_name blog.example.com www.blog.example.com;
    root /var/www/shipyard/blog/current/public;
    location / { try_files $uri /index.php?$query_string; }
}
server {
    listen 80;
    server_name blog.example.com www.blog.example.com staging.blog.example.com;
    root /var/www/shipyard/blog/current/public;
}
server {
    listen 80;
    server_name api.example.com;
    root /var/www/shipyard/api-service;
    location / { proxy_pass http://127.0.0.1:3001; }
}
NGINX;

    private function mockSsh(array $fakes): void
    {
        $this->mock(SSHService::class, function ($mock) use ($fakes) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($fakes) {
                foreach ($fakes as $needle => $output) {
                    if (str_contains($command, $needle)) {
                        return ['output' => $output, 'exit_code' => 0, 'success' => true];
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    public function test_existing_projects_are_imported_as_applications(): void
    {
        $this->mockSsh([
            'find /var/www' => implode("\n", [
                '/var/www/html',
                '/var/www/shipyard',
                '/var/www/legacy-site',
                '/var/www/shipyard/blog',
                '/var/www/shipyard/api-service',
            ]),
            'sites-enabled' => self::NGINX_SITES,

            '/var/www/shipyard/blog/current" && test -d' => 'atomic',
            '/var/www/shipyard/blog/current/artisan' => 'laravel',
            'git -C "/var/www/shipyard/blog/current" config' => 'git@github.com:acme/blog.git',
            'git -C "/var/www/shipyard/blog/current" rev-parse' => 'production',

            '/var/www/shipyard/api-service/current" && test -d' => 'in_place',
            '/var/www/shipyard/api-service/artisan' => 'nodejs',

            '/var/www/legacy-site/current" && test -d' => 'in_place',
            '/var/www/legacy-site/artisan' => 'static',
        ]);

        $user = $this->createOrgUser();
        $server = Server::factory()->create(['php_version' => '8.4']);

        $response = $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import");

        $response->assertOk();
        $this->assertCount(3, $response->json('imported'));

        $blog = Application::where('deploy_path', '/var/www/shipyard/blog')->first();
        $this->assertNotNull($blog);
        $this->assertSame('laravel', $blog->type);
        $this->assertSame('atomic', $blog->deployment_strategy);
        $this->assertSame('git@github.com:acme/blog.git', $blog->repository_url);
        $this->assertSame('production', $blog->branch);
        $this->assertSame('blog.example.com', $blog->domain);
        $this->assertTrue($blog->ssl_enabled);
        $this->assertSame('8.4', $blog->php_version);
        $this->assertNull($blog->git_provider_id);

        $api = Application::where('deploy_path', '/var/www/shipyard/api-service')->first();
        $this->assertSame('nodejs', $api->type);
        $this->assertSame('in_place', $api->deployment_strategy);
        $this->assertNull($api->repository_url);
        $this->assertSame('main', $api->branch);
        $this->assertSame('api.example.com', $api->domain);
        $this->assertFalse($api->ssl_enabled);
        $this->assertSame(3001, $api->port);

        $legacy = Application::where('deploy_path', '/var/www/legacy-site')->first();
        $this->assertSame('static', $legacy->type);

        // Every server_name in the matching nginx blocks becomes a Domain
        // record; SSL is tracked per domain (443 vs 80-only blocks)
        $blogDomains = $blog->domains()->orderByDesc('is_primary')->orderBy('domain')->get();
        $this->assertSame(
            [
                ['blog.example.com', true, true],
                ['staging.blog.example.com', false, false],
                ['www.blog.example.com', false, true],
            ],
            $blogDomains->map(fn ($d) => [$d->domain, $d->is_primary, $d->ssl_enabled])->all()
        );

        $apiDomains = $api->domains()->get();
        $this->assertCount(1, $apiDomains);
        $this->assertSame('api.example.com', $apiDomains[0]->domain);
        $this->assertTrue($apiDomains[0]->is_primary);
        $this->assertFalse($apiDomains[0]->ssl_enabled);

        $this->assertSame(0, $legacy->domains()->count());

        // Container/base dirs must never import as apps
        $this->assertNull(Application::where('deploy_path', '/var/www/html')->first());
        $this->assertNull(Application::where('deploy_path', '/var/www/shipyard')->first());
    }

    public function test_project_env_files_are_imported_as_environment_variables(): void
    {
        $this->mockSsh([
            'find /var/www' => "/var/www/shipyard/blog\n/var/www/shipyard/api-service",

            '/var/www/shipyard/blog/current" && test -d' => 'atomic',
            '/var/www/shipyard/blog/current/artisan' => 'laravel',
            'cat "/var/www/shipyard/blog/shared/.env"' => "APP_NAME=\"My Blog\"\n# comment\nDB_PASSWORD=s3cret\n",

            '/var/www/shipyard/api-service/current" && test -d' => 'in_place',
            '/var/www/shipyard/api-service/artisan' => 'nodejs',
            'cat "/var/www/shipyard/api-service/.env"' => "PORT=3001\n",
        ]);

        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import")
            ->assertOk();

        $blog = Application::where('deploy_path', '/var/www/shipyard/blog')->first();
        $vars = $blog->environmentVariables()->get()->mapWithKeys(fn ($v) => [$v->key => $v->value]);
        $this->assertSame('My Blog', $vars['APP_NAME'] ?? null);
        $this->assertSame('s3cret', $vars['DB_PASSWORD'] ?? null);
        $this->assertCount(2, $vars);

        $api = Application::where('deploy_path', '/var/www/shipyard/api-service')->first();
        $this->assertSame('3001', $api->environmentVariables()->first()?->value);
    }

    // Apps imported before env support existed (or whose env was never
    // synced) get their variables backfilled on the next import run.
    public function test_rerunning_import_backfills_env_for_managed_apps_without_variables(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $existing = Application::factory()->create([
            'server_id' => $server->id,
            'deploy_path' => '/var/www/shipyard/blog',
            'deployment_strategy' => 'in_place',
        ]);
        $existing->environmentVariables()->delete();

        $this->mockSsh([
            'find /var/www' => '/var/www/shipyard/blog',
            'cat "/var/www/shipyard/blog/.env"' => "APP_KEY=abc123\n",
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import");

        $response->assertOk();
        $this->assertSame([], $response->json('imported'));
        $this->assertSame('abc123', $existing->environmentVariables()->first()?->value);
    }

    // Apps imported before domain records existed get their domains
    // backfilled from the nginx config on the next import run.
    public function test_rerunning_import_backfills_domains_for_managed_apps_without_domains(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $existing = Application::factory()->create([
            'server_id' => $server->id,
            'deploy_path' => '/var/www/shipyard/blog',
            'deployment_strategy' => 'atomic',
        ]);

        $this->mockSsh([
            'find /var/www' => '/var/www/shipyard/blog',
            'sites-enabled' => self::NGINX_SITES,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import");

        $response->assertOk();
        $this->assertSame([], $response->json('imported'));

        $domains = $existing->domains()->orderByDesc('is_primary')->orderBy('domain')->get();
        $this->assertSame(
            ['blog.example.com', 'staging.blog.example.com', 'www.blog.example.com'],
            $domains->pluck('domain')->all()
        );
        $this->assertTrue($domains[0]->is_primary);
    }

    // An app that already has domain records is never touched by the
    // backfill, even when nginx knows about more names.
    public function test_backfill_never_touches_apps_that_already_have_domains(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $existing = Application::factory()->create([
            'server_id' => $server->id,
            'deploy_path' => '/var/www/shipyard/blog',
            'deployment_strategy' => 'atomic',
        ]);
        $existing->domains()->create(['domain' => 'kept.example.com', 'is_primary' => true]);

        $this->mockSsh([
            'find /var/www' => '/var/www/shipyard/blog',
            'sites-enabled' => self::NGINX_SITES,
        ]);

        $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import")
            ->assertOk();

        $this->assertSame(['kept.example.com'], $existing->domains()->pluck('domain')->all());
    }

    public function test_managed_paths_and_unclassifiable_dirs_are_skipped(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        Application::factory()->create([
            'server_id' => $server->id,
            'deploy_path' => '/var/www/shipyard/blog',
        ]);

        $this->mockSsh([
            'find /var/www' => "/var/www/shipyard/blog\n/var/www/emptydir",
            '/var/www/emptydir/artisan' => 'unknown',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/servers/{$server->id}/applications/import");

        $response->assertOk();
        $this->assertSame([], $response->json('imported'));
        $this->assertCount(2, $response->json('skipped'));
        $this->assertSame(1, Application::count());
    }
}
