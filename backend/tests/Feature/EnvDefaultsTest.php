<?php

namespace Tests\Feature;

use App\Jobs\ProcessDaemonInstall;
use App\Jobs\ProcessScheduledTaskInstall;
use App\Models\Application;
use App\Models\Daemon;
use App\Models\Domain;
use App\Models\GitProvider;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\User;
use App\Support\EnvFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnvDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createOrgUser();
    }

    private function laravelApp(array $attributes = []): Application
    {
        return Application::factory()->laravel()->create([
            'server_id' => Server::factory()->create()->id,
            'deployment_strategy' => 'atomic',
            ...$attributes,
        ]);
    }

    private function setEnv(Application $app, array $values): void
    {
        foreach ($values as $key => $value) {
            $app->environmentVariables()->create(['key' => $key, 'value' => $value]);
        }
    }

    public function test_template_reads_the_repository_example_and_forces_production_values(): void
    {
        $provider = GitProvider::factory()->github()->create(['host' => null]);
        $app = $this->laravelApp([
            'git_provider_id' => $provider->id,
            'repository_url' => 'https://github.com/acme/shop.git',
            'branch' => 'release',
        ]);
        Domain::factory()->primary()->withSsl()->create(['application_id' => $app->id, 'domain' => 'shop.example.com']);

        Http::fake([
            'api.github.com/repos/acme/shop/contents/.env.example*' => Http::response(
                "# Shop\nAPP_NAME=Shop\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\nLOG_LEVEL=debug\nMAIL_FROM=hi@shop.test\n"
            ),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}/env-template")
            ->assertOk()
            ->assertJsonPath('source', 'repository');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'ref=release'));

        $content = $response->json('content');
        $values = EnvFile::parse($content);

        $this->assertStringStartsWith("# Shop\n", $content, 'Comments and order from the example are kept.');
        $this->assertSame('Shop', $values['APP_NAME']);
        $this->assertSame('production', $values['APP_ENV']);
        $this->assertSame('false', $values['APP_DEBUG']);
        $this->assertSame('warning', $values['LOG_LEVEL']);
        $this->assertSame('https://shop.example.com', $values['APP_URL']);
        $this->assertSame('hi@shop.test', $values['MAIL_FROM']);
        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $values['APP_KEY']);
        $this->assertEqualsCanonicalizing(
            ['APP_ENV', 'APP_DEBUG', 'APP_KEY', 'LOG_LEVEL', 'APP_URL'],
            $response->json('applied')
        );

        $this->assertSame(0, $app->environmentVariables()->count(), 'The template is never saved by itself.');
    }

    public function test_template_falls_back_to_the_builtin_when_the_repository_has_no_example(): void
    {
        $provider = GitProvider::factory()->create();
        $app = $this->laravelApp([
            'git_provider_id' => $provider->id,
            'repository_url' => 'git@gitlab.com:acme/shop.git',
        ]);

        Http::fake(['gitlab.com/*' => Http::response(['message' => '404 File Not Found'], 404)]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}/env-template")
            ->assertOk()
            ->assertJsonPath('source', 'builtin');

        $this->assertStringContainsString('.env.example', $response->json('notice'));
        $values = EnvFile::parse($response->json('content'));
        $this->assertSame('false', $values['APP_DEBUG']);
        $this->assertNotEmpty($values['APP_KEY']);
    }

    public function test_template_for_a_nodejs_app_sets_node_env(): void
    {
        $app = Application::factory()->nodejs()->create(['port' => 4000]);

        $values = EnvFile::parse(
            $this->actingAs($this->user)->getJson("/api/applications/{$app->id}/env-template")->json('content')
        );

        $this->assertSame(['NODE_ENV' => 'production', 'PORT' => '4000'], $values);
    }

    public function test_health_flags_unsafe_production_settings_without_leaking_values(): void
    {
        $app = $this->laravelApp();
        $this->setEnv($app, [
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'APP_KEY' => '',
            'LOG_LEVEL' => 'debug',
            'QUEUE_CONNECTION' => 'database',
            'DB_PASSWORD' => 'super-secret-db-password',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}/env-health")
            ->assertOk();

        $codes = array_column($response->json('warnings'), 'code');
        $this->assertEqualsCanonicalizing(
            ['app_debug', 'app_key_missing', 'app_env', 'log_level_debug', 'queue_worker_missing', 'scheduler_missing'],
            $codes
        );
        $this->assertStringNotContainsString('super-secret-db-password', $response->getContent());
    }

    public function test_health_is_clean_for_a_production_app_with_worker_and_scheduler(): void
    {
        $app = $this->laravelApp();
        $this->setEnv($app, [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'QUEUE_CONNECTION' => 'redis',
        ]);
        Daemon::factory()->create([
            'server_id' => $app->server_id,
            'application_id' => $app->id,
            'command' => 'php8.3 artisan queue:work redis',
            'status' => 'installed',
        ]);
        ScheduledTask::factory()->create([
            'server_id' => $app->server_id,
            'application_id' => $app->id,
            'command' => "php8.3 {$app->deploy_path}/current/artisan schedule:run",
            'status' => 'installed',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}/env-health")
            ->assertOk()
            ->assertExactJson(['warnings' => []]);
    }

    public function test_health_fixes_are_accepted_by_the_daemon_and_scheduler_endpoints(): void
    {
        Queue::fake();
        $app = $this->laravelApp();
        $this->setEnv($app, ['APP_ENV' => 'production', 'APP_KEY' => 'x', 'QUEUE_CONNECTION' => 'redis']);

        $warnings = collect(
            $this->actingAs($this->user)->getJson("/api/applications/{$app->id}/env-health")->json('warnings')
        )->keyBy('code');

        $worker = $warnings['queue_worker_missing']['fix'];
        $this->assertSame('daemon', $worker['type']);
        $this->assertStringContainsString('queue:work redis', $worker['data']['command']);
        $this->actingAs($this->user)
            ->postJson("/api/servers/{$worker['server_id']}/daemons", $worker['data'])
            ->assertAccepted();

        $scheduler = $warnings['scheduler_missing']['fix'];
        $this->actingAs($this->user)
            ->postJson("/api/servers/{$scheduler['server_id']}/scheduled-tasks", $scheduler['data'])
            ->assertSuccessful();

        Queue::assertPushed(ProcessDaemonInstall::class);
        Queue::assertPushed(ProcessScheduledTaskInstall::class);

        $codes = array_column(
            $this->actingAs($this->user)->getJson("/api/applications/{$app->id}/env-health")->json('warnings'),
            'code'
        );
        $this->assertNotContains('queue_worker_missing', $codes);
        $this->assertNotContains('scheduler_missing', $codes);
    }

    public function test_an_unusual_queue_connection_name_stays_out_of_the_command(): void
    {
        $app = $this->laravelApp();
        $this->setEnv($app, ['APP_ENV' => 'production', 'APP_KEY' => 'x', 'QUEUE_CONNECTION' => 'redis; rm -rf /']);

        $fix = collect(
            $this->actingAs($this->user)->getJson("/api/applications/{$app->id}/env-health")->json('warnings')
        )->firstWhere('code', 'queue_worker_missing')['fix'];

        $this->assertStringNotContainsString('rm -rf', $fix['data']['command']);
    }

    public function test_an_app_without_variables_gets_a_single_hint(): void
    {
        $app = $this->laravelApp();

        $this->actingAs($this->user)
            ->getJson("/api/applications/{$app->id}/env-health")
            ->assertOk()
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0.code', 'env_empty');
    }
}
