<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\SSHService;
use App\Services\TerminalService;
use App\Support\Ssh\HostKey;
use App\Support\Ssh\InteractiveSSH2;
use Mockery;
use phpseclib3\Common\Functions\Strings;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Tests\Support\HostKeyFixture;
use Tests\TestCase;

class SshHostKeySecurityTest extends TestCase
{
    private function server(?string $key): Server
    {
        return new Server(['host' => 'server.test', 'port' => 22, 'username' => 'root', 'ssh_host_key' => $key, 'is_local' => false]);
    }

    public function test_ssh_sftp_and_terminal_reject_changed_keys_before_authentication(): void
    {
        foreach (['ssh', 'sftp', 'terminal'] as $transport) {
            $class = match ($transport) {
                'ssh' => SSH2::class, 'sftp' => SFTP::class, default => InteractiveSSH2::class
            };
            $client = Mockery::mock($class);
            $client->shouldReceive('setTimeout')->once();
            $client->shouldReceive('setPreferredAlgorithms')->once();
            $client->shouldReceive('getServerPublicHostKey')->once()->andReturn(EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH'));
            $client->shouldReceive('disconnect')->once();
            $client->shouldNotReceive('login');
            $client->shouldNotReceive('openShell');
            $service = $this->service($transport, $client);
            try {
                $this->connect($service, $transport, $this->server(HostKeyFixture::key()));
                $this->fail('Changed host identity must be rejected.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('host key mismatch', $e->getMessage());
            }
        }
    }

    public function test_unknown_keys_fail_without_opening_a_network_connection(): void
    {
        foreach (['ssh', 'sftp', 'terminal'] as $transport) {
            $client = Mockery::mock($transport === 'terminal' ? InteractiveSSH2::class : SFTP::class);
            $client->shouldNotReceive('getServerPublicHostKey');
            $client->shouldNotReceive('login');
            try {
                $this->connect($this->service($transport, $client), $transport, $this->server(null));
                $this->fail('An unknown host must not be implicitly trusted.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('trusted SSH public host key', $e->getMessage());
            }
        }
    }

    public function test_matching_keys_are_verified_before_login_for_every_transport(): void
    {
        foreach (['ssh', 'sftp', 'terminal'] as $transport) {
            $class = match ($transport) {
                'ssh' => SSH2::class, 'sftp' => SFTP::class, default => InteractiveSSH2::class
            };
            $client = Mockery::mock($class);
            $client->shouldReceive('setTimeout');
            $client->shouldReceive('disconnect');
            $client->shouldReceive('setPreferredAlgorithms')->once();
            $client->shouldReceive('getServerPublicHostKey')->once()->ordered()->andReturn(HostKeyFixture::key());
            $client->shouldReceive('login')->once()->ordered()->andReturn(true);
            $client->shouldReceive('setWindowSize');
            $client->shouldReceive('openShell');
            $server = $this->server(HostKeyFixture::key());
            $server->private_key = EC::createKey('Ed25519')->toString('PKCS8');
            $this->connect($this->service($transport, $client), $transport, $server);
            $this->addToAssertionCount(1);
        }
    }

    public function test_rsa_sha2_signature_labels_match_the_saved_rsa_public_key(): void
    {
        $key = RSA::createKey(2048)->getPublicKey()->toString('OpenSSH');
        $client = Mockery::mock(SSH2::class);
        $client->shouldReceive('setPreferredAlgorithms')->with(['hostkey' => ['rsa-sha2-512', 'rsa-sha2-256']])->once();
        $client->shouldReceive('getServerPublicHostKey')->andReturn(str_replace('ssh-rsa ', 'rsa-sha2-512 ', $key));
        HostKey::verify($client, $key);
        $this->addToAssertionCount(1);
    }

    public function test_ed25519_host_pinning_keeps_rsa_sha2_user_authentication(): void
    {
        $client = new class('unused.test') extends SSH2
        {
            public string $packet = '';

            public function getServerPublicHostKey()
            {
                return HostKeyFixture::key();
            }

            protected function send_binary_packet($data, $logged = null)
            {
                $this->packet = $data;
                throw new RuntimeException('Authentication packet captured without network access.');
            }
        };
        HostKey::verify($client, HostKeyFixture::key());
        // Simulate the server's advertised signature support, then execute
        // phpseclib's real RSA authentication algorithm selection.
        (new \ReflectionProperty(SSH2::class, 'supported_private_key_algorithms'))
            ->setValue($client, ['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256']);
        try {
            (new \ReflectionMethod(SSH2::class, 'privatekey_login'))
                ->invoke($client, 'shipyard', RSA::createKey(2048));
            $this->fail('Expected the outgoing packet to be intercepted.');
        } catch (RuntimeException $e) {
            $this->assertSame('Authentication packet captured without network access.', $e->getMessage());
        }
        $packet = $client->packet;
        [$message, $username, $service, $method, $signed, $algorithm] =
            Strings::unpackSSH2('CsssCs', $packet);
        $this->assertSame('shipyard', $username);
        $this->assertSame('publickey', $method);
        $this->assertContains($algorithm, ['rsa-sha2-256', 'rsa-sha2-512']);
    }

    private function connect(object $service, string $transport, Server $server): void
    {
        match ($transport) {
            'ssh' => $service->connect($server),
            'sftp' => $service->connectSftp($server),
            default => $service->open($server, 80, 24),
        };
    }

    private function service(string $transport, SSH2 $client): object
    {
        if ($transport === 'terminal') {
            return new class($client) extends TerminalService
            {
                public function __construct(private InteractiveSSH2 $client) {}

                protected function makeClient(Server $server): InteractiveSSH2
                {
                    return $this->client;
                }
            };
        }

        return new class($client) extends SSHService
        {
            public function __construct(private SSH2 $client) {}

            protected function makeSshClient(Server $server): SSH2
            {
                return $this->client;
            }

            protected function makeSftpClient(Server $server): SFTP
            {
                return $this->client;
            }

            protected function loadPrivateKey(Server $server): mixed
            {
                return 'fake';
            }
        };
    }
}
