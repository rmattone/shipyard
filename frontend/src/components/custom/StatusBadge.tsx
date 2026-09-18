import { cn } from "@/lib/utils"

type StatusType = 'active' | 'inactive' | 'deploying' | 'running' | 'pending' | 'failed' | 'success' | string

interface StatusBadgeProps {
  status: StatusType
  /** Override the visible text; the raw status is still exposed to assistive tech. */
  label?: string
  className?: string
}

type Tone = 'positive' | 'neutral' | 'progress' | 'negative'

const tones: Record<Tone, string> = {
  positive: 'bg-emerald-500/10 text-emerald-700 ring-emerald-600/20 dark:text-emerald-400 dark:ring-emerald-400/25',
  neutral: 'bg-muted text-muted-foreground ring-border',
  progress: 'bg-amber-500/10 text-amber-700 ring-amber-600/25 dark:text-amber-400 dark:ring-amber-400/25',
  negative: 'bg-red-500/10 text-red-700 ring-red-600/20 dark:text-red-400 dark:ring-red-400/25',
}

const statusTone: Record<string, Tone> = {
  active: 'positive',
  success: 'positive',
  installed: 'positive',
  completed: 'positive',
  inactive: 'neutral',
  idle: 'neutral',
  deploying: 'progress',
  running: 'progress',
  pending: 'progress',
  installing: 'progress',
  removing: 'progress',
  failed: 'negative',
}

const liveStatuses = new Set(['deploying', 'running', 'pending', 'installing', 'removing'])

function humanize(status: string) {
  return status.charAt(0).toUpperCase() + status.slice(1).replace(/[_-]/g, ' ')
}

/**
 * Status as a quiet pill: a dot carries the color, the text carries the meaning,
 * so the state is never communicated by color alone. The dot only pulses while
 * something is actually in progress.
 */
export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const tone = statusTone[status] ?? 'neutral'
  const live = liveStatuses.has(status)

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset transition-colors duration-150',
        tones[tone],
        className
      )}
    >
      <span aria-hidden className={cn('status-dot', live && 'status-dot-live')} />
      {label ?? humanize(status)}
    </span>
  )
}
