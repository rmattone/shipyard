import { useEffect, useState, useCallback } from 'react'
import { serversApi, ServerMetrics } from '@/services/api'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import { cn } from '@/lib/utils'

interface ServerMetricsCardProps {
  serverId: number
  autoRefresh?: boolean
  refreshInterval?: number
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i]
}

function getProgressColor(percentage: number): string {
  if (percentage < 60) return 'bg-emerald-500'
  if (percentage < 80) return 'bg-yellow-500'
  return 'bg-red-500'
}

function ProgressBar({ percentage, label, detail }: { percentage: number; label: string; detail: string }) {
  return (
    <div className="space-y-1">
      <div className="flex justify-between text-sm">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium">{percentage.toFixed(1)}%</span>
      </div>
      <div className="h-2 bg-muted rounded-full overflow-hidden">
        <div
          className={cn('h-full rounded-full transition-all', getProgressColor(percentage))}
          style={{ width: `${Math.min(percentage, 100)}%` }}
        />
      </div>
      <div className="text-xs text-muted-foreground">{detail}</div>
    </div>
  )
}

function MetricsSkeleton() {
  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <Skeleton className="h-5 w-24" />
        <Skeleton className="h-8 w-8 rounded" />
      </div>
      <div className="space-y-4">
        {[1, 2, 3].map((i) => (
          <div key={i} className="space-y-2">
            <div className="flex justify-between">
              <Skeleton className="h-4 w-16" />
              <Skeleton className="h-4 w-12" />
            </div>
            <Skeleton className="h-2 w-full rounded-full" />
            <Skeleton className="h-3 w-32" />
          </div>
        ))}
        <div className="pt-2 space-y-2">
          <Skeleton className="h-4 w-20" />
          <Skeleton className="h-4 w-40" />
        </div>
        <div className="space-y-2">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-4 w-32" />
        </div>
      </div>
    </Card>
  )
}

export function ServerMetricsCard({ serverId, autoRefresh = false, refreshInterval = 30000 }: ServerMetricsCardProps) {
  const [metrics, setMetrics] = useState<ServerMetrics | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [refreshing, setRefreshing] = useState(false)

  const fetchMetrics = useCallback(async (showRefreshing = false) => {
    if (showRefreshing) {
      setRefreshing(true)
    }

    try {
      const response = await serversApi.getMetrics(serverId)
      setMetrics(response.data)
      setError(null)
    } catch (err: unknown) {
      const error = err as { response?: { data?: { message?: string } } }
      setError(error.response?.data?.message || 'Failed to load metrics')
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

    const interval = setInterval(() => {
      fetchMetrics()
    }, refreshInterval)

    return () => clearInterval(interval)
  }, [autoRefresh, refreshInterval, fetchMetrics])

  const handleRefresh = () => {
    fetchMetrics(true)
  }

  if (loading) {
    return <MetricsSkeleton />
  }

  if (error) {
    return (
      <Card className="p-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="font-semibold">Server Metrics</h3>
          <Button variant="ghost" size="icon" onClick={handleRefresh} disabled={refreshing}>
            <ArrowPathIcon className={cn('h-4 w-4', refreshing && 'animate-spin')} />
          </Button>
        </div>
        <div className="flex items-center gap-2 text-sm text-yellow-600 dark:text-yellow-500">
          <ExclamationTriangleIcon className="h-4 w-4" />
          <span>{error}</span>
        </div>
      </Card>
    )
  }

  if (!metrics) {
    return null
  }

  return (
    <Card className="p-6">
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-semibold">Server Metrics</h3>
        <Button variant="ghost" size="icon" onClick={handleRefresh} disabled={refreshing}>
          <ArrowPathIcon className={cn('h-4 w-4', refreshing && 'animate-spin')} />
        </Button>
      </div>

      <div className="space-y-4">
        <ProgressBar
          percentage={metrics.memory.percentage}
          label="Memory"
          detail={`${formatBytes(metrics.memory.used)} / ${formatBytes(metrics.memory.total)}`}
        />

        <ProgressBar
          percentage={metrics.cpu.usage}
          label="CPU"
          detail={`${metrics.cpu.usage.toFixed(1)}% usage`}
        />

        <ProgressBar
          percentage={metrics.disk.percentage}
          label="Disk"
          detail={`${formatBytes(metrics.disk.used)} / ${formatBytes(metrics.disk.total)}`}
        />

        <div className="pt-2 border-t">
          <div className="flex justify-between text-sm">
            <span className="text-muted-foreground">Uptime</span>
            <span className="font-medium">{metrics.uptime.formatted}</span>
          </div>
        </div>

        <div>
          <div className="text-sm text-muted-foreground mb-1">Load Average</div>
          <div className="flex gap-4 text-sm">
            <div>
              <span className="text-muted-foreground">1m:</span>{' '}
              <span className="font-medium">{metrics.load.avg_1.toFixed(2)}</span>
            </div>
            <div>
              <span className="text-muted-foreground">5m:</span>{' '}
              <span className="font-medium">{metrics.load.avg_5.toFixed(2)}</span>
            </div>
            <div>
              <span className="text-muted-foreground">15m:</span>{' '}
              <span className="font-medium">{metrics.load.avg_15.toFixed(2)}</span>
            </div>
          </div>
        </div>
      </div>
    </Card>
  )
}
