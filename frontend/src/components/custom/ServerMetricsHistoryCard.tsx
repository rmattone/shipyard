import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { format } from 'date-fns'
import {
  serversApi,
  MetricsHistoryRange,
  MetricsSummary,
  ServerMetricsHistory,
  ServerMetricsHistoryPoint,
} from '@/services/api'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'
import { cn, formatBytes } from '@/lib/utils'
import type { MetricKey } from './ServerMetricsCard'

interface ServerMetricsHistoryCardProps {
  serverId: number
  /** Controlled selection; falls back to internal state when omitted. */
  metric?: MetricKey
  onMetricChange?: (metric: MetricKey) => void
}

const METRIC_LABELS: Record<MetricKey, string> = {
  memory: 'Memory',
  cpu: 'CPU',
  disk: 'Disk',
  load: 'Load',
}
const METRIC_ORDER: MetricKey[] = ['cpu', 'memory', 'disk', 'load']

const RANGE_LABELS: Record<MetricsHistoryRange, string> = {
  '7d': '7 days',
  '30d': '30 days',
}

// Above this the server is treated as saturated for memory, CPU and disk.
const SATURATION_PERCENT = 80

function formatPercent(value: number): string {
  return `${value.toFixed(1)}%`
}

function formatLoad(value: number): string {
  return value.toFixed(2)
}

function formatSummaryValue(value: number | null, formatter: (v: number) => string): string {
  return value === null ? '–' : formatter(value)
}

// Width of the element the chart lives in, so the SVG can use real pixels
// and keep 2px strokes and legible text at any container size.
function useElementWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null)
  const [width, setWidth] = useState(0)

  useEffect(() => {
    const el = ref.current
    if (!el) return

    setWidth(el.getBoundingClientRect().width)

    const observer = new ResizeObserver((entries) => {
      const entry = entries[0]
      if (entry) setWidth(entry.contentRect.width)
    })
    observer.observe(el)

    return () => observer.disconnect()
  }, [])

  return [ref, width] as const
}

interface ChartPoint {
  t: Date
  value: number
  secondary?: number
}

interface MetricLineChartProps {
  points: ChartPoint[]
  yMax: number
  yTicks: number[]
  formatValue: (v: number) => string
  formatTick: (d: Date) => string
  primaryLabel: string
  secondaryLabel?: string
  reference?: { value: number; label: string }
  title: string
}

const CHART_HEIGHT = 200
const PAD = { top: 10, right: 12, bottom: 22, left: 40 }

