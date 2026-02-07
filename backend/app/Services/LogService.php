<?php

namespace App\Services;

use App\Models\Application;
use RuntimeException;

class LogService
{
    public function __construct(
        private SSHService $sshService
    ) {}

    /**
     * Get list of available log files for an application.
     */
    public function getLogFiles(Application $app): array
    {
        $logPath = $app->getLogsPath();

        $this->sshService->connect($app->server);

        // Check if logs directory exists
        $result = $this->sshService->execute("test -d {$logPath} && echo 'exists'");

        if (!$result['success'] || trim($result['output']) !== 'exists') {
            $this->sshService->disconnect();
            return [];
        }

        // List log files with details
        $result = $this->sshService->execute(
            "find {$logPath} -maxdepth 1 -name '*.log' -type f -exec stat --format='%n|%s|%Y' {} \\; 2>/dev/null | sort -t'|' -k3 -rn"
        );

        $this->sshService->disconnect();

        if (!$result['success'] || empty(trim($result['output']))) {
            return [];
        }

        $files = [];
        $lines = explode("\n", trim($result['output']));

        foreach ($lines as $line) {
            if (empty($line)) continue;

            $parts = explode('|', $line);
            if (count($parts) !== 3) continue;

            [$path, $size, $mtime] = $parts;
            $filename = basename($path);

            $files[] = [
                'name' => $filename,
                'path' => $path,
                'size' => (int) $size,
                'last_modified' => date('Y-m-d H:i:s', (int) $mtime),
            ];
        }

        return $files;
    }

    /**
     * Get log file content with optional search and line limit.
     */
    public function getLogContent(Application $app, string $filename, int $lines = 500, ?string $search = null): array
    {
        // Validate filename to prevent path traversal
        if (preg_match('/[\/\\\\]/', $filename) || $filename === '.' || $filename === '..') {
            throw new RuntimeException('Invalid filename');
        }

        $logPath = "{$app->getLogsPath()}/{$filename}";

        $this->sshService->connect($app->server);

        // Check if file exists and get its stats
        $result = $this->sshService->execute("test -f {$logPath} && echo 'exists'");

        if (!$result['success'] || trim($result['output']) !== 'exists') {
            $this->sshService->disconnect();
            throw new RuntimeException("Log file not found: {$filename}");
        }

        // Get file stats
        $statsResult = $this->sshService->execute("stat --format='%s' {$logPath}");
        $fileSize = $statsResult['success'] ? (int) trim($statsResult['output']) : 0;

        // Get total line count
        $wcResult = $this->sshService->execute("wc -l < {$logPath}");
        $totalLines = $wcResult['success'] ? (int) trim($wcResult['output']) : 0;

        // Get log content
        if ($search) {
            // Escape special characters in search term for grep
            $escapedSearch = escapeshellarg($search);
            $command = "tail -n {$lines} {$logPath} | grep -i {$escapedSearch}";
        } else {
            $command = "tail -n {$lines} {$logPath}";
        }

        $result = $this->sshService->execute($command);

        $this->sshService->disconnect();

        $content = $result['success'] ? $result['output'] : '';
        $returnedLines = empty($content) ? 0 : substr_count($content, "\n") + 1;

        return [
            'content' => $content,
            'filename' => $filename,
            'total_lines' => $totalLines,
            'returned_lines' => $returnedLines,
            'file_size' => $fileSize,
        ];
    }
}
