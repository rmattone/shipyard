<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Server;
use App\Models\User;
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
    listen 80;
    listen 443 ssl;
    server_name blog.example.com;
    root /var/www/shipyard/blog/current/public;
    location / { try_files $uri /index.php?$query_string; }
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

        $server = Server::factory()->create(['php_version' => '8.4']);

        $response = $this->actingAs(User::factory()->create())
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

        $server = Server::factory()->create();

        $this->actingAs(User::factory()->create())
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

    public function test_managed_paths_and_unclassifiable_dirs_are_skipped(): void
    {
        $server = Server::factory()->create();
        Application::factory()->create([
            'server_id' => $server->id,
            'deploy_path' => '/var/www/shipyard/blog',
        ]);

        $this->mockSsh([
            'find /var/www' => "/var/www/shipyard/blog\n/var/www/emptydir",
            '/var/www/emptydir/artisan' => 'unknown',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson("/api/servers/{$server->id}/applications/import");

        $response->assertOk();
        $this->assertSame([], $response->json('imported'));
        $this->assertCount(2, $response->json('skipped'));
        $this->assertSame(1, Application::count());
    }
}
