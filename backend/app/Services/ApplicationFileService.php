<?php

namespace App\Services;

use App\Models\Application;
use App\Support\RemoteSudo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApplicationFileService
{
    public const MAX_UPLOAD_KB = 102400;

    public function __construct(private SSHService $sshService) {}

    public function defaultPath(Application $app): string
    {
        if ($app->isLaravel()) {
            return ($app->usesAtomicDeployments() ? 'shared/' : '').'storage/app/private';
        }

        return $app->usesAtomicDeployments() ? 'shared/uploads' : '.shipyard/uploads';
    }

    public function browse(Application $app, ?string $path = null): array
    {
        $path ??= $this->defaultPath($app);
        try {
            $parts = explode("\0", rtrim($this->run($app, 'browse', $path), "\0"));
            [$resolved, $exists, $public, $ephemeral] = array_splice($parts, 0, 4);
            $folders = [];
            foreach (array_chunk($parts, 4) as [$name, $relative, $isPublic, $isEphemeral]) {
                $folders[] = ['name' => $name, 'path' => $relative, 'public' => $isPublic === '1', 'ephemeral' => $isEphemeral === '1'];
            }
            usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

            return [
                'path' => $path,
                'absolute_path' => $resolved,
                'exists' => $exists === '1',
                'public' => $public === '1',
                'ephemeral' => $ephemeral === '1',
                'default_path' => $this->defaultPath($app),
                'folders' => $folders,
                'truncated' => count($folders) >= 1000,
                'max_upload_bytes' => self::MAX_UPLOAD_KB * 1024,
            ];
        } finally {
            $this->sshService->disconnect();
        }
    }

    public function createDirectory(Application $app, string $path, string $name, bool $allowPublic = false): string
    {
        $this->validateName($name);
        try {
            return $this->run($app, 'mkdir', $path, $name, allowPublic: $allowPublic);
        } finally {
            $this->sshService->disconnect();
        }
    }

    public function upload(Application $app, string $path, UploadedFile $file, bool $overwrite = false, bool $allowPublic = false): string
    {
        $name = $file->getClientOriginalName();
        $this->validateName($name);
        $this->validatePath($path);
        $temporaryDirectory = null;
        try {
            // Check the destination before transferring bytes to the server.
            $destination = $this->browse($app, $path);
            if ($destination['public'] && ! $allowPublic) {
                throw ValidationException::withMessages(['allow_public' => 'Confirm that this folder may be web-accessible.']);
            }
            $this->sshService->connect($app->server);
            $result = $this->sshService->execute('mktemp -d /tmp/shipyard-upload-XXXXXXXXXXXX', 30);
            if (! $result['success'] || ! preg_match('~\A/tmp/shipyard-upload-[A-Za-z0-9]+\z~', trim($result['output']))) {
                throw new RuntimeException('Could not prepare a private upload on the server.');
            }
            $temporaryDirectory = trim($result['output']);
            if (! $this->sshService->upload($file->getRealPath(), $temporaryDirectory.'/file', 240)) {
                throw new RuntimeException('The file transfer failed. Please try again.');
            }

            return $this->run($app, 'upload', $path, $name, $temporaryDirectory.'/file', $overwrite, $allowPublic);
        } finally {
            try {
                if ($temporaryDirectory !== null) {
                    $this->sshService->execute('rm -f -- '.escapeshellarg($temporaryDirectory.'/file').' && rmdir -- '.escapeshellarg($temporaryDirectory), 30);
                }
            } catch (\Throwable $e) {
                report($e);
            } finally {
                $this->sshService->disconnect();
            }
        }
    }

    private function run(Application $app, string $action, string $path, string $name = '', string $source = '', bool $overwrite = false, bool $allowPublic = false): string
    {
        $this->validatePath($path);
        $server = $app->server;
        $owner = $app->isLaravel() ? $server->phpRuntimeUser() : ($server->deploy_user ?? $server->username);
        $args = [$action, $app->deploy_path, $path, $owner, $app->usesAtomicDeployments() ? '1' : '0', $app->type, $this->defaultPath($app), $name, $source, $overwrite ? '1' : '0', $allowPublic ? '1' : '0'];
        $command = 'bash -c '.escapeshellarg(file_get_contents(resource_path('scripts/application-files.sh'))).' -- '.implode(' ', array_map('escapeshellarg', $args));
        $this->sshService->connect($server);
        $result = $this->sshService->execute(RemoteSudo::wrap($server, $command).' 2>&1', 240);
        if (! $result['success']) {
            if (in_array($result['exit_code'], [42, 44], true)) {
                throw ValidationException::withMessages(['path' => trim($result['output'])]);
            }
            if ($result['exit_code'] === 43) {
                throw new HttpException(409, trim($result['output']));
            }
            throw new RuntimeException('Could not access the application folder. Check the server connection, available space, and filesystem permissions.');
        }

        return $result['output'];
    }

    private function validatePath(string $path): void
    {
        if (strlen($path) > 2048 || str_starts_with($path, '/') || preg_match('/[\\\\\x00-\x1f\x7f]/', $path)) {
            throw ValidationException::withMessages(['path' => 'Choose a folder inside this application.']);
        }
        foreach ($path === '' ? [] : explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..', '.git'], true)) {
                throw ValidationException::withMessages(['path' => 'Choose a folder inside this application.']);
            }
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '' || strlen($name) > 255 || in_array($name, ['.', '..', '.git'], true) || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $name)) {
            throw ValidationException::withMessages(['name' => 'Use a name without slashes or control characters (up to 255 bytes).']);
        }
    }
}
