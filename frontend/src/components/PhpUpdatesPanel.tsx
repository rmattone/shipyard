import { useCallback, useEffect, useRef, useState } from 'react'
import { ArrowPathIcon } from '@heroicons/react/24/outline'
import { toast } from 'sonner'
import { useAuth } from '@/hooks/useAuth'
import { databasesApi, serversApi, type DatabaseInstallation, type PhpBranch } from '@/services/api'
import { LoadingSpinner } from '@/components/custom'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'

const isRunning = (run: DatabaseInstallation | null) => run?.status === 'pending' || run?.status === 'running'
const errorMessage = (error: unknown) =>
  (error as { response?: { data?: { message?: string } } }).response?.data?.message || 'Could not reach the server.'

interface Props {
  serverId: number
  disabled: boolean
  onBusyChange: (busy: boolean) => void
  onComplete: () => void
}

export default function PhpUpdatesPanel({ serverId, disabled, onBusyChange, onComplete }: Props) {
  const { hasRole } = useAuth()
  const [branches, setBranches] = useState<PhpBranch[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingHistory, setLoadingHistory] = useState(true)
  const [starting, setStarting] = useState(false)
  const [error, setError] = useState('')
  const [pollError, setPollError] = useState('')
  const [run, setRun] = useState<DatabaseInstallation | null>(null)
  const [showLog, setShowLog] = useState(false)
  const completeRef = useRef(onComplete)
  completeRef.current = onComplete
  const busy = starting || isRunning(run)

  const refresh = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await serversApi.phpBranches(serverId)
      setBranches(response.data.branches)
    } catch (error) {
      setError(errorMessage(error))
    } finally {
      setLoading(false)
    }
  }, [serverId])

  useEffect(() => { void refresh() }, [refresh])

  // Reattach after a refresh/navigation using the persisted operation record.
  useEffect(() => {
    let cancelled = false
    databasesApi.installations(serverId).then(({ data }) => {
      if (!cancelled) setRun(data.find(item => item.engine === 'php' && item.version_requested) ?? null)
    }).catch(() => {
      if (!cancelled) setPollError('Could not load the previous update. Refresh this page to retry.')
    }).finally(() => {
      if (!cancelled) setLoadingHistory(false)
    })
    return () => { cancelled = true }
  }, [serverId])

  useEffect(() => {
    onBusyChange(busy)
    return () => onBusyChange(false)
  }, [busy, onBusyChange])

  const runId = run?.id
  const running = isRunning(run)
  useEffect(() => {
    if (!runId || !running) return
    let cancelled = false
    let timer: ReturnType<typeof setTimeout>
    const poll = async () => {
      try {
        const { data } = await databasesApi.installationStatus(runId)
        if (cancelled) return
        setRun(data)
        setPollError('')
        if (!isRunning(data)) {
          if (data.status === 'success') toast.success('PHP packages updated and services verified.')
          else toast.error('PHP update failed. Check the update log.')
          void refresh()
          completeRef.current()
          return
        }
      } catch {
        if (cancelled) return
        setPollError('Connection interrupted. The update may still be running; reconnecting…')
      }
      timer = setTimeout(poll, 2500)
    }
    void poll()
    return () => { cancelled = true; clearTimeout(timer) }
  }, [runId, running, refresh])

  const update = async (branch: string) => {
    setStarting(true)
    try {
      const { data } = await serversApi.updatePhp(serverId, branch)
      setRun(data)
      setPollError('')
      setShowLog(true)
    } catch (error) {
      toast.error(errorMessage(error))
    } finally {
      setStarting(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center justify-between gap-4">
          <div>
            <CardTitle className="text-lg">PHP Updates</CardTitle>
            <CardDescription>Update an installed PHP branch and its extensions on Ubuntu or Debian.</CardDescription>
          </div>
          <Button variant="outline" size="sm" onClick={refresh} disabled={loading || busy}>
            <ArrowPathIcon className="h-4 w-4 mr-2" /> Refresh
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-sm text-muted-foreground">
          Uses the latest packages available from this server’s configured repositories.
          Active PHP-FPM, Apache with mod_php, and detected ShipYard PHP workers are restarted;
          sites may briefly be interrupted. Restart externally managed PHP workers separately.
        </p>
        {error ? <p role="alert" className="text-sm text-destructive">{error}</p> : loading ? (
          <LoadingSpinner size="sm" />
        ) : branches.length === 0 ? (
          <p className="text-sm text-muted-foreground">No APT-managed PHP branches detected.</p>
        ) : branches.map(({ branch, packages }) => (
          <div key={branch} className="border rounded-lg p-4 space-y-3">
            <div className="flex items-center justify-between gap-4">
              <div className="min-w-0">
                <p className="font-medium">PHP {branch}</p>
                <p className="text-xs font-mono text-muted-foreground break-all">
                  {packages.find(pkg => pkg.name.split(':')[0] === `php${branch}-common`)?.version}
                </p>
              </div>
              {hasRole('owner', 'admin') && (
                <Button size="sm" variant="outline" disabled={disabled || busy || loadingHistory} onClick={() => update(branch)}>
                  {busy && run?.version_requested === branch ? <LoadingSpinner size="sm" /> : <ArrowPathIcon className="h-4 w-4 mr-2" />}
                  Update PHP {branch}
                </Button>
              )}
            </div>
            <details className="text-sm">
              <summary className="cursor-pointer text-muted-foreground">{packages.length} installed packages · View versions</summary>
              <ul className="mt-2 space-y-1 font-mono text-xs break-all">
                {packages.map(pkg => <li key={pkg.name}>{pkg.name}: {pkg.version}</li>)}
              </ul>
            </details>
          </div>
        ))}
        <p className="text-xs text-muted-foreground">
          A completed update confirms repository package versions and service restarts.
          Security fixes, including vendor backports, must be confirmed against the package vendor’s advisory.
        </p>
        {pollError && <p role="status" className="text-sm text-muted-foreground">{pollError}</p>}
        {run && (
          <div className="flex items-center gap-3">
            <Badge variant={run.status === 'failed' ? 'destructive' : 'secondary'}>{run.status}</Badge>
            <span className="text-sm">PHP {run.version_requested}{run.version_installed ? ` · ${run.version_installed}` : ''}</span>
            <Button variant="ghost" size="sm" onClick={() => setShowLog(true)}>View update log</Button>
          </div>
        )}
      </CardContent>
      <Dialog open={showLog} onOpenChange={setShowLog}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>PHP {run?.version_requested} update · {run?.status}</DialogTitle>
            <DialogDescription>Package and service output. You can close this dialog while the update runs.</DialogDescription>
          </DialogHeader>
          {pollError && <p role="status" className="text-sm">{pollError}</p>}
          <pre className="bg-black text-green-400 rounded-lg p-4 max-h-[50vh] overflow-auto whitespace-pre-wrap break-words text-xs">{run?.log || 'Waiting for output…'}</pre>
        </DialogContent>
      </Dialog>
    </Card>
  )
}
