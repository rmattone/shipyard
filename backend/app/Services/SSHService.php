<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\Process;

class SSHService
{
    private ?SSH2 $ssh = null;

    private ?SFTP $sftp = null;

    private ?Server $server = null;

    private bool $isLocal = false;

    public function connect(Server $server): self
    {
        $this->isLocal = $server->isLocal();

        if ($this->isLocal) {
            $this->server = $server;

            return $this;
        }

        // Reuse the live session for repeated connect() calls to the same
        // server: one atomic deploy calls connect() five-plus times and used
        // to leak a fresh SSH session each time.
        if ($this->ssh !== null && $this->isSameServer($server) && $this->ssh->isConnected()) {
            $this->server = $server;

            return $this;
        }

        if (! $this->isSameServer($server)) {
            $this->disconnect();
        } elseif ($this->ssh !== null) {
            $this->ssh->disconnect();
        }

        $this->server = $server;

        $ssh = $this->makeSshClient($server);
        $ssh->setTimeout(30);

        if (! $ssh->login($server->username, $this->loadPrivateKey($server))) {
            throw new RuntimeException("SSH authentication failed for {$server->host}");
        }

        $this->ssh = $ssh;

        return $this;
    }

    public function connectSftp(Server $server): self
    {
        $this->isLocal = $server->isLocal();

        if ($this->isLocal) {
            $this->server = $server;

            return $this;
        }

        if ($this->sftp !== null && $this->isSameServer($server) && $this->sftp->isConnected()) {
            $this->server = $server;

            return $this;
        }

        if (! $this->isSameServer($server)) {
            $this->disconnect();
        } elseif ($this->sftp !== null) {
            $this->sftp->disconnect();
        }

        $this->server = $server;

        $sftp = $this->makeSftpClient($server);
        $sftp->setTimeout(30);

        if (! $sftp->login($server->username, $this->loadPrivateKey($server))) {
            throw new RuntimeException("SFTP authentication failed for {$server->host}");
        }

        $this->sftp = $sftp;

        return $this;
    }

    private function isSameServer(Server $server): bool
    {
        return $this->server !== null && $this->server->id === $server->id;
    }

    protected function makeSshClient(Server $server): SSH2
    {
        return new SSH2($server->host, $server->port);
    }

    protected function makeSftpClient(Server $server): SFTP
    {
        return new SFTP($server->host, $server->port);
    }

    protected function loadPrivateKey(Server $server): mixed
    {
        return PublicKeyLoader::load($server->private_key);
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

        $this->server = null;
        $this->isLocal = false;
    }

    public function execute(string $command, int $timeout = 300): array
    {
        if ($this->isLocal) {
            return $this->executeLocal($command, $timeout);
        }

        if (! $this->ssh) {
            throw new RuntimeException('Not connected to any server');
        }

        $this->ssh->setTimeout($timeout);
        $output = $this->ssh->exec($command);

        // A timed-out exec leaves the channel open (every later command dies
        // with "Please close the channel") and getExitStatus() can hold the
        // previous command's 0, making the failure read as success.
        if ($this->ssh->isTimeout()) {
            $this->ssh->reset();

            return [
                'output' => (is_string($output) ? $output : '')."\n[command timed out after {$timeout}s]",
                'exit_code' => -1,
                'success' => false,
            ];
        }

        // phpseclib returns false when the command could not be executed at
        // all (connection dropped, channel failure). Treating that as empty
        // output makes transient failures read as "directory missing".
        if ($output === false) {
            throw new RuntimeException("SSH command could not be executed (connection lost?): {$command}");
        }

        // getExitStatus() returns false when no status arrived (e.g. timeout);
        // that must not be mistaken for exit code 0.
        $exitCode = $this->ssh->getExitStatus();
        if (! is_int($exitCode)) {
            $exitCode = -1;
        }

        return [
            'output' => $output,
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    private function executeLocal(string $command, int $timeout = 300): array
    {
        // Commands that require sudo on local server
        if ($this->commandRequiresSudo($command)) {
            $command = 'sudo '.$command;
        }

        $process = Process::fromShellCommandline($command);
        $process->setTimeout($timeout);

        $process->run();

        return [
            'output' => $process->getOutput().$process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
            'success' => $process->isSuccessful(),
        ];
    }

    /**
     * Check if a command requires sudo for local execution.
     */
    private function commandRequiresSudo(string $command): bool
    {
        $sudoCommands = [
            'nginx ',
            'nginx -t',
            'systemctl ',
            'certbot ',
            'ln -sf /etc/nginx',
            'rm -f /etc/nginx',
            'rm -f /etc/letsencrypt',
        ];

        foreach ($sudoCommands as $sudoCommand) {
            if (str_starts_with($command, $sudoCommand)) {
                return true;
            }
        }

        return false;
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        if ($this->isLocal) {
            $dir = dirname($remotePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return copy($localPath, $remotePath);
        }

        if (! $this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (! $this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        // Stream from disk rather than buffering: restore dumps reach a
        // gigabyte, and file_get_contents on one would exhaust memory_limit
        // before anything reached the server.
        return $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }

    public function uploadContent(string $content, string $remotePath): bool
    {
        if ($this->isLocal) {
            // Use sudo for system paths that require elevated permissions
            if ($this->requiresSudo($remotePath)) {
                // Write to temp file first, then sudo cp to destination
                $tempFile = tempnam(sys_get_temp_dir(), 'srv_');
                file_put_contents($tempFile, $content);

                $process = Process::fromShellCommandline(
                    sprintf('sudo cp %s %s && sudo chmod 644 %s',
                        escapeshellarg($tempFile),
                        escapeshellarg($remotePath),
                        escapeshellarg($remotePath)
                    )
                );
                $process->run();
                unlink($tempFile);

                return $process->isSuccessful();
            }
            $dir = dirname($remotePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents($remotePath, $content) !== false;
        }

        if (! $this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (! $this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->put($remotePath, $content);
    }

    public function download(string $remotePath): ?string
    {
        if ($this->isLocal) {
            if (! file_exists($remotePath)) {
                return null;
            }

            return file_get_contents($remotePath);
        }

        if (! $this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (! $this->sftp) {
            throw new RuntimeException('Not connected to any server');
        }

        return $this->sftp->get($remotePath);
    }

    public function fileExists(string $remotePath): bool
    {
        if ($this->isLocal) {
            return file_exists($remotePath);
        }

        if (! $this->sftp && $this->server) {
            $this->connectSftp($this->server);
        }

        if (! $this->sftp) {
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

            $message = $server->isLocal() ? 'Local execution ready' : 'Connection successful';

            return [
                'success' => true,
                'message' => $message,
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

    /**
     * Check if a path requires sudo for local execution.
     */
    private function requiresSudo(string $path): bool
    {
        $sudoPaths = [
            '/etc/nginx',
            '/etc/letsencrypt',
            '/etc/systemd',
            '/var/log/nginx',
        ];

        foreach ($sudoPaths as $sudoPath) {
            if (str_starts_with($path, $sudoPath)) {
                return true;
            }
        }

        return false;
    }
}
