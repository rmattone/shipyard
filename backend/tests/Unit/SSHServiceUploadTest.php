<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\SSHService;
use phpseclib3\Net\SFTP;
use Tests\TestCase;

class FakeSftp extends SFTP
{
    public ?string $putRemoteFile = null;

    public $putData = null;

    public ?int $putMode = null;

    public int $disconnectCount = 0;

    public bool $connected = true;

    public function __construct()
    {
        parent::__construct('unused.invalid', 22);
    }

    public function login($username, ...$args)
    {
        return true;
    }

    public function setTimeout($timeout) {}

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $this->putRemoteFile = $remote_file;
        $this->putData = $data;
        $this->putMode = $mode;

        return true;
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

// Named distinctly from SSHServiceConnectionTest's TestableSSHService (same
// namespace, same test run) since that one fakes makeSshClient(), not
// makeSftpClient().
class TestableUploadSSHService extends SSHService
{
    /** @var array<int, FakeSftp> */
    public array $sftpClients = [];

    protected function makeSftpClient(Server $server): SFTP
    {
        return $this->sftpClients[] = new FakeSftp;
    }

    protected function loadPrivateKey(Server $server): mixed
    {
        return 'fake-key';
    }
}

/**
 * Restore dumps reach a gigabyte, so upload() must hand phpseclib a path and
 * let it stream, never read the file into memory first.
 */
class SSHServiceUploadTest extends TestCase
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

    public function test_upload_streams_from_the_local_path(): void
    {
        $localPath = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($localPath, 'SELECT 1;');

        $service = new TestableUploadSSHService;
        $service->connectSftp($this->makeServer(1));

        $this->assertTrue($service->upload($localPath, '/var/tmp/dump.sql'));

        $fake = $service->sftpClients[0];
        $this->assertSame('/var/tmp/dump.sql', $fake->putRemoteFile);
        $this->assertSame($localPath, $fake->putData, 'upload() must hand phpseclib the local path, not the file contents.');
        $this->assertSame(SFTP::SOURCE_LOCAL_FILE, $fake->putMode);

        unlink($localPath);
    }
}
