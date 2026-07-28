<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\PhpFpmPoolService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The ShipYard FPM pool is what makes the home directory layout actually
 * serve PHP: the pool runs as the deploy user (code owner == code executor)
 * while nginx keeps talking to a www-data owned socket. These tests pin the
 * dangerous properties: the pool file is validated with php-fpm -t before
 * FPM is ever reloaded, and a failed validation must roll the file back.
 */
class PhpFpmPoolTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    /** @var string[] */
    private array $executedCommands = [];

    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true): void
    {
        $this->uploadedScripts = [];
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptOutput, $scriptSucceeds) {
                $this->executedCommands[] = $command;

                if (str_starts_with($command, 'bash ')) {
                    return [
                        'output' => $scriptOutput,
                        'exit_code' => $scriptSucceeds ? 0 : 1,
                        'success' => $scriptSucceeds,
                    ];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function provisionedServer(): Server
    {
        $this->createOrgUser();

        return Server::factory()->create(['deploy_user' => 'shipyard']);
    }

    public function test_pool_config_runs_as_the_deploy_user(): void
    {
        $server = $this->provisionedServer();

        $config = app(PhpFpmPoolService::class)->poolConfig($server, '8.3');

        $this->assertStringContainsString('[shipyard]', $config);
        $this->assertStringContainsString('user = shipyard', $config);
        $this->assertStringContainsString('group = shipyard', $config);
        $this->assertStringContainsString('listen = /run/php/php8.3-fpm-shipyard.sock', $config);
        $this->assertStringContainsString('listen.owner = www-data', $config);
        $this->assertStringContainsString('listen.group = www-data', $config);
    }

    public function test_ensure_pool_script_validates_before_reload_and_can_roll_back(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_POOL_APPLIED');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');

        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString('/etc/php/8.3/fpm/pool.d/shipyard.conf', $script);
        $this->assertStringContainsString('php-fpm8.3 -t', $script);
        $this->assertStringContainsString('reload-or-restart php8.3-fpm', $script);
        // Validation failure must restore the previous pool file.
        $this->assertStringContainsString('SHIPYARD_POOL_INVALID', $script);
        // The reload must come after the validation in the script.
        $this->assertGreaterThan(
            strpos($script, 'php-fpm8.3 -t'),
            strpos($script, 'reload-or-restart'),
        );
        // A failed validation must actually restore the previous file (or
        // remove a brand-new one) BEFORE the invalid marker is emitted; an
        // implementation that only prints the marker without touching $POOL
        // would leave a broken pool file in place.
        $validationPos = strpos($script, 'php-fpm8.3 -t');
        $invalidMarkerPos = strpos($script, 'SHIPYARD_POOL_INVALID');
        $restoreBranch = substr($script, $validationPos, $invalidMarkerPos - $validationPos);
        $this->assertMatchesRegularExpression(
            '/mv\s+"\$BACKUP"\s+"\$POOL"/',
            $restoreBranch,
            'A failed validation must restore the previous pool file, not just report failure.'
        );
        $this->assertMatchesRegularExpression(
            '/rm\s+-f\s+"\$POOL"/',
            $restoreBranch,
            'A failed validation on a brand-new pool file must remove it, not just report failure.'
        );
    }

    public function test_ensure_pool_throws_when_validation_fails_remotely(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_POOL_INVALID', scriptSucceeds: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('was rolled back');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_requires_a_deploy_user(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no deploy user');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_rejects_malformed_php_versions(): void
    {
        $server = $this->provisionedServer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PHP version');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3; rm -rf /');
    }

    public function test_ensure_pool_rejects_a_deploy_user_that_would_inject_pool_directives(): void
    {
        $server = $this->provisionedServer();
        // deploy_user is interpolated directly into a root-installed INI
        // file; a newline would let an attacker (or a corrupted row) inject
        // arbitrary pool directives. Bypass the model mutator/DB round trip
        // with forceFill so the malicious value reaches ensurePool() intact.
        $server->forceFill(['deploy_user' => "bad\nuser"]);

        $this->expectException(InvalidArgumentException::class);

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_throws_when_php_fpm_is_not_installed(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_PHP_MISSING', scriptSucceeds: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not installed');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_does_nothing_when_pool_is_unchanged_and_socket_is_live(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_POOL_UNCHANGED');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');

        // No exception means the short-circuit path was accepted. Pin fix 1
        // here too: the script must gate that short-circuit on a live
        // socket, not merely identical file content, otherwise a run that
        // died between install and reload would report success forever.
        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString('test -S', $script);
        $this->assertStringContainsString('php8.3-fpm-shipyard.sock', $script);

        // The cleanup script upload must itself be removed afterwards, not
        // left behind on the server.
        $removals = array_filter($this->executedCommands, fn ($c) => str_starts_with($c, 'rm -f'));
        $this->assertNotEmpty($removals, 'The uploaded script must be cleaned up after execution.');
    }
}
