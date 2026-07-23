<?php

namespace Tests\Feature;

use App\Models\ServerSshKey;
use App\Services\AuthorizedKeysService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The authorized_keys script is uploaded and run as root (or via sudo), so
 * these tests assert the exact shape of what's shipped over SSH: key
 * content must never reach the shell outside of a single-quoted,
 * randomized heredoc, and the username must be shell-quoted wherever it
 * lands in the script.
 */
class ServerSshKeyShellSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    private function mockSsh(bool $scriptSucceeds = true, string $scriptOutput = ''): void
    {
        $this->uploadedScripts = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($scriptSucceeds, $scriptOutput) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptSucceeds, $scriptOutput) {
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

    private function service(): AuthorizedKeysService
    {
        return app(AuthorizedKeysService::class);
    }

    public function test_install_script_only_ever_uses_the_key_inside_a_heredoc(): void
    {
        $this->mockSsh();
        $key = ServerSshKey::factory()->installing()->create(['username' => 'deploy']);

        $this->service()->installKey($key);

        $script = $this->uploadedScripts[0];

        $heredocPos = strpos($script, "<<'SHIPYARD_EOF_");
        $keyPos = strpos($script, $key->public_key);

        $this->assertNotFalse($heredocPos, 'Script must contain a SHIPYARD_EOF_ heredoc opener.');
        $this->assertNotFalse($keyPos, 'Script must contain the raw key line.');
        $this->assertGreaterThan($heredocPos, $keyPos, 'The key must appear after the heredoc opener, i.e. only inside it.');
        $this->assertSame(1, substr_count($script, $key->public_key), 'The key line must appear exactly once.');

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString("-o 'deploy'", $script);
        $this->assertStringContainsString('install -d -m 0700', $script);
        $this->assertStringContainsString('.ssh"', $script);
        $this->assertStringContainsString('chmod 0600', $script);
        $this->assertStringContainsString('grep -qxF', $script);
    }

    public function test_install_script_uses_a_randomized_heredoc_delimiter(): void
    {
        $this->mockSsh();
        $key = ServerSshKey::factory()->installing()->create();

        $this->service()->installKey($key);
        $first = $this->uploadedScripts[0];

        $this->service()->installKey($key);
        $second = $this->uploadedScripts[1];

        preg_match('/SHIPYARD_EOF_(\w+)/', $first, $a);
        preg_match('/SHIPYARD_EOF_(\w+)/', $second, $b);

        $this->assertNotEmpty($a[1] ?? '');
        $this->assertNotEmpty($b[1] ?? '');
        $this->assertNotSame($a[1], $b[1]);
    }

    public function test_removal_script_uses_grep_v_and_the_same_heredoc_safety(): void
    {
        $this->mockSsh();
        $key = ServerSshKey::factory()->removing()->create(['username' => 'deploy']);

        $this->service()->removeKey($key);

        $script = $this->uploadedScripts[0];

        $heredocPos = strpos($script, "<<'SHIPYARD_EOF_");
        $keyPos = strpos($script, $key->public_key);

        $this->assertNotFalse($heredocPos);
        $this->assertNotFalse($keyPos);
        $this->assertGreaterThan($heredocPos, $keyPos);

        $this->assertStringContainsString('grep -vxF', $script);
        $this->assertStringContainsString("-o 'deploy'", $script);
        $this->assertStringContainsString('set -euo pipefail', $script);
    }

    public function test_removal_treats_a_missing_authorized_keys_file_as_success(): void
    {
        $this->mockSsh();
        $key = ServerSshKey::factory()->removing()->create();

        $this->service()->removeKey($key);
        $script = $this->uploadedScripts[0];

        $this->assertStringContainsString('if [ ! -f "$AK" ]; then', $script);
        $this->assertStringContainsString('exit 0', $script);
    }

    public function test_hostile_usernames_are_rejected_before_a_script_is_built(): void
    {
        $this->mockSsh();

        foreach (['root; rm -rf /', "www-data\nevil", '../etc', 'WWW-DATA', '1user', "user'"] as $username) {
            $key = ServerSshKey::factory()->installing()->create(['username' => $username]);

            try {
                $this->service()->installKey($key);
                $this->fail("Expected an InvalidArgumentException for username: {$username}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid username', $e->getMessage());
            }
        }

        $this->assertEmpty($this->uploadedScripts);
    }
}
