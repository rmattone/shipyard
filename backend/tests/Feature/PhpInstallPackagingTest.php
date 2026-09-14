<?php

namespace Tests\Feature;

use App\Models\DatabaseInstallation;
use App\Models\Server;
use App\Services\DatabaseInstallationService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Installing the unversioned "php" metapackage dragged apache2 onto every
 * provisioned server: phpX.Y depends on
 * "libapache2-mod-phpX.Y | phpX.Y-fpm | phpX.Y-cgi" and apt satisfies that
 * with the first alternative, even when php-fpm is on the same command line.
 * apache2 then beat nginx to port 80 on the next reboot. Unversioned extension
 * packages caused a second failure: sury's php-redis tracks the newest series
 * in the repo, so a box provisioned for 8.4 also got a half-installed 8.5.
 *
 * The installer must resolve one concrete version and install only phpX.Y-*.
 */
class PhpInstallPackagingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executedCommands = [];

    /**
     * @param  array<string, string>  $overrides  checked before the defaults
     */
    private function mockSsh(array $overrides = []): void
    {
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) use ($overrides) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($overrides) {
                $this->executedCommands[] = $command;

                $fakes = $overrides + [
                    'ppa.launchpadcontent.net' => 'available',
                    'cat /etc/os-release' => 'ID=ubuntu VERSION_CODENAME=noble',
                    'apt-cache depends php-fpm' => '8.4',
                    // must precede the generic is-active fake below
                    'is-active apache2' => 'inactive',
                    'PHP_MAJOR_VERSION' => '8.4',
                    'is-active' => 'active',
                    'php --version' => 'PHP 8.4.24 (cli)',
                    'composer --version' => 'Composer version 2.8.4',
                ];

                foreach ($fakes as $needle => $output) {
                    if (str_contains($command, $needle)) {
                        return ['output' => $output, 'exit_code' => 0, 'success' => true];
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function runPhpInstall(): DatabaseInstallation
    {
        $installation = DatabaseInstallation::factory()->create([
            'server_id' => Server::factory()->create(['php_version' => null])->id,
            'engine' => 'php',
            'status' => 'pending',
        ]);

        try {
            app(DatabaseInstallationService::class)->install($installation);
        } catch (RuntimeException) {
            // the service marks the row failed and rethrows; assertions below
            // inspect the persisted state either way
        }

        return $installation->refresh();
    }

    /** @return array<int, string> */
    private function aptInstallCommands(): array
    {
        return array_values(array_filter(
            $this->executedCommands,
            fn (string $c): bool => str_contains($c, 'apt-get install')
        ));
    }

    public function test_it_never_requests_the_apache_pulling_php_metapackage(): void
    {
        $this->mockSsh();

        $this->assertSame('success', $this->runPhpInstall()->status);

        foreach ($this->aptInstallCommands() as $command) {
            $this->assertDoesNotMatchRegularExpression(
                '/\sphp(\s|$)/',
                $command,
                "The bare php metapackage pulls libapache2-mod-php: {$command}"
            );
            $this->assertStringNotContainsString('libapache2', $command);
        }
    }

    public function test_it_installs_only_versioned_packages_for_the_resolved_series(): void
    {
        $this->mockSsh();

        $this->runPhpInstall();

        $phpInstall = array_values(array_filter(
            $this->aptInstallCommands(),
            fn (string $c): bool => str_contains($c, 'php8.4-fpm')
        ));

        $this->assertCount(1, $phpInstall, 'Expected exactly one versioned PHP install command.');
        $this->assertStringContainsString(
            'php8.4-fpm php8.4-cli php8.4-mysql php8.4-pgsql php8.4-mbstring php8.4-xml '.
            'php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd php8.4-intl php8.4-redis',
            $phpInstall[0]
        );
    }

    public function test_it_records_the_resolved_version_rather_than_probing_after_the_fact(): void
    {
        // apt points at 8.3 while the box's default CLI is a leftover 8.4:
        // the server must be recorded as the series ShipYard actually installed
        $this->mockSsh([
            'apt-cache depends php-fpm' => '8.3',
            'PHP_MAJOR_VERSION' => '8.4',
        ]);

        $installation = $this->runPhpInstall();

        $this->assertSame('success', $installation->status);
        $this->assertSame('8.3', $installation->server->fresh()->php_version);
        $this->assertStringContainsString('the default `php` on this host is 8.4', $installation->log);
    }

    public function test_it_pins_apache_out_of_apt_and_masks_the_unit(): void
    {
        $this->mockSsh();

        $this->runPhpInstall();

        $pinCommands = array_values(array_filter(
            $this->executedCommands,
            fn (string $c): bool => str_contains($c, 'preferences.d/shipyard-no-apache')
        ));
        $this->assertCount(1, $pinCommands, 'Expected the apt pin to be written once.');

        $this->assertSame(1, preg_match("/echo '([^']+)'/", $pinCommands[0], $matches));
        $pin = base64_decode($matches[1]);
        $this->assertStringContainsString('Package: apache2 apache2-bin apache2-data apache2-utils libapache2-mod-php*', $pin);
        $this->assertStringContainsString('Pin-Priority: -1', $pin);

        $masked = array_filter($this->executedCommands, fn (string $c): bool => str_contains($c, 'mask apache2'));
        $this->assertNotEmpty($masked, 'apache2 must be masked so a reinstall cannot bring it back at boot.');
    }

    public function test_it_writes_the_pin_before_any_php_package_is_resolved(): void
    {
        $this->mockSsh();

        $this->runPhpInstall();

        $pinIndex = null;
        $installIndex = null;
        foreach ($this->executedCommands as $index => $command) {
            if ($pinIndex === null && str_contains($command, 'preferences.d/shipyard-no-apache')) {
                $pinIndex = $index;
            }
            if ($installIndex === null && str_contains($command, 'php8.4-fpm')) {
                $installIndex = $index;
            }
        }

        $this->assertNotNull($pinIndex);
        $this->assertNotNull($installIndex);
        $this->assertLessThan(
            $installIndex,
            $pinIndex,
            'The pin is useless if apt has already resolved the PHP packages.'
        );
    }

    public function test_it_leaves_a_running_apache_for_the_operator_to_deal_with(): void
    {
        $this->mockSsh(['is-active apache2' => 'active']);

        $installation = $this->runPhpInstall();

        $this->assertStringContainsString('apache2 is currently serving on this host', $installation->log);

        $masked = array_filter($this->executedCommands, fn (string $c): bool => str_contains($c, 'mask apache2'));
        $this->assertSame([], array_values($masked), 'ShipYard must not stop a web server that is serving traffic.');
    }

    public function test_it_aborts_when_the_php_version_cannot_be_resolved(): void
    {
        $this->mockSsh(['apt-cache depends php-fpm' => 'not-a-version']);

        $installation = $this->runPhpInstall();

        $this->assertSame('failed', $installation->status);
        $this->assertNull($installation->server->fresh()->php_version);
        $this->assertSame(
            [],
            array_values(array_filter($this->aptInstallCommands(), fn (string $c): bool => str_contains($c, '-fpm'))),
            'No PHP package may be installed when the target version is unknown.'
        );
    }
}
