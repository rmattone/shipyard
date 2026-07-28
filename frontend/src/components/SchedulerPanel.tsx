import { useState, useEffect, useCallback } from 'react'
import { toast } from 'sonner'
import {
  ClockIcon,
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
import {
  scheduledTasksApi,
  type ScheduledTask,
  type Application,
} from '@/services/api'

const FREQUENCIES: { value: ScheduledTask['frequency']; label: string }[] = [
  { value: 'minutely', label: 'Every Minute' },
  { value: 'hourly', label: 'Hourly' },
  { value: 'nightly', label: 'Nightly' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'reboot', label: 'On Reboot' },
  { value: 'custom', label: 'Custom' },
]

const frequencyLabel = (frequency: ScheduledTask['frequency']) =>
  FREQUENCIES.find(f => f.value === frequency)?.label ?? frequency

const laravelSchedulerCommand = (app: Application, serverPhpVersion?: string | null) => {
  const phpVersion = app.php_version ?? serverPhpVersion ?? '8.3'
  const base = app.deployment_strategy === 'atomic'
    ? `${app.deploy_path}/current`
    : app.deploy_path
  return `php${phpVersion} ${base}/artisan schedule:run`
}

const emptyForm = {
  command: '',
  user: 'www-data',
  frequency: 'minutely' as ScheduledTask['frequency'],
  minute: '*',
  hour: '*',
  day: '*',
  month: '*',
  weekday: '*',
}

interface SchedulerPanelProps {
  serverId: number
  serverName: string
  serverPhpVersion?: string | null
  /** The server's provisioned deploy user, if any; new tasks default to it. */
  serverDeployUser?: string | null
  /** When set, the panel is scoped to this application's tasks. */
  application?: Application
  /** Laravel apps on the server, for the preset select and card badges. */
  apps?: Application[]
}

export function SchedulerPanel({ serverId, serverName, serverPhpVersion, serverDeployUser, application, apps = [] }: SchedulerPanelProps) {
  const [tasks, setTasks] = useState<ScheduledTask[]>([])
  const [loading, setLoading] = useState(true)

  const [showAddDialog, setShowAddDialog] = useState(false)
  const [taskToDelete, setTaskToDelete] = useState<ScheduledTask | null>(null)
  const [outputTask, setOutputTask] = useState<ScheduledTask | null>(null)
  const [output, setOutput] = useState<{ output: string; exists: boolean } | null>(null)
  const [loadingOutput, setLoadingOutput] = useState(false)

  const [formData, setFormData] = useState({ ...emptyForm, user: serverDeployUser ?? 'www-data' })
  const [presetAppId, setPresetAppId] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)

  const laravelApps = application ? [] : apps.filter(app => app.type === 'laravel')

  const refreshTasks = useCallback(async () => {
    const response = await scheduledTasksApi.list(serverId, application?.id)
    setTasks(response.data)
    return response.data
  }, [serverId, application?.id])

  useEffect(() => {
    refreshTasks()
      .catch((error: unknown) => {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load scheduled tasks')
      })
      .finally(() => setLoading(false))
  }, [refreshTasks])

  // While any task is installing or removing, poll until the queue settles.
  const hasPendingTasks = tasks.some(t => t.status === 'installing' || t.status === 'removing')

  useEffect(() => {
    if (!hasPendingTasks) return

    const interval = setInterval(async () => {
      try {
        await refreshTasks()
      } catch {
        // Transient polling errors are ignored; the next tick retries.
      }
    }, 3000)

    return () => clearInterval(interval)
  }, [hasPendingTasks, refreshTasks])

  const openAddDialog = () => {
    setPresetAppId(null)
    // App-scoped Laravel apps get the scheduler command pre-filled; that is
    // the task this panel exists for.
    if (application && application.type === 'laravel') {
      setFormData({
        ...emptyForm,
        user: serverDeployUser ?? 'www-data',
        command: laravelSchedulerCommand(application, serverPhpVersion),
      })
    } else {
      setFormData({ ...emptyForm, user: serverDeployUser ?? 'www-data' })
    }
    setShowAddDialog(true)
  }

  const applyLaravelPreset = (appId: string) => {
    const app = laravelApps.find(a => a.id === parseInt(appId))
    if (!app) return

    setPresetAppId(app.id)
    setFormData({
      ...emptyForm,
      command: laravelSchedulerCommand(app, serverPhpVersion),
      user: serverDeployUser ?? 'www-data',
      frequency: 'minutely',
    })
  }

  const handleCreate = async () => {
    if (!formData.command.trim() || !formData.user.trim()) {
      toast.error('Please fill in the command and user fields')
      return
    }

    setSaving(true)
    try {
      const applicationId = application?.id ?? presetAppId ?? undefined
      const payload = {
        command: formData.command,
        user: formData.user,
        frequency: formData.frequency,
        ...(applicationId ? { application_id: applicationId } : {}),
        ...(formData.frequency === 'custom'
          ? {
              minute: formData.minute,
              hour: formData.hour,
              day: formData.day,
              month: formData.month,
              weekday: formData.weekday,
            }
          : {}),
      }
      const response = await scheduledTasksApi.create(serverId, payload)
      setTasks([response.data, ...tasks])
      toast.success('Scheduled task is being installed')
      setShowAddDialog(false)
      setFormData(emptyForm)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create scheduled task')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!taskToDelete) return

    try {
      await scheduledTasksApi.delete(serverId, taskToDelete.id)
      toast.success('Scheduled task is being removed')
      await refreshTasks()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to remove scheduled task')
    } finally {
      setTaskToDelete(null)
    }
  }

  const openOutputDialog = async (task: ScheduledTask) => {
    setOutputTask(task)
    setOutput(null)
    await loadOutput(task)
  }

  const loadOutput = async (task: ScheduledTask) => {
    setLoadingOutput(true)
    try {
      const response = await scheduledTasksApi.output(serverId, task.id, 200)
      setOutput(response.data)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load task output')
    } finally {
      setLoadingOutput(false)
    }
  }

  const appName = (task: ScheduledTask) =>
    apps.find(a => a.id === task.application_id)?.name

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
          <h1 className="text-2xl font-bold">Scheduler</h1>
          <p className="text-muted-foreground">
            {application
              ? `Manage cron jobs for ${application.name}`
              : `Manage cron jobs on ${serverName}`}
          </p>
        </div>
        <Button onClick={openAddDialog}>
          <PlusIcon className="h-4 w-4 mr-2" />
          New Scheduled Task
        </Button>
      </div>

      {tasks.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <ClockIcon className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-medium mb-2">No scheduled tasks</h3>
            <p className="text-muted-foreground text-center mb-4">
              {application?.type === 'laravel'
                ? 'The Laravel scheduler needs a cron entry running schedule:run every minute.'
                : 'Create a cron job to run commands on a schedule.'}
            </p>
            <Button onClick={openAddDialog}>
              <PlusIcon className="h-4 w-4 mr-2" />
              New Scheduled Task
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {tasks.map((task) => (
            <Card key={task.id}>
              <CardContent className="p-6">
                <div className="flex items-center justify-between gap-4">
                  <div className="flex items-center gap-4 min-w-0">
                    <div className="h-12 w-12 shrink-0 rounded-lg bg-amber-500/10 flex items-center justify-center">
                      <ClockIcon className="h-6 w-6 text-amber-500" />
                    </div>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <h3 className="font-mono text-sm font-medium truncate" title={task.command}>
                          {task.command}
                        </h3>
                        <StatusBadge status={task.status} />
                      </div>
                      <p className="text-sm text-muted-foreground">
                        <Badge variant="outline" className="mr-2 font-mono">{task.user}</Badge>
                        {!application && appName(task) && (
                          <Badge variant="secondary" className="mr-2">{appName(task)}</Badge>
                        )}
                        {frequencyLabel(task.frequency)}
                        <span className="ml-2 font-mono text-xs">{task.cron_expression}</span>
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => openOutputDialog(task)}
                    >
                      <DocumentTextIcon className="h-4 w-4 mr-1" />
                      Output
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setTaskToDelete(task)}
                      disabled={task.status === 'removing'}
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
            <DialogTitle>New Scheduled Task</DialogTitle>
            <DialogDescription>
              The command is installed as a cron entry on {serverName}.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            {laravelApps.length > 0 && (
              <div className="space-y-2">
                <Label>Laravel Scheduler Preset (optional)</Label>
                <Select onValueChange={applyLaravelPreset}>
                  <SelectTrigger>
                    <SelectValue placeholder="Fill in schedule:run for an app..." />
                  </SelectTrigger>
                  <SelectContent>
                    {laravelApps.map(app => (
                      <SelectItem key={app.id} value={String(app.id)}>
                        {app.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            )}

            <div className="space-y-2">
              <Label htmlFor="command">Command *</Label>
              <Input
                id="command"
                className="font-mono"
                value={formData.command}
                onChange={(e) => setFormData({ ...formData, command: e.target.value })}
                placeholder="php8.3 /home/shipyard/app/current/artisan schedule:run"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="user">User *</Label>
                <Input
                  id="user"
                  className="font-mono"
                  value={formData.user}
                  onChange={(e) => setFormData({ ...formData, user: e.target.value })}
                  placeholder="www-data"
                />
                <p className="text-xs text-muted-foreground">
                  Unix user whose crontab runs this task
                </p>
              </div>
              <div className="space-y-2">
                <Label>Frequency *</Label>
                <Select
                  value={formData.frequency}
                  onValueChange={(value: ScheduledTask['frequency']) =>
                    setFormData({ ...formData, frequency: value })
                  }
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {FREQUENCIES.map(f => (
                      <SelectItem key={f.value} value={f.value}>{f.label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {formData.frequency === 'custom' && (
              <div className="grid grid-cols-5 gap-2">
                {([
                  ['minute', 'Minute'],
                  ['hour', 'Hour'],
                  ['day', 'Day'],
                  ['month', 'Month'],
                  ['weekday', 'Weekday'],
                ] as const).map(([field, label]) => (
                  <div key={field} className="space-y-2">
                    <Label htmlFor={field} className="text-xs">{label}</Label>
                    <Input
                      id={field}
                      className="font-mono text-center"
                      value={formData[field]}
                      onChange={(e) => setFormData({ ...formData, [field]: e.target.value })}
                    />
                  </div>
                ))}
              </div>
            )}

            {formData.frequency === 'reboot' && (
              <p className="text-sm text-muted-foreground">
                This task runs once every time the server boots.
              </p>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Create Task
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Output Dialog */}
      <Dialog open={!!outputTask} onOpenChange={(open) => { if (!open) setOutputTask(null) }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>Task Output</DialogTitle>
            <DialogDescription className="font-mono text-xs truncate">
              {outputTask?.command}
            </DialogDescription>
          </DialogHeader>
          {outputTask?.status === 'failed' && outputTask.log && (
            <div className="rounded-md border border-red-200 bg-red-50 p-3">
              <p className="text-sm font-medium text-red-800 mb-1">Installation failed</p>
              <pre className="text-xs text-red-800 whitespace-pre-wrap max-h-32 overflow-y-auto">
                {outputTask.log}
              </pre>
            </div>
          )}
          {loadingOutput ? (
            <div className="flex items-center justify-center py-8">
              <LoadingSpinner size="lg" />
            </div>
          ) : output && !output.exists ? (
            <p className="text-sm text-muted-foreground py-4 text-center">
              No output yet. The log appears after the task runs for the first time.
            </p>
          ) : (
            <pre className="bg-muted rounded-md p-4 text-xs font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">
              {output?.output || ''}
            </pre>
          )}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => outputTask && loadOutput(outputTask)}
              disabled={loadingOutput}
            >
              <ArrowPathIcon className="h-4 w-4 mr-1" />
              Refresh
            </Button>
            <Button onClick={() => setOutputTask(null)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!taskToDelete} onOpenChange={() => setTaskToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Scheduled Task</AlertDialogTitle>
            <AlertDialogDescription>
              This removes the cron entry and its log file from the server. The command will
              no longer run on schedule.
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
