import { useEffect, useState, useCallback } from 'react'
import { serversApi, ServerMetrics } from '@/services/api'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import { cn, formatBytes } from '@/lib/utils'
import { format } from 'date-fns'

export type MetricKey = 'cpu' | 'memory' | 'disk' | 'load'

interface ServerMetricsCardProps {
  serverId: number
  autoRefresh?: boolean
  refreshInterval?: number
  /** When provided, tiles act as a selector for the history chart below. */
  selected?: MetricKey
  onSelect?: (metric: MetricKey) => void
  /** `inline` renders a compact, unboxed row meant to sit inside a resource header. */
  variant?: 'card' | 'inline'
}

function formatUptime(seconds: number): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  if (days > 0) return `${days}d ${hours}h`
  const minutes = Math.floor((seconds % 3600) / 60)
  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`
}

interface InlineVitalProps {
  metric: MetricKey
  label: string
  value: string
  percentage: number
  detail: string
  selected?: boolean
  onSelect?: (metric: MetricKey) => void
}

/** Header-sized vital: label, number, hairline bar. The detail lives in the tooltip. */
function InlineVital({ metric, label, value, percentage, detail, selected, onSelect }: InlineVitalProps) {
  const interactive = Boolean(onSelect)
  const Tag = interactive ? 'button' : 'div'
  return (
    <Tag
      type={interactive ? 'button' : undefined}
      onClick={interactive ? () => onSelect?.(metric) : undefined}
      aria-pressed={interactive ? selected : undefined}
      title={`${label}: ${value} (${detail})`}
      className={cn(
        'pressable flex w-[5.5rem] flex-col gap-1 rounded-md px-2.5 py-1.5 text-left',
        interactive && 'hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        selected && 'bg-muted/60'
      )}
    >
      <span className="flex items-baseline justify-between gap-2">
        <span className="text-[11px] font-medium text-muted-foreground">{label}</span>
        <span className="text-sm font-semibold leading-none tabular-nums">{value}</span>
      </span>
      <span className="h-0.5 w-full overflow-hidden rounded-full bg-muted" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(Math.min(percentage, 100))}>
        <span
          className={cn('block h-full rounded-full transition-[width,background-color] duration-500 ease-out', barTone(percentage))}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </span>
    </Tag>
  )
}

function barTone(percentage: number): string {
  if (percentage < 60) return 'bg-emerald-500'
  if (percentage < 80) return 'bg-amber-500'
  return 'bg-red-500'
}

interface VitalProps {
  metric: MetricKey
  label: string
  value: string
  percentage: number
  detail: string
  selected?: boolean
  onSelect?: (metric: MetricKey) => void
}

/**
 * One vital sign: a big number you can read from across the room, a thin bar
 * for the shape, and the detail underneath. Becomes a button when the page
 * wires it to the history chart, so the tile you tap is the trend you see.
 */
function Vital({ metric, label, value, percentage, detail, selected, onSelect }: VitalProps) {
  const interactive = Boolean(onSelect)
  const Tag = interactive ? 'button' : 'div'
  return (
    <Tag
      type={interactive ? 'button' : undefined}
      onClick={interactive ? () => onSelect?.(metric) : undefined}
      aria-pressed={interactive ? selected : undefined}
      className={cn(
        'pressable relative flex min-w-0 flex-col gap-1.5 px-4 py-3 text-left',
        interactive && 'hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring',
        selected && 'bg-muted/40'
      )}
    >
      {selected && <span aria-hidden className="absolute inset-x-4 bottom-0 h-0.5 rounded-full bg-primary" />}
      <span className="flex items-baseline justify-between gap-3">
        <span className="text-xs font-medium text-muted-foreground">{label}</span>
        <span className="text-lg font-semibold leading-none tabular-nums tracking-tight">{value}</span>
      </span>
      <span className="h-1 w-full overflow-hidden rounded-full bg-muted" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(Math.min(percentage, 100))}>
        <span
          className={cn('block h-full rounded-full transition-[width,background-color] duration-500 ease-out', barTone(percentage))}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </span>
      <span className="truncate text-xs tabular-nums text-muted-foreground">{detail}</span>
    </Tag>
  )
}

function VitalsSkeleton() {
  return (
    <Card className="overflow-hidden p-0">
      <div className="grid grid-cols-2 divide-x divide-y sm:divide-y-0 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="space-y-2 px-4 py-3">
            <div className="flex justify-between"><Skeleton className="h-3 w-14" /><Skeleton className="h-4 w-12" /></div>
            <Skeleton className="h-1 w-full rounded-full" />
            <Skeleton className="h-3 w-28" />
          </div>
        ))}
      </div>
      <div className="flex items-center justify-between border-t px-4 py-1.5">
        <Skeleton className="h-3 w-40" />
        <Skeleton className="h-3 w-24" />
      </div>
    </Card>
  )
}

export function ServerMetricsCard({ serverId, autoRefresh = false, refreshInterval = 30000, selected, onSelect, variant = 'card' }: ServerMetricsCardProps) {
  const [metrics, setMetrics] = useState<ServerMetrics | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [refreshing, setRefreshing] = useState(false)
  const [updatedAt, setUpdatedAt] = useState<Date | null>(null)

  const fetchMetrics = useCallback(async (showRefreshing = false) => {
    if (showRefreshing) setRefreshing(true)
    try {
      const response = await serversApi.getMetrics(serverId)
      setMetrics(response.data)
      setUpdatedAt(new Date())
      setError(null)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      // Keep the last good sample on screen; the footer marks it stale.
      setError(e.response?.data?.message || 'Failed to load metrics')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [serverId])

  useEffect(() => {
    fetchMetrics()
  }, [fetchMetrics])

  useEffect(() => {
    if (!autoRefresh) return
    const interval = setInterval(() => fetchMetrics(), refreshInterval)
    return () => clearInterval(interval)
  }, [autoRefresh, refreshInterval, fetchMetrics])

  if (loading) {
    if (variant === 'inline') {
      return (
        <div className="flex items-center gap-1" role="status" aria-label="Loading metrics">
          {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10 w-[5.5rem] rounded-md" />)}
        </div>
      )
    }
    return <VitalsSkeleton />
  }

  if (error && !metrics && variant === 'inline') {
    return (
      <div className="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-400">
        <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
        <span>Metrics unavailable</span>
        <Button variant="ghost" size="icon" className="h-6 w-6" onClick={() => fetchMetrics(true)} disabled={refreshing} aria-label="Retry loading metrics">
          <ArrowPathIcon className={cn('h-3.5 w-3.5', refreshing && 'animate-spin')} />
        </Button>
      </div>
    )
  }

  if (error && !metrics) {
    return (
      <Card className="flex items-center justify-between gap-4 px-5 py-4">
        <div className="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
          <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
          <span>Metrics unavailable: {error}</span>
        </div>
        <Button variant="outline" size="sm" onClick={() => fetchMetrics(true)} disabled={refreshing}>
          <ArrowPathIcon className={cn('h-4 w-4', refreshing && 'animate-spin')} />
          Retry
        </Button>
      </Card>
    )
  }

  if (!metrics) return null

  const cores = metrics.cpu.cores
  const loadPercent = cores > 0 ? (metrics.load.avg_1 / cores) * 100 : 0

  if (variant === 'inline') {
    const freshness = updatedAt
      ? error ? `Stale, last sample ${format(updatedAt, 'HH:mm:ss')}` : `Updated ${format(updatedAt, 'HH:mm:ss')}`
      : undefined
    return (
      <div className={cn('flex flex-wrap items-center gap-x-1 gap-y-2 transition-opacity duration-200', error && 'opacity-70')}>
        <InlineVital metric="cpu" label="CPU" value={`${metrics.cpu.usage.toFixed(0)}%`} percentage={metrics.cpu.usage} detail={`${cores} ${cores === 1 ? 'core' : 'cores'}`} selected={selected === 'cpu'} onSelect={onSelect} />
        <InlineVital metric="memory" label="Memory" value={`${metrics.memory.percentage.toFixed(0)}%`} percentage={metrics.memory.percentage} detail={`${formatBytes(metrics.memory.used)} of ${formatBytes(metrics.memory.total)}`} selected={selected === 'memory'} onSelect={onSelect} />
        <InlineVital metric="disk" label="Disk" value={`${metrics.disk.percentage.toFixed(0)}%`} percentage={metrics.disk.percentage} detail={`${formatBytes(metrics.disk.used)} of ${formatBytes(metrics.disk.total)}`} selected={selected === 'disk'} onSelect={onSelect} />
        <InlineVital metric="load" label="Load" value={metrics.load.avg_1.toFixed(2)} percentage={loadPercent} detail={`5m ${metrics.load.avg_5.toFixed(2)} · 15m ${metrics.load.avg_15.toFixed(2)}`} selected={selected === 'load'} onSelect={onSelect} />
        <div className="ml-1 flex flex-col gap-1 px-2.5 py-1.5" title={metrics.uptime.formatted}>
          <span className="text-[11px] font-medium text-muted-foreground">Uptime</span>
          <span className="text-sm font-semibold leading-none tabular-nums">{formatUptime(metrics.uptime.seconds)}</span>
        </div>
        <Button
          variant="ghost"
          size="icon"
          className={cn('h-7 w-7', error && 'text-amber-700 dark:text-amber-400')}
          onClick={() => fetchMetrics(true)}
          disabled={refreshing}
          aria-label={freshness ? `Refresh metrics. ${freshness}` : 'Refresh metrics'}
          title={freshness}
        >
          {error ? <ExclamationTriangleIcon className="h-3.5 w-3.5" /> : <ArrowPathIcon className={cn('h-3.5 w-3.5', refreshing && 'animate-spin')} />}
        </Button>
      </div>
    )
  }

  return (
    <Card className="overflow-hidden p-0">
      <div className={cn('grid grid-cols-2 divide-x divide-y transition-opacity duration-200 sm:divide-y-0 lg:grid-cols-4', error && 'opacity-70')}>
        <Vital
          metric="cpu"
          label="CPU"
          value={`${metrics.cpu.usage.toFixed(0)}%`}
          percentage={metrics.cpu.usage}
          detail={`${cores} ${cores === 1 ? 'core' : 'cores'}`}
          selected={selected === 'cpu'}
          onSelect={onSelect}
        />
        <Vital
          metric="memory"
          label="Memory"
          value={`${metrics.memory.percentage.toFixed(0)}%`}
          percentage={metrics.memory.percentage}
          detail={`${formatBytes(metrics.memory.used)} of ${formatBytes(metrics.memory.total)}`}
          selected={selected === 'memory'}
          onSelect={onSelect}
        />
        <Vital
          metric="disk"
          label="Disk"
          value={`${metrics.disk.percentage.toFixed(0)}%`}
          percentage={metrics.disk.percentage}
          detail={`${formatBytes(metrics.disk.used)} of ${formatBytes(metrics.disk.total)}`}
          selected={selected === 'disk'}
          onSelect={onSelect}
        />
        <Vital
          metric="load"
          label="Load"
          value={metrics.load.avg_1.toFixed(2)}
          percentage={loadPercent}
          detail={`5m ${metrics.load.avg_5.toFixed(2)} · 15m ${metrics.load.avg_15.toFixed(2)}`}
          selected={selected === 'load'}
          onSelect={onSelect}
        />
      </div>
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t px-4 py-1 text-xs text-muted-foreground">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 tabular-nums">
          <span>Up {metrics.uptime.formatted}</span>
          {metrics.swap.total > 0 && (
            <span>Swap {metrics.swap.percentage.toFixed(0)}% ({formatBytes(metrics.swap.used)} of {formatBytes(metrics.swap.total)})</span>
          )}
        </div>
        <div className="flex items-center gap-2 tabular-nums">
          {error && updatedAt ? (
            <span className="flex items-center gap-1 text-amber-700 dark:text-amber-400" role="status">
              <ExclamationTriangleIcon className="h-3.5 w-3.5" />
              Stale, last sample {format(updatedAt, 'HH:mm:ss')}
            </span>
          ) : updatedAt ? (
            <span>Updated {format(updatedAt, 'HH:mm:ss')}</span>
          ) : null}
          <Button variant="ghost" size="icon" className="h-6 w-6" onClick={() => fetchMetrics(true)} disabled={refreshing} aria-label="Refresh metrics">
            <ArrowPathIcon className={cn('h-3.5 w-3.5', refreshing && 'animate-spin')} />
          </Button>
        </div>
      </div>
    </Card>
  )
}
