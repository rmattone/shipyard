<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\SSHService;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Tests\TestCase;

class FakeSsh2 extends SSH2
{
    public int $disconnectCount = 0;

    public bool $connected = true;

    /** @var string|false */
    public $execReturn = '';

    /** @var int|false */
    public $exitStatus = 0;

    public function __construct()
    {
        parent::__construct('unused.invalid', 22);
    }

    public function login($username, ...$args)
    {
        return true;
    }

    public function setTimeout($timeout) {}

    public function exec($command, $callback = null)
    {
        return $this->execReturn;
    }

    public function getExitStatus()
    {
        return $this->exitStatus;
    }

    public function disconnect()
    {
        $this->disconnectCount++;
        $this->connected = false;
    }

    public function isConnected($level = 0)
    {
        return $this->connected;
    }
}

class TestableSSHService extends SSHService
{
    /** @var array<int, FakeSsh2> */
    public array $clients = [];

    protected function makeSshClient(Server $server): SSH2
    {
        return $this->clients[] = new FakeSsh2;
    }

    protected function loadPrivateKey(Server $server): mixed
    {
        return 'fake-key';
    }
}

/**
 * DEPLOY-12: connections must be reused per server instead of leaking one
 * SSH session per connect() call. DEPLOY-13: phpseclib returning false must
 * surface as an error, not read as empty output ("structure missing").
 */
class SSHServiceConnectionTest extends TestCase
{
    private function makeServer(int $id): Server
    {
        $server = new Server;
        $server->id = $id;
        $server->forceFill([
            'host' => "server-{$id}.test",
            'port' => 22,
            'username' => 'root',
            'is_local' => false,
        ]);

        return $server;
    }

    public function test_repeated_connect_to_the_same_server_reuses_the_connection(): void
    {
        $service = new TestableSSHService;
        $server = $this->makeServer(1);

        $service->connect($server);
        $service->connect($server);
        $service->connect($server);

        $this->assertCount(1, $service->clients, 'connect() must reuse the live session for the same server.');
    }

    public function test_connecting_to_a_different_server_replaces_the_connection(): void
    {
        $service = new TestableSSHService;

        $service->connect($this->makeServer(1));
        $service->connect($this->makeServer(2));

        $this->assertCount(2, $service->clients);
        $this->assertSame(1, $service->clients[0]->disconnectCount, 'The previous connection must be closed, not leaked.');
    }

    public function test_a_dead_connection_is_reestablished(): void
    {
        $service = new TestableSSHService;
        $server = $this->makeServer(1);

        $service->connect($server);
        $service->clients[0]->connected = false;
        $service->connect($server);

        $this->assertCount(2, $service->clients, 'A dropped session must be replaced on the next connect().');
    }

    public function test_exec_returning_false_throws_instead_of_reading_as_empty_output(): void
    {
        $service = new TestableSSHService;
        $service->connect($this->makeServer(1));
        $service->clients[0]->execReturn = false;

        $this->expectException(RuntimeException::class);

        $service->execute('test -d /var/www/releases');
    }

    public function test_missing_exit_status_is_reported_as_failure(): void
    {
        $service = new TestableSSHService;
        $service->connect($this->makeServer(1));
        $service->clients[0]->execReturn = 'partial output before timeout';
        $service->clients[0]->exitStatus = false;

        $result = $service->execute('sleep 999');

        $this->assertFalse($result['success'], 'A command with no exit status (timeout) must not read as success.');
        $this->assertSame(-1, $result['exit_code']);
    }
}
