<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMetric;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Aggregates stored ServerMetric samples into the series and summaries the
 * server overview chart needs. Bucketing uses UNIX_TIMESTAMP(), which is
 * MySQL specific; MySQL is the only database ShipYard supports.
 */
class ServerMetricsHistoryService
{
    public const RANGES = ['7d', '30d'];

    /**
     * Bucket sizes keep every range at a few hundred points: 7d / 30 min is
     * 336 points, 30d / 2 h is 360.
     */
    private const RANGE_CONFIG = [
        '7d' => ['days' => 7, 'bucket_minutes' => 30],
        '30d' => ['days' => 30, 'bucket_minutes' => 120],
    ];

    private const SUMMARY_METRICS = [
        'cpu' => ['column' => 'cpu_percent', 'precision' => 1],
        'cpu_steal' => ['column' => 'cpu_steal_percent', 'precision' => 1],
        'memory' => ['column' => 'memory_percent', 'precision' => 1],
        'disk' => ['column' => 'disk_percent', 'precision' => 1],
        'load_1' => ['column' => 'load_1', 'precision' => 2],
    ];

    public function history(Server $server, string $range): array
    {
        if (! isset(self::RANGE_CONFIG[$range])) {
            throw new InvalidArgumentException("Unknown metrics range: {$range}");
        }

        $config = self::RANGE_CONFIG[$range];
        $since = now()->subDays($config['days']);

        $samples = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $since)
            ->orderBy('collected_at')
            ->get(['collected_at', 'cpu_percent', 'cpu_steal_percent', 'cpu_cores', 'memory_percent', 'disk_percent', 'disk_total', 'disk_used', 'load_1']);

        return [
            'range' => $range,
            'bucket_minutes' => $config['bucket_minutes'],
            'cores' => $samples->last()?->cpu_cores,
            'disk_total' => $samples->last()?->disk_total,
            'disk_used' => $samples->last()?->disk_used,
            'samples' => $samples->count(),
            'series' => $this->series($server, $since, $config['bucket_minutes'] * 60),
            'summary' => $this->summary($samples),
        ];
    }

    private function series(Server $server, Carbon $since, int $bucketSeconds): array
    {
        $rows = ServerMetric::query()
            ->where('server_id', $server->id)
            ->where('collected_at', '>=', $since)
            ->selectRaw('FLOOR(UNIX_TIMESTAMP(collected_at) / ?) * ? AS bucket', [$bucketSeconds, $bucketSeconds])
            ->selectRaw('MAX(cpu_percent) AS cpu')
            ->selectRaw('AVG(cpu_percent) AS cpu_avg')
            ->selectRaw('MAX(cpu_steal_percent) AS cpu_steal')
            ->selectRaw('AVG(cpu_steal_percent) AS cpu_steal_avg')
            ->selectRaw('MAX(memory_percent) AS memory')
            ->selectRaw('AVG(memory_percent) AS memory_avg')
            ->selectRaw('MAX(disk_percent) AS disk')
            ->selectRaw('MAX(load_1) AS load_1')
            ->selectRaw('MAX(swap_used) AS swap_used')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(fn ($row) => [
            't' => Carbon::createFromTimestampUTC((int) $row->bucket)->toIso8601String(),
            'cpu' => round((float) $row->cpu, 1),
            'cpu_avg' => round((float) $row->cpu_avg, 1),
            'cpu_steal' => round((float) $row->cpu_steal, 1),
            'cpu_steal_avg' => round((float) $row->cpu_steal_avg, 1),
            'memory' => round((float) $row->memory, 1),
            'memory_avg' => round((float) $row->memory_avg, 1),
            'disk' => round((float) $row->disk, 1),
            'load_1' => round((float) $row->load_1, 2),
            'swap_used' => (int) $row->swap_used,
        ])->all();
    }

    /**
     * @param  Collection<int, ServerMetric>  $samples  ordered by collected_at
     */
    private function summary($samples): array
    {
        $summary = [];

        foreach (self::SUMMARY_METRICS as $key => $metric) {
            $values = $samples->pluck($metric['column'])->map(fn ($v) => (float) $v)->all();
            $summary[$key] = $this->describe($values, $metric['precision']);
        }

        $summary['disk_growth_bytes_per_day'] = $this->diskGrowthPerDay($samples);

        return $summary;
    }

    /**
     * @param  float[]  $values
     * @return array{peak: float|null, p95: float|null, avg: float|null}
     */
    private function describe(array $values, int $precision): array
    {
        if ($values === []) {
            return ['peak' => null, 'p95' => null, 'avg' => null];
        }

        sort($values);
        $count = count($values);

        // Nearest-rank percentile: the smallest value at or above which 95%
        // of the samples fall.
        $rank = max(0, (int) ceil(0.95 * $count) - 1);

        return [
            'peak' => round($values[$count - 1], $precision),
            'p95' => round($values[$rank], $precision),
            'avg' => round(array_sum($values) / $count, $precision),
        ];
    }

    /**
     * Linear disk growth between the first and last sample in the range, or
     * null when there is not enough data to say.
     */
    private function diskGrowthPerDay($samples): ?int
    {
        if ($samples->count() < 2) {
            return null;
        }

        $first = $samples->first();
        $last = $samples->last();

        $seconds = $first->collected_at->diffInSeconds($last->collected_at);

        if ($seconds <= 0) {
            return null;
        }

        return (int) round(($last->disk_used - $first->disk_used) / $seconds * 86400);
    }
}
