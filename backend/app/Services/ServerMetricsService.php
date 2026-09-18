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
                'swap' => $this->getSwapMetrics(),
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

        if (! $result['success'] || empty(trim($result['output']))) {
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

    private function getSwapMetrics(): array
    {
        $result = $this->sshService->execute("free -b | awk 'NR==3 {print \$2,\$3}'");

        if (! $result['success']) {
            return ['total' => 0, 'used' => 0, 'percentage' => 0];
        }

        return $this->parseSwap($result['output']);
    }

    /**
     * Parse the "Swap:" line of free -b (total and used, in bytes). Servers
     * without swap report a zero total, which yields zeros throughout.
     */
    public function parseSwap(string $output): array
    {
        $parts = preg_split('/\s+/', trim($output));
        $total = (int) ($parts[0] ?? 0);
        $used = (int) ($parts[1] ?? 0);

        return [
            'total' => $total,
            'used' => $used,
            'percentage' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        ];
    }

    private function getCpuMetrics(): array
    {
        // /proc/stat counters are cumulative since boot, so a single read only
        // yields the lifetime average. Two samples one second apart give the
        // current utilisation. nproc rides along to avoid another round trip.
        $result = $this->sshService->execute('head -1 /proc/stat; sleep 1; head -1 /proc/stat; nproc', 30);

        $parsed = $result['success'] ? $this->parseCpuSamples($result['output']) : null;

        if ($parsed !== null) {
            return $parsed;
        }

        // Fallback for hosts where /proc/stat is unreadable.
        $result = $this->sshService->execute("top -bn1 | grep 'Cpu(s)' | awk '{print \$2+\$4}'");
        $usage = (float) trim($result['output'] ?? '0');

        $cores = $this->sshService->execute('nproc');

        return [
            'usage' => round($usage, 1),
            'cores' => max(1, (int) trim($cores['output'] ?? '1')),
        ];
    }

    /**
     * Parse two consecutive "cpu ..." lines from /proc/stat followed by the
     * nproc output. Returns null when the output cannot be parsed so the
     * caller can fall back to another probe.
     *
     * Field order after the "cpu" label: user nice system idle iowait irq
     * softirq steal guest guest_nice. Idle time is idle + iowait; everything
     * else counts as busy, so steal shows up as usage on oversold VMs.
     */
    public function parseCpuSamples(string $output): ?array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== ''));

        $cpuLines = array_values(array_filter($lines, fn ($l) => preg_match('/^cpu\s+\d/', $l) === 1));

        if (count($cpuLines) < 2) {
            return null;
        }

        $first = $this->cpuLineTotals($cpuLines[0]);
        $second = $this->cpuLineTotals($cpuLines[1]);

        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];

        if ($totalDelta <= 0) {
            $usage = 0.0;
        } else {
            $usage = (($totalDelta - $idleDelta) / $totalDelta) * 100;
        }

        $cores = 1;
        $last = end($lines);
        if ($last !== false && ctype_digit($last)) {
            $cores = max(1, (int) $last);
        }

        return [
            'usage' => round(max(0.0, min(100.0, $usage)), 1),
            'cores' => $cores,
        ];
    }

    /**
     * @return array{total: int, idle: int}
     */
    private function cpuLineTotals(string $line): array
    {
        $fields = preg_split('/\s+/', trim($line));
        array_shift($fields); // "cpu" label

        $values = array_map('intval', $fields);
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);

        return [
            'total' => array_sum($values),
            'idle' => $idle,
        ];
    }

    private function getDiskMetrics(): array
    {
        $result = $this->sshService->execute("df -B1 / | tail -1 | awk '{print \$2,\$3,\$4}'");

        if (! $result['success'] || empty(trim($result['output']))) {
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

        if (! $result['success'] || empty(trim($result['output']))) {
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

        if (! $result['success'] || empty(trim($result['output']))) {
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
            $parts[] = $days.' '.($days === 1 ? 'day' : 'days');
        }

        if ($hours > 0) {
            $parts[] = $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
        }

        if (empty($parts)) {
            return 'Less than a minute';
        }

        return implode(', ', $parts);
    }
}
