<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use RuntimeException;

class SSHService
{
    private ?SSH2 $ssh = null;
    private ?SFTP $sftp = null;
    private ?Server $server = null;

    public function connect(Server $server): self
    {
        $this->server = $server;
        $this->ssh = new SSH2($server->host, $server->port);
        $this->ssh->setTimeout(30);

        $key = PublicKeyLoader::load($server->private_key);

        if (!$this->ssh->login($server->username, $key)) {
            throw new RuntimeException("SSH authentication failed for {$server->host}");
        }

        return $this;
    }

    public function connectSftp(Server $server): self
    {
        $this->server = $server;
        $this->sftp = new SFTP($server->host, $server->port);
        $this->sftp->setTimeout(30);

        $key = PublicKeyLoader::load($server->private_key);

        if (!$this->sftp->login($server->username, $key)) {
            throw new RuntimeException("SFTP authentication failed for {$server->host}");
        }

        return $this;
    }

    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
        if ($this->sftp) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }
    }

    public function execute(string $command, int $timeout = 300): array
    {
        if (!$this->ssh) {
            throw new RuntimeException('Not connected to any server');
        }

        $this->ssh->setTimeout($timeout);
        $output = $this->ssh->exec($command);
        $exitCode = $this->ssh->getExitStatus();

        return [
            'output' => $output,
            'exit_code' => $exitCode ?? 0,
            'success' => ($exitCode ?? 0) === 0,
        ];
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        if (!$this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (!$this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->put($remotePath, file_get_contents($localPath));
    }

    public function uploadContent(string $content, string $remotePath): bool
    {
        if (!$this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (!$this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->put($remotePath, $content);
    }

    public function download(string $remotePath): ?string
    {
        if (!$this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (!$this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->get($remotePath);
    }

    public function fileExists(string $remotePath): bool
    {
        if (!$this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (!$this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->file_exists($remotePath);
    }

    public function testConnection(Server $server): array
    {
        try {
            $this->connect($server);
            $result = $this->execute('echo "Connection successful" && uname -a');
            $this->disconnect();

            return [
                'success' => true,
                'message' => 'Connection successful',
                'system_info' => trim($result['output']),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'system_info' => null,
            ];
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
