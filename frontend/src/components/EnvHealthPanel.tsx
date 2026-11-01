import { useCallback, useEffect, useState } from 'react'
import { toast } from 'sonner'
import {
  ExclamationTriangleIcon,
  ExclamationCircleIcon,
  InformationCircleIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { LoadingSpinner } from '@/components/custom'
import { cn } from '@/lib/utils'
import { daemonsApi, envApi, scheduledTasksApi, type EnvWarning } from '@/services/api'

const LEVEL_STYLE: Record<EnvWarning['level'], { icon: typeof InformationCircleIcon; className: string }> = {
  danger: {
    icon: ExclamationCircleIcon,
    className: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/20 dark:text-red-200',
  },
  warning: {
    icon: ExclamationTriangleIcon,
    className: 'border-yellow-200 bg-yellow-50 text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950/20 dark:text-yellow-200',
  },
  info: {
    icon: InformationCircleIcon,
    className: 'border-border bg-muted/40 text-muted-foreground',
  },
}

const FIX_LABEL: Record<NonNullable<EnvWarning['fix']>['type'], string> = {
  daemon: 'Add queue worker',
  scheduled_task: 'Add scheduler cron',
}

/**
 * Production checks for the saved environment. `refreshKey` changes after
 * every save so the list follows what is actually stored, not the editor.
 */
export function EnvHealthPanel({ appId, refreshKey }: { appId: number; refreshKey: number }) {
  const [warnings, setWarnings] = useState<EnvWarning[]>([])
  const [fixing, setFixing] = useState<string | null>(null)

  const load = useCallback(() => {
    envApi.getHealth(appId)
      .then(response => setWarnings(response.data.warnings))
      .catch(() => setWarnings([]))
  }, [appId])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const applyFix = async (warning: EnvWarning) => {
    const fix = warning.fix
    if (!fix) return

    setFixing(warning.code)
    try {
      if (fix.type === 'daemon') {
        await daemonsApi.create(fix.server_id, fix.data)
        toast.success('Queue worker is being installed')
      } else {
        await scheduledTasksApi.create(fix.server_id, fix.data)
        toast.success('Scheduler cron entry is being installed')
      }
      load()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to apply the fix')
    } finally {
      setFixing(null)
    }
  }

  if (warnings.length === 0) return null

  return (
    <div className="space-y-2">
      {warnings.map((warning) => {
        const style = LEVEL_STYLE[warning.level]
        const Icon = style.icon
        return (
          <div
            key={warning.code}
            className={cn('flex items-start justify-between gap-4 rounded-lg border p-3 text-sm', style.className)}
          >
            <div className="flex items-start gap-2">
              <Icon className="h-4 w-4 mt-0.5 shrink-0" />
              <p>{warning.message}</p>
            </div>
            {warning.fix && (
              <Button
                size="sm"
                variant="outline"
                className="shrink-0"
                onClick={() => applyFix(warning)}
                disabled={fixing !== null}
              >
                {fixing === warning.code ? <LoadingSpinner size="sm" /> : FIX_LABEL[warning.fix.type]}
              </Button>
            )}
          </div>
        )
      })}
    </div>
  )
}