function MetricLineChart({
  points,
  yMax,
  yTicks,
  formatValue,
  formatTick,
  primaryLabel,
  secondaryLabel,
  reference,
  title,
}: MetricLineChartProps) {
  const [containerRef, width] = useElementWidth<HTMLDivElement>()
  const [hoverIndex, setHoverIndex] = useState<number | null>(null)

  const plotWidth = Math.max(0, width - PAD.left - PAD.right)
  const plotHeight = CHART_HEIGHT - PAD.top - PAD.bottom

  const domain = useMemo(() => {
    if (points.length === 0) return null
    const start = points[0].t.getTime()
    const end = points[points.length - 1].t.getTime()
    return { start, end: end > start ? end : start + 1 }
  }, [points])

  const xFor = useCallback(
    (t: Date) => {
      if (!domain) return PAD.left
      return PAD.left + ((t.getTime() - domain.start) / (domain.end - domain.start)) * plotWidth
    },
    [domain, plotWidth]
  )

  const yFor = useCallback(
    (v: number) => PAD.top + plotHeight - (Math.min(v, yMax) / yMax) * plotHeight,
    [plotHeight, yMax]
  )

  const primaryPath = useMemo(
    () => points.map((p, i) => `${i === 0 ? 'M' : 'L'}${xFor(p.t).toFixed(1)} ${yFor(p.value).toFixed(1)}`).join(' '),
    [points, xFor, yFor]
  )

  const secondaryPath = useMemo(() => {
    if (!points.some((p) => p.secondary !== undefined)) return null
    return points
      .map((p, i) => `${i === 0 ? 'M' : 'L'}${xFor(p.t).toFixed(1)} ${yFor(p.secondary ?? p.value).toFixed(1)}`)
      .join(' ')
  }, [points, xFor, yFor])

  const xTicks = useMemo(() => {
    if (!domain || plotWidth === 0) return []
    const count = plotWidth < 420 ? 3 : plotWidth < 720 ? 5 : 7
    return Array.from({ length: count }, (_, i) => {
      const t = domain.start + ((domain.end - domain.start) * i) / (count - 1)
      return { t: new Date(t), position: i === 0 ? 'start' : i === count - 1 ? 'end' : 'middle' }
    })
  }, [domain, plotWidth])

  const handlePointerMove = (event: React.PointerEvent<SVGSVGElement>) => {
    if (points.length === 0) return
    const rect = event.currentTarget.getBoundingClientRect()
    const x = event.clientX - rect.left

    let nearest = 0
    let nearestDistance = Infinity
    points.forEach((p, i) => {
      const distance = Math.abs(xFor(p.t) - x)
      if (distance < nearestDistance) {
        nearestDistance = distance
        nearest = i
      }
    })
    setHoverIndex(nearest)
  }

  const hovered = hoverIndex !== null ? points[hoverIndex] : null
  const hoverX = hovered ? xFor(hovered.t) : 0
  const tooltipLeft = hovered ? Math.min(Math.max(hoverX, PAD.left + 70), Math.max(PAD.left + 70, width - 82)) : 0

  return (
    <div ref={containerRef} className="relative w-full select-none">
      {width > 0 && (
        <svg
          role="img"
          aria-label={title}
          width={width}
          height={CHART_HEIGHT}
          className="block overflow-visible"
          onPointerMove={handlePointerMove}
          onPointerLeave={() => setHoverIndex(null)}
        >
          {/* Grid and y axis labels */}
          {yTicks.map((tick) => (
            <g key={tick}>
              <line
                x1={PAD.left}
                x2={PAD.left + plotWidth}
                y1={yFor(tick)}
                y2={yFor(tick)}
                className="stroke-border"
                strokeWidth={1}
              />
              <text
                x={PAD.left - 8}
                y={yFor(tick)}
                dy="0.32em"
                textAnchor="end"
                className="fill-muted-foreground text-[10px]"
              >
                {formatValue(tick)}
              </text>
            </g>
          ))}

          {/* x axis labels */}
          {xTicks.map((tick, i) => (
            <text
              key={i}
              x={xFor(tick.t)}
              y={CHART_HEIGHT - 6}
              textAnchor={tick.position as 'start' | 'middle' | 'end'}
              className="fill-muted-foreground text-[10px]"
            >
              {formatTick(tick.t)}
            </text>
          ))}

          {/* Reference line (saturation threshold or core count) */}
          {reference && reference.value <= yMax && (
            <g>
              <line
                x1={PAD.left}
                x2={PAD.left + plotWidth}
                y1={yFor(reference.value)}
                y2={yFor(reference.value)}
                className="stroke-muted-foreground"
                strokeWidth={1}
                strokeDasharray="3 4"
                opacity={0.7}
              />
              <text
                x={PAD.left + plotWidth}
                y={yFor(reference.value) - 4}
                textAnchor="end"
                className="fill-muted-foreground text-[10px]"
              >
                {reference.label}
              </text>
            </g>
          )}

          {/* Series */}
          {secondaryPath && (
            <path
              d={secondaryPath}
              fill="none"
              className="stroke-blue-600 dark:stroke-blue-400"
              strokeWidth={1.5}
              strokeLinejoin="round"
              strokeLinecap="round"
              opacity={0.45}
            />
          )}
          {points.length === 1 ? (
            <circle cx={xFor(points[0].t)} cy={yFor(points[0].value)} r={4} className="fill-blue-600 dark:fill-blue-400" />
          ) : (
            <path
              d={primaryPath}
              fill="none"
              className="stroke-blue-600 dark:stroke-blue-400"
              strokeWidth={2}
              strokeLinejoin="round"
              strokeLinecap="round"
            />
          )}

          {/* Hover crosshair */}
          {hovered && (
            <g>
              <line
                x1={hoverX}
                x2={hoverX}
                y1={PAD.top}
                y2={PAD.top + plotHeight}
                className="stroke-muted-foreground"
                strokeWidth={1}
                opacity={0.6}
              />
              {hovered.secondary !== undefined && (
                <circle
                  cx={hoverX}
                  cy={yFor(hovered.secondary)}
                  r={4}
                  className="fill-blue-600 dark:fill-blue-400 stroke-background"
                  strokeWidth={2}
                  opacity={0.6}
                />
              )}
              <circle
                cx={hoverX}
                cy={yFor(hovered.value)}
                r={5}
                className="fill-blue-600 dark:fill-blue-400 stroke-background"
                strokeWidth={2}
              />
            </g>
          )}
        </svg>
      )}

      {hovered && (
        <div
          className="pointer-events-none absolute top-0 -translate-x-1/2 rounded-md border bg-background px-2.5 py-1.5 text-xs shadow-md"
          style={{ left: tooltipLeft }}
        >
          <div className="text-muted-foreground">{format(hovered.t, 'MMM d, HH:mm')}</div>
          <div className="mt-0.5 flex items-center gap-1.5">
            <span className="inline-block h-2 w-2 rounded-full bg-blue-600 dark:bg-blue-400" />
            <span className="text-muted-foreground">{primaryLabel}</span>
            <span className="font-medium tabular-nums">{formatValue(hovered.value)}</span>
          </div>
          {hovered.secondary !== undefined && secondaryLabel && (
            <div className="mt-0.5 flex items-center gap-1.5">
              <span className="inline-block h-2 w-2 rounded-full bg-blue-600 opacity-45 dark:bg-blue-400" />
              <span className="text-muted-foreground">{secondaryLabel}</span>
              <span className="font-medium tabular-nums">{formatValue(hovered.secondary)}</span>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

interface SummaryItem {
  label: string
  value: string
  emphasis?: 'warning' | 'danger'
}

function SummaryChips({ items }: { items: SummaryItem[] }) {
  return (
    <dl className="flex flex-wrap gap-x-5 gap-y-1 text-sm">
      {items.map((item) => (
        <div key={item.label} className="flex items-baseline gap-1.5">
          <dt className="text-muted-foreground">{item.label}</dt>
          <dd
            className={cn(
              'font-medium tabular-nums',
              item.emphasis === 'warning' && 'text-yellow-600 dark:text-yellow-500',
              item.emphasis === 'danger' && 'text-red-600 dark:text-red-500'
            )}
          >
            {item.value}
          </dd>
        </div>
      ))}
    </dl>
  )
}

function emphasisFor(value: number | null): SummaryItem['emphasis'] {
  if (value === null) return undefined
  if (value >= 90) return 'danger'
  if (value >= SATURATION_PERCENT) return 'warning'
  return undefined
}

function percentSummaryItems(summary: MetricsSummary): SummaryItem[] {
  return [
    { label: 'Peak', value: formatSummaryValue(summary.peak, formatPercent), emphasis: emphasisFor(summary.peak) },
    { label: 'p95', value: formatSummaryValue(summary.p95, formatPercent), emphasis: emphasisFor(summary.p95) },
    { label: 'Avg', value: formatSummaryValue(summary.avg, formatPercent) },
  ]
}

function MetricPanel({
  title,
  description,
  items,
  children,
}: {
  title: string
  description: string
  items: SummaryItem[]
  children: React.ReactNode
}) {
  return (
    <section key={title} className="animate-in fade-in-0 duration-200">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
        <div>
          <h4 className="font-medium">{title}</h4>
          <p className="text-xs text-muted-foreground">{description}</p>
        </div>
        <SummaryChips items={items} />
      </div>
      {children}
    </section>
  )
}

function HistorySkeleton() {
  return (
    <div className="space-y-4">
      <div className="flex justify-between">
        <Skeleton className="h-5 w-24" />
        <Skeleton className="h-4 w-48" />
      </div>
      <Skeleton className="h-[200px] w-full" />
    </div>
  )
}

function toChartPoints(
  series: ServerMetricsHistoryPoint[],
  key: 'cpu' | 'memory' | 'disk' | 'load_1',
  secondaryKey?: 'cpu_avg' | 'memory_avg'
): ChartPoint[] {
  return series.map((p) => ({
    t: new Date(p.t),
    value: p[key],
    secondary: secondaryKey ? p[secondaryKey] : undefined,
  }))
}

export function ServerMetricsHistoryCard({ serverId, metric: controlledMetric, onMetricChange }: ServerMetricsHistoryCardProps) {
  const [range, setRange] = useState<MetricsHistoryRange>('7d')
  const [internalMetric, setInternalMetric] = useState<MetricKey>('cpu')
  const metric = controlledMetric ?? internalMetric
  const selectMetric = (next: MetricKey) => {
    setInternalMetric(next)
    onMetricChange?.(next)
  }
  const [history, setHistory] = useState<ServerMetricsHistory | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const fetchHistory = useCallback(async () => {
    setRefreshing(true)
    try {
      const response = await serversApi.getMetricsHistory(serverId, range)
      setHistory(response.data)
      setError(null)
    } catch (err: unknown) {
      const e = err as { response?: { data?: { message?: string } } }
      setError(e.response?.data?.message || 'Failed to load metrics history')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [serverId, range])

  useEffect(() => {
    fetchHistory()
  }, [fetchHistory])

  const formatTick = useCallback(
    (d: Date) => (range === '7d' ? format(d, 'EEE d') : format(d, 'MMM d')),
    [range]
  )

  const header = (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <h3 className="font-semibold">Resource history</h3>
        <Tabs value={metric} onValueChange={(value) => selectMetric(value as MetricKey)}>
          <TabsList aria-label="Metric" className="h-8">
            {METRIC_ORDER.map((key) => (
              <TabsTrigger key={key} value={key} className="h-6 px-2.5 text-xs">{METRIC_LABELS[key]}</TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      </div>
      <div className="flex items-center gap-2">
        <span className="hidden text-xs tabular-nums text-muted-foreground md:inline">
          {history && history.samples > 0
            ? `${history.samples} samples, one point per ${
                history.bucket_minutes >= 60 ? `${history.bucket_minutes / 60} h` : `${history.bucket_minutes} min`
              }`
            : 'Sampled every 5 minutes'}
        </span>
        <Tabs value={range} onValueChange={(value) => setRange(value as MetricsHistoryRange)}>
          <TabsList aria-label="Range" className="h-8">
            <TabsTrigger value="7d" className="h-6 px-2.5 text-xs">{RANGE_LABELS['7d']}</TabsTrigger>
            <TabsTrigger value="30d" className="h-6 px-2.5 text-xs">{RANGE_LABELS['30d']}</TabsTrigger>
          </TabsList>
        </Tabs>
        <Button variant="ghost" size="icon" onClick={fetchHistory} disabled={refreshing} aria-label="Refresh history">
          <ArrowPathIcon className={cn('h-4 w-4', refreshing && 'animate-spin')} />
        </Button>
      </div>
    </div>
  )

  if (loading) {
    return (
      <Card className="p-6">
        {header}
        <HistorySkeleton />
      </Card>
    )
  }

  if (error) {
    return (
      <Card className="p-6">
        {header}
        <div className="flex items-center gap-2 text-sm text-yellow-600 dark:text-yellow-500">
          <ExclamationTriangleIcon className="h-4 w-4" />
          <span>{error}</span>
        </div>
      </Card>
    )
  }

  if (!history) {
    return null
  }

  if (history.samples === 0) {
    return (
      <Card className="p-6">
        {header}
        <div className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
          No history yet. Samples are collected every 5 minutes while the server is active, so the first points appear
          shortly.
        </div>
      </Card>
    )
  }

  const { summary, series, cores } = history
  const lastPoint = series[series.length - 1]

  const diskItems: SummaryItem[] = [
    { label: 'Now', value: formatPercent(lastPoint.disk), emphasis: emphasisFor(lastPoint.disk) },
    { label: 'Peak', value: formatSummaryValue(summary.disk.peak, formatPercent), emphasis: emphasisFor(summary.disk.peak) },
  ]
  if (summary.disk_growth_bytes_per_day !== null) {
    const growth = summary.disk_growth_bytes_per_day
    diskItems.push({
      label: 'Growth',
      value: `${growth < 0 ? '-' : '+'}${formatBytes(Math.abs(growth))}/day`,
    })
    if (growth > 0 && history.disk_total !== null && history.disk_used !== null) {
      const daysToFull = Math.floor((history.disk_total - history.disk_used) / growth)
      diskItems.push({
        label: 'Full in',
        value: daysToFull > 365 ? '> 1 year' : `~${daysToFull} days`,
        emphasis: daysToFull <= 30 ? 'danger' : daysToFull <= 90 ? 'warning' : undefined,
      })
    }
  }

  const loadYMax = Math.max(1, cores ? cores * 2 : 0, (summary.load_1.peak ?? 0) * 1.15)
  const loadTicks = [0, loadYMax / 2, loadYMax]
  const loadItems: SummaryItem[] = [
    {
      label: 'Peak',
      value: formatSummaryValue(summary.load_1.peak, formatLoad),
      emphasis: cores && summary.load_1.peak !== null && summary.load_1.peak > cores ? 'warning' : undefined,
    },
    {
      label: 'p95',
      value: formatSummaryValue(summary.load_1.p95, formatLoad),
      emphasis: cores && summary.load_1.p95 !== null && summary.load_1.p95 > cores ? 'danger' : undefined,
    },
    { label: 'Avg', value: formatSummaryValue(summary.load_1.avg, formatLoad) },
  ]

  const percentTicks = [0, 25, 50, 75, 100]
  const saturation = { value: SATURATION_PERCENT, label: `${SATURATION_PERCENT}%` }

  const panels: Record<MetricKey, React.ReactNode> = {
    memory: (
      <MetricPanel
        title="Memory"
        description="Peak per interval, with the average as the lighter line. Sustained p95 above 80% means there is no room for another application."
        items={percentSummaryItems(summary.memory)}
      >
        <MetricLineChart
          title="Memory usage history"
          points={toChartPoints(series, 'memory', 'memory_avg')}
          yMax={100}
          yTicks={percentTicks}
          formatValue={formatPercent}
          formatTick={formatTick}
          primaryLabel="Peak"
          secondaryLabel="Avg"
          reference={saturation}
        />
      </MetricPanel>
    ),
    cpu: (
      <MetricPanel
        title="CPU"
        description={`Utilisation across ${cores ?? '?'} ${cores === 1 ? 'core' : 'cores'}. Short spikes are normal; a high p95 is not.`}
        items={percentSummaryItems(summary.cpu)}
      >
        <MetricLineChart
          title="CPU usage history"
          points={toChartPoints(series, 'cpu', 'cpu_avg')}
          yMax={100}
          yTicks={percentTicks}
          formatValue={formatPercent}
          formatTick={formatTick}
          primaryLabel="Peak"
          secondaryLabel="Avg"
          reference={saturation}
        />
      </MetricPanel>
    ),
    disk: (
      <MetricPanel
        title="Disk"
        description="Root filesystem. Growth is extrapolated from the first and last sample in the range."
        items={diskItems}
      >
        <MetricLineChart
          title="Disk usage history"
          points={toChartPoints(series, 'disk')}
          yMax={100}
          yTicks={percentTicks}
          formatValue={formatPercent}
          formatTick={formatTick}
          primaryLabel="Used"
          reference={saturation}
        />
      </MetricPanel>
    ),
    load: (
      <MetricPanel
        title="Load average (1 min)"
        description="Runnable processes. Staying above the core count means the CPU is the bottleneck."
        items={loadItems}
      >
        <MetricLineChart
          title="Load average history"
          points={toChartPoints(series, 'load_1')}
          yMax={loadYMax}
          yTicks={loadTicks}
          formatValue={formatLoad}
          formatTick={formatTick}
          primaryLabel="Peak"
          reference={cores ? { value: cores, label: `${cores} ${cores === 1 ? 'core' : 'cores'}` } : undefined}
        />
      </MetricPanel>
    ),
  }

  return (
    <Card className="p-6">
      {header}
      <div className={cn('transition-opacity duration-200', refreshing && 'opacity-60')}>
        {panels[metric]}
      </div>
    </Card>
  )
}
