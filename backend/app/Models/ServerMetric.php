<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resource sample of a server. Not organization-scoped on its own: rows
 * are only ever reached through /servers/{server}/..., and the parent binding
 * already applies OrganizationScope.
 */
class ServerMetric extends Model
{
    use HasFactory;

    /**
     * Raw samples older than this are deleted by servers:collect-metrics.
     * At one sample every five minutes this is 8 640 rows per server.
     */
    public const RETENTION_DAYS = 30;

    /**
     * Live reads from the overview also store a sample, but not more often
     * than this, so parallel tabs do not multiply rows.
     */
    public const LIVE_SAMPLE_GUARD_SECONDS = 15;

    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'collected_at',
        'cpu_percent',
        'cpu_steal_percent',
        'cpu_cores',
        'memory_total',
        'memory_used',
        'memory_percent',
        'swap_total',
        'swap_used',
        'disk_total',
        'disk_used',
        'disk_percent',
        'load_1',
        'load_5',
        'load_15',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'cpu_percent' => 'float',
            'cpu_steal_percent' => 'float',
            'cpu_cores' => 'integer',
            'memory_total' => 'integer',
            'memory_used' => 'integer',
            'memory_percent' => 'float',
            'swap_total' => 'integer',
            'swap_used' => 'integer',
            'disk_total' => 'integer',
            'disk_used' => 'integer',
            'disk_percent' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Build an unsaved row from the array ServerMetricsService::getMetrics()
     * returns.
     */
    public static function fromSnapshot(Server $server, array $metrics): static
    {
        return new static([
            'server_id' => $server->id,
            'collected_at' => $metrics['collected_at'] ?? now(),
            'cpu_percent' => $metrics['cpu']['usage'] ?? 0,
            'cpu_steal_percent' => $metrics['cpu']['steal'] ?? 0,
            'cpu_cores' => $metrics['cpu']['cores'] ?? 1,
            'memory_total' => $metrics['memory']['total'] ?? 0,
            'memory_used' => $metrics['memory']['used'] ?? 0,
            'memory_percent' => $metrics['memory']['percentage'] ?? 0,
            'swap_total' => $metrics['swap']['total'] ?? 0,
            'swap_used' => $metrics['swap']['used'] ?? 0,
            'disk_total' => $metrics['disk']['total'] ?? 0,
            'disk_used' => $metrics['disk']['used'] ?? 0,
            'disk_percent' => $metrics['disk']['percentage'] ?? 0,
            'load_1' => $metrics['load']['avg_1'] ?? 0,
            'load_5' => $metrics['load']['avg_5'] ?? 0,
            'load_15' => $metrics['load']['avg_15'] ?? 0,
        ]);
    }
}
