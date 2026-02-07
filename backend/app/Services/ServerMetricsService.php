<?php

namespace App\Services;

use App\Models\Server;
use Carbon\Carbon;

class ServerMetricsService
{
    public function __construct(
        private SSHService $sshService
    ) {}

    public function getMetrics(Server $server): array
    {
        $this->sshService->connect($server);

        try {
            $metrics = [
                'memory' => $this->getMemoryMetrics(),
                'cpu' => $this->getCpuMetrics(),
                'disk' => $this->getDiskMetrics(),
                'uptime' => $this->getUptimeMetrics(),
                'load' => $this->getLoadMetrics(),
                'collected_at' => Carbon::now()->toIso8601String(),
            ];

            return $metrics;
        } finally {
            $this->sshService->disconnect();
        }
    }

    private function getMemoryMetrics(): array
    {
        $result = $this->sshService->execute("free -b | awk 'NR==2 {print \$2,\$3,\$4,\$7}'");

        if (!$result['success'] || empty(trim($result['output']))) {
            return [
                'total' => 0,
                'used' => 0,
                'free' => 0,
                'available' => 0,
                'percentage' => 0,
            ];
        }

        $parts = preg_split('/\s+/', trim($result['output']));
        $total = (int) ($parts[0] ?? 0);
        $used = (int) ($parts[1] ?? 0);
        $free = (int) ($parts[2] ?? 0);
        $available = (int) ($parts[3] ?? 0);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'available' => $available,
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function getCpuMetrics(): array
    {
        // Try to get CPU usage from /proc/stat (more reliable than top)
        $result = $this->sshService->execute(
            "cat /proc/stat | head -1 | awk '{usage=(\$2+\$4)*100/(\$2+\$4+\$5); print usage}'"
        );

        if (!$result['success'] || empty(trim($result['output']))) {
            // Fallback to top command
            $result = $this->sshService->execute(
                "top -bn1 | grep 'Cpu(s)' | awk '{print \$2+\$4}'"
            );
        }

        $usage = (float) trim($result['output'] ?? '0');

        return [
            'usage' => round($usage, 1),
        ];
    }

    private function getDiskMetrics(): array
    {
        $result = $this->sshService->execute("df -B1 / | tail -1 | awk '{print \$2,\$3,\$4}'");

        if (!$result['success'] || empty(trim($result['output']))) {
            return [
                'total' => 0,
                'used' => 0,
                'free' => 0,
                'percentage' => 0,
            ];
        }

        $parts = preg_split('/\s+/', trim($result['output']));
        $total = (int) ($parts[0] ?? 0);
        $used = (int) ($parts[1] ?? 0);
        $free = (int) ($parts[2] ?? 0);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function getUptimeMetrics(): array
    {
        $result = $this->sshService->execute("cat /proc/uptime | awk '{print \$1}'");

        if (!$result['success'] || empty(trim($result['output']))) {
            return [
                'seconds' => 0,
                'formatted' => 'Unknown',
            ];
        }

        $seconds = (int) floatval(trim($result['output']));

        return [
            'seconds' => $seconds,
            'formatted' => $this->formatUptime($seconds),
        ];
    }

    private function getLoadMetrics(): array
    {
        $result = $this->sshService->execute("cat /proc/loadavg | awk '{print \$1,\$2,\$3}'");

        if (!$result['success'] || empty(trim($result['output']))) {
            return [
                'avg_1' => 0,
                'avg_5' => 0,
                'avg_15' => 0,
            ];
        }

        $parts = preg_split('/\s+/', trim($result['output']));

        return [
            'avg_1' => (float) ($parts[0] ?? 0),
            'avg_5' => (float) ($parts[1] ?? 0),
            'avg_15' => (float) ($parts[2] ?? 0),
        ];
    }

    private function formatUptime(int $seconds): string
    {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];

        if ($days > 0) {
            $parts[] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        }

        if ($hours > 0) {
            $parts[] = $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
        }

        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes');
        }

        if (empty($parts)) {
            return 'Less than a minute';
        }

        return implode(', ', $parts);
    }
}
