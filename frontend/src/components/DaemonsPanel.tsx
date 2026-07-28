import { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import {
  CogIcon,
  PlusIcon,
  ArrowPathIcon,
  DocumentTextIcon,
  XCircleIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { LoadingSpinner, StatusBadge } from '@/components/custom'
import { cn } from '@/lib/utils'
import {
  daemonsApi,
  type Daemon,
  type DaemonStatus,
  type Application,
} from '@/services/api'

const QUEUE_CONNECTIONS = ['redis', 'database', 'sqs', 'beanstalkd'] as const

const appWorkingDir = (app: Application) =>
  app.deployment_strategy === 'atomic' ? `${app.deploy_path}/current` : app.deploy_path

const emptyForm = {
  command: '',
  user: 'www-data',
  directory: '',
  processes: 1,
}

const emptyWorkerForm = {
  connection: 'redis',
  queue: 'default',
  tries: 3,
  timeout: 60,
  sleep: 3,
}

type RuntimeState = DaemonStatus | 'unknown' | undefined

interface DaemonsPanelProps {
  serverId: number
  serverName: string
  serverPhpVersion?: string | null
  /** The server's provisioned deploy user, if any; new daemons default to it. */
  serverDeployUser?: string | null
  /** When set, the panel is scoped to this application's daemons. */
  application?: Application
  /** Apps on the server, for the worker preset and card badges. */
  apps?: Application[]
}

export function DaemonsPanel({ serverId, serverName, serverPhpVersion, serverDeployUser, application, apps = [] }: DaemonsPanelProps) {
  const [daemons, setDaemons] = useState<Daemon[]>([])
  const [loading, setLoading] = useState(true)
  const [runtime, setRuntime] = useState<Record<number, RuntimeState>>({})

  const [showAddDialog, setShowAddDialog] = useState(false)
  const [daemonToDelete, setDaemonToDelete] = useState<Daemon | null>(null)
  const [daemonToRestart, setDaemonToRestart] = useState<Daemon | null>(null)
  const [restarting, setRestarting] = useState<number | null>(null)
  const [outputDaemon, setOutputDaemon] = useState<Daemon | null>(null)
  const [output, setOutput] = useState<{ output: string; exists: boolean } | null>(null)
  const [loadingOutput, setLoadingOutput] = useState(false)

  const [formData, setFormData] = useState({ ...emptyForm, user: serverDeployUser ?? 'www-data' })
  const [workerForm, setWorkerForm] = useState(emptyWorkerForm)
  const [presetAppId, setPresetAppId] = useState<number | null>(null)
  const [usePreset, setUsePreset] = useState(false)
  const [saving, setSaving] = useState(false)

  const laravelApps = application
    ? (application.type === 'laravel' ? [application] : [])
    : apps.filter(app => app.type === 'laravel')

  const refreshDaemons = useCallback(async () => {
    const response = await daemonsApi.list(serverId, application?.id)
    setDaemons(response.data)
    return response.data
  }, [serverId, application?.id])

  const refreshRuntime = useCallback(async (list: Daemon[]) => {
    const installed = list.filter(d => d.status === 'installed')
    // Errors degrade to a muted "unknown" badge, never a toast: an
    // unreachable server must not spam the page.
    const results = await Promise.all(
      installed.map(d =>
        daemonsApi.status(serverId, d.id)
          .then(r => [d.id, r.data] as const)
          .catch(() => [d.id, 'unknown'] as const)
      )
    )
    setRuntime(prev => {
      const next = { ...prev }
      for (const [id, state] of results) next[id] = state
      return next
    })
  }, [serverId])

  useEffect(() => {
    refreshDaemons()
      .then(list => refreshRuntime(list))
      .catch((error: unknown) => {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load daemons')
      })
      .finally(() => setLoading(false))
  }, [refreshDaemons, refreshRuntime])

  // While any daemon is installing or removing, poll until the queue settles.
  const hasPending = daemons.some(d => d.status === 'installing' || d.status === 'removing')

  useEffect(() => {
    if (!hasPending) return

    const interval = setInterval(async () => {
      try {
        const list = await refreshDaemons()
        if (!list.some(d => d.status === 'installing' || d.status === 'removing')) {
          refreshRuntime(list)
        }
      } catch {
        // Transient polling errors are ignored; the next tick retries.
      }
    }, 3000)

    return () => clearInterval(interval)
  }, [hasPending, refreshDaemons, refreshRuntime])

  const workerCommand = (app: Application | undefined) => {
    const phpVersion = app?.php_version ?? serverPhpVersion ?? '8.3'
    return `php${phpVersion} artisan queue:work ${workerForm.connection}`
      + ` --queue=${workerForm.queue} --sleep=${workerForm.sleep}`
      + ` --tries=${workerForm.tries} --timeout=${workerForm.timeout}`
  }

  const presetApp = application ?? laravelApps.find(a => a.id === presetAppId)

  const openAddDialog = () => {
    setPresetAppId(null)
    setUsePreset(false)
    setWorkerForm(emptyWorkerForm)
    setFormData({
      ...emptyForm,
      user: serverDeployUser ?? 'www-data',
      directory: application ? appWorkingDir(application) : '',
    })
    setShowAddDialog(true)
  }

  const applyWorkerPreset = (form: typeof emptyWorkerForm, app: Application | undefined) => {
    setWorkerForm(form)
    if (!app) return
    const phpVersion = app.php_version ?? serverPhpVersion ?? '8.3'
    setFormData(prev => ({
      ...prev,
      command: `php${phpVersion} artisan queue:work ${form.connection}`
        + ` --queue=${form.queue} --sleep=${form.sleep}`
        + ` --tries=${form.tries} --timeout=${form.timeout}`,
      directory: appWorkingDir(app),
    }))
  }

  const handleCreate = async () => {
    if (!formData.command.trim() || !formData.user.trim()) {
      toast.error('Please fill in the command and user fields')
      return
    }
    if (!application && !presetAppId && !formData.directory.trim()) {
      toast.error('Please fill in the directory field')
      return
    }

    setSaving(true)
    try {
      const applicationId = application?.id ?? presetAppId ?? undefined
      const response = await daemonsApi.create(serverId, {
        command: formData.command,
        user: formData.user,
        ...(formData.directory.trim() ? { directory: formData.directory } : {}),
        processes: formData.processes,
        ...(applicationId ? { application_id: applicationId } : {}),
      })
      setDaemons([response.data, ...daemons])
      toast.success('Daemon is being installed')
      setShowAddDialog(false)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create daemon')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!daemonToDelete) return

    try {
      await daemonsApi.delete(serverId, daemonToDelete.id)
      toast.success('Daemon is being removed')
      await refreshDaemons()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to remove daemon')
    } finally {
      setDaemonToDelete(null)
    }
  }

  const handleRestart = async () => {
    if (!daemonToRestart) return
    const daemon = daemonToRestart
    setDaemonToRestart(null)
    setRestarting(daemon.id)

    try {
      await daemonsApi.restart(serverId, daemon.id)
      toast.success('Daemon restarted')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to restart daemon')
    } finally {
      setRestarting(null)
      refreshRuntime([daemon])
    }
  }

  const openOutputDialog = async (daemon: Daemon) => {
    setOutputDaemon(daemon)
    setOutput(null)
    await loadOutput(daemon)
  }

  const loadOutput = async (daemon: Daemon) => {
    setLoadingOutput(true)
    try {
      const response = await daemonsApi.output(serverId, daemon.id, 200)
      setOutput(response.data)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load daemon logs')
    } finally {
      setLoadingOutput(false)
    }
  }

  const appName = (daemon: Daemon) =>
    apps.find(a => a.id === daemon.application_id)?.name

  const runtimeBadge = (daemon: Daemon) => {
    if (daemon.status !== 'installed') return null
    const state = runtime[daemon.id]
    if (state === undefined) return null
    if (state === 'unknown') {
      return <Badge variant="secondary" className="bg-gray-100 text-gray-500">unknown</Badge>
    }
    const activeCount = Object.values(state.instances).filter(s => s === 'active').length
    const total = Object.keys(state.instances).length
    return (
      <Badge
        variant="secondary"
        className={cn(
          state.state === 'running' && 'bg-green-100 text-green-800',
          state.state === 'degraded' && 'bg-yellow-100 text-yellow-800',
          state.state === 'stopped' && 'bg-red-100 text-red-800',
        )}
      >
        {state.state === 'degraded' ? `degraded (${activeCount}/${total})` : state.state}
      </Badge>
    )
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Daemons</h1>
          <p className="text-muted-foreground">
            {application
              ? `Background processes for ${application.name}`
              : `Background processes on ${serverName}`}
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={() => refreshRuntime(daemons)}
            title="Refresh runtime status"
          >
            <ArrowPathIcon className="h-4 w-4" />
          </Button>
          <Button onClick={openAddDialog}>
            <PlusIcon className="h-4 w-4 mr-2" />
            New Daemon
          </Button>
        </div>
      </div>

      {daemons.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <CogIcon className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-medium mb-2">No daemons</h3>
            <p className="text-muted-foreground text-center mb-4">
              {application?.type === 'laravel'
                ? 'Run queue workers or Horizon as always-on processes that restart with every deploy.'
                : 'Run long-lived commands as always-on processes supervised by systemd.'}
            </p>
            <Button onClick={openAddDialog}>
              <PlusIcon className="h-4 w-4 mr-2" />
              New Daemon
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {daemons.map((daemon) => (
            <Card key={daemon.id}>
              <CardContent className="p-6">
                <div className="flex items-center justify-between gap-4">
                  <div className="flex items-center gap-4 min-w-0">
                    <div className="h-12 w-12 shrink-0 rounded-lg bg-sky-500/10 flex items-center justify-center">
                      <CogIcon className="h-6 w-6 text-sky-500" />
                    </div>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <h3 className="font-mono text-sm font-medium truncate" title={daemon.command}>
                          {daemon.command}
                        </h3>
                        <StatusBadge status={daemon.status} />
                        {runtimeBadge(daemon)}
                      </div>
                      <p className="text-sm text-muted-foreground truncate">
                        <Badge variant="outline" className="mr-2 font-mono">{daemon.user}</Badge>
                        {serverDeployUser && daemon.user !== serverDeployUser && (
                          <Badge variant="outline" className="mr-2 text-amber-500 border-amber-500/40">
                            not the deploy user
                          </Badge>
                        )}
                        {!application && appName(daemon) && (
                          <Badge variant="secondary" className="mr-2">{appName(daemon)}</Badge>
                        )}
                        {daemon.processes} {daemon.processes === 1 ? 'process' : 'processes'}
                        <span className="ml-2 font-mono text-xs">{daemon.directory}</span>
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setDaemonToRestart(daemon)}
                      disabled={daemon.status !== 'installed' || restarting === daemon.id}
                    >
                      {restarting === daemon.id ? (
                        <LoadingSpinner size="sm" />
                      ) : (
                        <>
                          <ArrowPathIcon className="h-4 w-4 mr-1" />
                          Restart
                        </>
                      )}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => openOutputDialog(daemon)}
                    >
                      <DocumentTextIcon className="h-4 w-4 mr-1" />
                      Logs
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setDaemonToDelete(daemon)}
                      disabled={daemon.status === 'removing'}
                    >
                      <XCircleIcon className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Create Dialog */}
      <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>New Daemon</DialogTitle>
            <DialogDescription>
              Installed as a systemd service on {serverName}. To change a daemon later,
              delete and recreate it.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {laravelApps.length > 0 && (
              <div className="space-y-3 rounded-md border p-3">
                <div className="flex items-center justify-between">
                  <Label>Laravel queue worker preset</Label>
                  {!application && (
                    <Select onValueChange={(value) => {
                      const app = laravelApps.find(a => a.id === parseInt(value))
                      setPresetAppId(app?.id ?? null)
                      setUsePreset(true)
                      if (app) applyWorkerPreset(workerForm, app)
                    }}>
                      <SelectTrigger className="w-44">
                        <SelectValue placeholder="Pick an app..." />
                      </SelectTrigger>
                      <SelectContent>
                        {laravelApps.map(app => (
                          <SelectItem key={app.id} value={String(app.id)}>{app.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                  {application && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => { setUsePreset(true); applyWorkerPreset(workerForm, application) }}
                    >
                      Use preset
                    </Button>
                  )}
                </div>
                {usePreset && presetApp && (
                  <>
                    <div className="grid grid-cols-2 gap-2">
                      <div className="space-y-1">
                        <Label className="text-xs">Connection</Label>
                        <Select
                          value={workerForm.connection}
                          onValueChange={(connection) =>
                            applyWorkerPreset({ ...workerForm, connection }, presetApp)
                          }
                        >
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {QUEUE_CONNECTIONS.map(c => (
                              <SelectItem key={c} value={c}>{c}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-1">
                        <Label htmlFor="queue" className="text-xs">Queue</Label>
                        <Input
                          id="queue"
                          value={workerForm.queue}
                          onChange={(e) => applyWorkerPreset({ ...workerForm, queue: e.target.value }, presetApp)}
                        />
                      </div>
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                      {([['tries', 'Tries'], ['timeout', 'Timeout'], ['sleep', 'Sleep']] as const).map(([field, label]) => (
                        <div key={field} className="space-y-1">
                          <Label htmlFor={field} className="text-xs">{label}</Label>
                          <Input
                            id={field}
                            type="number"
                            value={workerForm[field]}
                            onChange={(e) => applyWorkerPreset(
                              { ...workerForm, [field]: parseInt(e.target.value) || 0 },
                              presetApp
                            )}
                          />
                        </div>
                      ))}
                    </div>
                    <p className="font-mono text-xs text-muted-foreground break-all">
                      {workerCommand(presetApp)}
                    </p>
                  </>
                )}
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="command">Command *</Label>
              <Input
                id="command"
                className="font-mono"
                value={formData.command}
                onChange={(e) => setFormData({ ...formData, command: e.target.value })}
                placeholder="php8.3 artisan queue:work redis --tries=3"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="directory">Directory *</Label>
              <Input
                id="directory"
                className="font-mono"
                value={formData.directory}
                onChange={(e) => setFormData({ ...formData, directory: e.target.value })}
                placeholder="/home/shipyard/app/current"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="daemon-user">User *</Label>
                <Input
                  id="daemon-user"
                  className="font-mono"
                  value={formData.user}
                  onChange={(e) => setFormData({ ...formData, user: e.target.value })}
                  placeholder="www-data"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="processes">Processes</Label>
                <Input
                  id="processes"
                  type="number"
                  min={1}
                  max={10}
                  value={formData.processes}
                  onChange={(e) => setFormData({ ...formData, processes: Math.max(1, Math.min(10, parseInt(e.target.value) || 1)) })}
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Create Daemon
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Logs Dialog */}
      <Dialog open={!!outputDaemon} onOpenChange={(open) => { if (!open) setOutputDaemon(null) }}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle>Daemon Logs</DialogTitle>
            <DialogDescription className="font-mono text-xs truncate">
              {outputDaemon?.command}
            </DialogDescription>
          </DialogHeader>
          {outputDaemon?.status === 'failed' && outputDaemon.log && (
            <div className="rounded-md border border-red-200 bg-red-50 p-3">
              <p className="text-sm font-medium text-red-800 mb-1">Installation failed</p>
              <pre className="text-xs text-red-800 whitespace-pre-wrap max-h-32 overflow-y-auto">
                {outputDaemon.log}
              </pre>
            </div>
          )}
          {loadingOutput ? (
            <div className="flex items-center justify-center py-8">
              <LoadingSpinner size="lg" />
            </div>
          ) : output && !output.exists ? (
            <p className="text-sm text-muted-foreground py-4 text-center">
              No log entries yet. Output appears once the daemon writes to stdout or stderr.
            </p>
          ) : (
            <pre className="bg-muted rounded-md p-4 text-xs font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">
              {output?.output || ''}
            </pre>
          )}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => outputDaemon && loadOutput(outputDaemon)}
              disabled={loadingOutput}
            >
              <ArrowPathIcon className="h-4 w-4 mr-1" />
              Refresh
            </Button>
            <Button onClick={() => setOutputDaemon(null)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Restart Confirmation */}
      <AlertDialog open={!!daemonToRestart} onOpenChange={() => setDaemonToRestart(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Restart Daemon</AlertDialogTitle>
            <AlertDialogDescription>
              Restarts all {daemonToRestart?.processes} process(es). In-flight work receives
              SIGTERM and has up to 30 seconds to finish.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleRestart}>
              Restart
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!daemonToDelete} onOpenChange={() => setDaemonToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Daemon</AlertDialogTitle>
            <AlertDialogDescription>
              Stops all processes and removes the systemd unit from the server.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
              Remove
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
