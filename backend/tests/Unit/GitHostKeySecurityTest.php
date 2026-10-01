<?php

namespace Tests\Unit;

use App\Models\Application;
use App\Models\GitProvider;
use App\Services\DeploymentService;
use App\Services\GitProviderService;
use App\Support\Ssh\GitHostKey;
use App\Support\Ssh\HostKey;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\HostKeyFixture;
use Tests\TestCase;

class GitHostKeySecurityTest extends TestCase
{
    public function test_all_generated_ssh_operations_use_saved_trust_and_propagate_rejection(): void
    {
        $provider = new GitProvider(['type' => 'github', 'private_key' => 'test-key', 'ssh_host_key' => HostKeyFixture::key()]);
        $service = new GitProviderService;
        $dir = sys_get_temp_dir().'/shipyard-host-key-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $app = new Application;
        $app->setRelation('gitProvider', $provider);
        $wrap = new ReflectionMethod(DeploymentService::class, 'wrapScriptWithSSHKey');
        $scripts = [
            $service->generateSSHCloneCommand($provider, 'https://github.com/team/repo', 'main', $dir),
            $service->generateSSHPullCommand($provider, 'https://github.com/team/repo', 'main', $dir),
            $service->generateSSHTestCommand($provider),
            $wrap->invoke(app(DeploymentService::class), $app, 'git fetch origin'),
        ];
        // Execute the generated wrappers with a recording transport. This checks
        // shell expansion, the actual known-hosts contents, and failure propagation.
        file_put_contents($dir.'/ssh', <<<'SH'
#!/bin/sh
strict=0
hosts=''
for arg in "$@"; do
  case "$arg" in
    StrictHostKeyChecking=yes) strict=1 ;;
    UserKnownHostsFile=*) hosts=${arg#UserKnownHostsFile=} ;;
  esac
done
[ "$strict" = 1 ] || exit 91
[ -f "$hosts" ] || exit 92
[ "$(cat "$hosts")" = "$EXPECTED_HOSTS" ] || exit 93
printf '%s' "$hosts" > "$RECORD_PATH"
exit "$TRANSPORT_EXIT"
SH);
        file_put_contents($dir.'/git', <<<'SH'
#!/bin/sh
[ "$1" = remote ] && exit 0
exec "$GIT_SSH" git@github.com
SH);
        chmod($dir.'/ssh', 0700);
        chmod($dir.'/git', 0700);
        try {
            foreach ($scripts as $script) {
                file_put_contents($dir.'/operation.sh', $script);
                $syntax = new Process(['bash', '-n', $dir.'/operation.sh']);
                $syntax->run();
                $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
                foreach ([0, 255] as $exit) {
                    $process = new Process(['bash', $dir.'/operation.sh'], $dir, [
                        'PATH' => $dir.':'.getenv('PATH'),
                        'TMPDIR' => $dir,
                        'EXPECTED_HOSTS' => trim(GitHostKey::knownHosts($provider)),
                        'RECORD_PATH' => $dir.'/record',
                        'TRANSPORT_EXIT' => (string) $exit,
                        'GIT_SSH_COMMAND' => false,
                    ]);
                    $process->run();
                    $this->assertSame($exit, $process->getExitCode(), $process->getErrorOutput());
                    $hostsFile = file_get_contents($dir.'/record');
                    $this->assertFileDoesNotExist($hostsFile, 'Temporary trust file must be cleaned up; the database retains trust.');
                }
            }
        } finally {
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_known_hosts_cannot_include_wildcards_or_extra_hosts(): void
    {
        $provider = new GitProvider(['type' => 'github', 'host' => '*.test,other.test', 'ssh_host_key' => HostKeyFixture::key()]);
        $this->expectException(RuntimeException::class);
        GitHostKey::knownHosts($provider);
    }

    public function test_untrusted_git_provider_is_rejected_before_generating_a_deployment(): void
    {
        $provider = new GitProvider(['type' => 'github', 'private_key' => 'test-key']);
        $this->expectException(RuntimeException::class);
        (new GitProviderService)->generateSSHCloneCommand($provider, 'https://github.com/team/repo', 'main', '/tmp/app');
    }

    public function test_comments_are_not_copied_into_known_hosts_or_shell_scripts(): void
    {
        $key = HostKey::normalize(HostKeyFixture::key());
        $provider = new GitProvider(['type' => 'github', 'ssh_host_key' => $key.' $(touch /tmp/not-executed)']);
        $this->assertSame('github.com '.$key."\n", GitHostKey::knownHosts($provider));
    }
}
