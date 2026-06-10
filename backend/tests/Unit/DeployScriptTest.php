<?php

namespace Tests\Unit;

use App\Models\Application;
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
}
