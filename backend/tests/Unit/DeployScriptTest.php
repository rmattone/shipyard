<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\Server;
use PHPUnit\Framework\TestCase;

class DeployScriptTest extends TestCase
{
    /**
     * Any template that calls npm must first source nvm, because the deploy
     * script runs in a non-login shell (bash <script>) where nvm-installed
     * node/npm are not on PATH. See DeploymentService::runDeployScript().
     *
     * @dataProvider npmScriptProvider
     */
    public function test_npm_templates_source_nvm(string $type, string $strategy): void
    {
        $script = Application::getDefaultDeployScript($type, $strategy);

        $this->assertStringContainsString('npm ', $script, "Expected {$type}/{$strategy} to use npm");
        $this->assertStringContainsString(
            'NVM_DIR',
            $script,
            "Template {$type}/{$strategy} calls npm but does not source nvm; npm will be missing in the deploy shell."
        );
    }

    public static function npmScriptProvider(): array
    {
        return [
            'laravel in_place' => ['laravel', 'in_place'],
            'laravel atomic' => ['laravel', 'atomic'],
            'nodejs in_place' => ['nodejs', 'in_place'],
            'nodejs atomic' => ['nodejs', 'atomic'],
            'static in_place' => ['static', 'in_place'],
            'static atomic' => ['static', 'atomic'],
        ];
    }

    /**
     * NGINX-3: nginx proxies to the app's configured port, so the PM2
     * process must be started with the same PORT in its environment.
     */
    public function test_pm2_restart_command_exports_the_configured_port(): void
    {
        $app = new Application([
            'name' => 'My App',
            'type' => 'nodejs',
            'deploy_path' => '/var/www/shipyard/my-app',
            'deployment_strategy' => 'atomic',
            'port' => 3100,
        ]);

        $command = $app->buildPm2RestartCommand();

        $this->assertStringContainsString('export PORT=3100', $command);
        $this->assertStringContainsString('--update-env', $command);
    }

    public function test_pm2_restart_command_defaults_to_port_3000(): void
    {
        $app = new Application([
            'name' => 'My App',
            'type' => 'nodejs',
            'deploy_path' => '/var/www/shipyard/my-app',
            'deployment_strategy' => 'atomic',
        ]);

        $command = $app->buildPm2RestartCommand();

        $this->assertStringContainsString('export PORT=3000', $command);
    }

    /**
     * Task 5b: PHP-FPM runs as the deploy user on home-layout (provisioned)
     * servers, so the in-place Laravel script's storage/bootstrap chown must
     * follow that user rather than being hardcoded to www-data, or the first
     * request 500s because the runtime user cannot write storage/.
     */
    public function test_in_place_laravel_script_chowns_writable_paths_to_the_deploy_user_on_provisioned_servers(): void
    {
        $app = new Application([
            'name' => 'My App',
            'type' => 'laravel',
            'deployment_strategy' => 'in_place',
            'deploy_path' => '/home/shipyard/my-app',
            'branch' => 'main',
        ]);
        $app->setRelation('server', new Server(['deploy_user' => 'shipyard']));

        $script = $app->getDeployScriptWithVariables();

        $this->assertStringContainsString('chown -R shipyard:www-data storage bootstrap/cache', $script);
        $this->assertStringNotContainsString('chown -R www-data:www-data', $script);
    }

    public function test_in_place_laravel_script_chowns_writable_paths_to_www_data_on_legacy_servers(): void
    {
        $app = new Application([
            'name' => 'My App',
            'type' => 'laravel',
            'deployment_strategy' => 'in_place',
            'deploy_path' => '/var/www/shipyard/my-app',
            'branch' => 'main',
        ]);
        $app->setRelation('server', new Server);

        $script = $app->getDeployScriptWithVariables();

        $this->assertStringContainsString('chown -R www-data:www-data storage bootstrap/cache', $script);
    }
}
