import { useEffect, useState, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useSectionParam } from '@/hooks/useSectionParam'
import { gitProvidersApi, systemApi, GitProvider, SystemVersion, UpdateStatus } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { PlusIcon, TrashIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
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
import { cn } from '@/lib/utils'
import { AccountSettings } from './AccountSettings'
import { NotificationChannels } from './NotificationChannels'
import { OrganizationGeneral } from './OrganizationGeneral'
import { OrganizationMembers } from './OrganizationMembers'
import { ServerTrash } from './ServerTrash'

type SettingsSection = 'account' | 'source-control' | 'notifications' | 'members' | 'general' | 'trash' | 'system'
const SECTIONS: readonly SettingsSection[] = ['account', 'source-control', 'notifications', 'members', 'general', 'trash', 'system']

export default function Settings() {
  const navigate = useNavigate()
  const [providers, setProviders] = useState<GitProvider[]>([])
  const [loading, setLoading] = useState(true)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [activeSection, setActiveSection] = useSectionParam(SECTIONS, 'source-control')

  // System update state
  const [versionInfo, setVersionInfo] = useState<SystemVersion | null>(null)
  const [updateStatus, setUpdateStatus] = useState<UpdateStatus | null>(null)
  const [updating, setUpdating] = useState(false)
  const [checkingVersion, setCheckingVersion] = useState(false)
  const logRef = useRef<HTMLPreElement>(null)
  const pollIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  useEffect(() => {
    loadProviders()
  }, [])

  // Load version info when switching to system section, and resume watching
  // an update that is already running (for example after a page reload).
  useEffect(() => {
    if (activeSection === 'system') {
      checkVersion()
      systemApi.getUpdateStatus()
        .then((response) => {
          if (response.data.status === 'running') {
            setUpdateStatus(response.data)
            setUpdating(true)
            beginPolling()
          } else if (response.data.status !== 'idle') {
            setUpdateStatus(response.data)
          }
        })
        .catch(() => { /* not fatal; the section still renders */ })
    }
    return () => stopPolling()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeSection])

  const loadProviders = async () => {
    try {
      const response = await gitProvidersApi.list()
      setProviders(response.data)
    } catch {
      toast.error('Failed to load git providers')
    } finally {
      setLoading(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteId) return
    setDeleting(true)
    try {
      await gitProvidersApi.delete(deleteId)
      setProviders(providers.filter(p => p.id !== deleteId))
      toast.success('Git provider deleted')
    } catch {
      toast.error('Failed to delete git provider')
    } finally {
      setDeleting(false)
      setDeleteId(null)
    }
  }

  const getProviderIcon = (type: string) => {
    switch (type) {
      case 'github':
        return '🐙'
      case 'gitlab':
        return '🦊'
      case 'bitbucket':
        return '🪣'
      default:
        return '📦'
    }
  }

  const checkVersion = async (refresh = false) => {
    setCheckingVersion(true)
    try {
      const response = await systemApi.getVersion(refresh)
      setVersionInfo(response.data)
    } catch {
      toast.error('Failed to check for updates')
    } finally {
      setCheckingVersion(false)
    }
  }

  const stopPolling = () => {
    if (pollIntervalRef.current) {
      clearInterval(pollIntervalRef.current)
      pollIntervalRef.current = null
    }
  }

  const beginPolling = () => {
    stopPolling()
    const startedAt = Date.now()
    pollIntervalRef.current = setInterval(async () => {
      try {
        const response = await systemApi.getUpdateStatus()
        setUpdateStatus(response.data)

        if (logRef.current) {
          logRef.current.scrollTop = logRef.current.scrollHeight
        }

        const finished = response.data.status === 'completed' || response.data.status === 'failed'
        // "idle" after we started means the state was lost (cache cleared);
        // give the job a moment to be picked up before treating it as gone.
        const lost = response.data.status === 'idle' && Date.now() - startedAt > 15000
        if (finished || lost) {
          stopPolling()
          setUpdating(false)
          if (response.data.status === 'completed') {
            toast.success('Update completed. Reload to use the new version.')
            checkVersion(true)
          } else if (response.data.status === 'failed') {
            toast.error(response.data.message || 'Update failed. Check the log for details.')
          } else {
            toast.error('Lost track of the update. Check the queue worker logs.')
          }
        }
      } catch {
        // The app is in maintenance mode or restarting; keep polling.
      }
    }, 2000)
  }

  const startUpdate = async () => {
    setUpdating(true)
    setUpdateStatus({ running: true, status: 'running', log: 'Queueing update...\n', exit_code: null, started_at: null, finished_at: null, message: null })

    try {
      await systemApi.startUpdate()
      beginPolling()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to start update')
      setUpdateStatus(null)
      setUpdating(false)
      setUpdateStatus(null)
    }
  }

  const sidebarItems = [
    { id: 'account' as const, label: 'Account' },
    { id: 'source-control' as const, label: 'Source Control' },
    { id: 'notifications' as const, label: 'Notifications' },
    { id: 'members' as const, label: 'Members' },
    { id: 'general' as const, label: 'General' },
    { id: 'trash' as const, label: 'Trash' },
    { id: 'system' as const, label: 'System' },
  ]

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="settings-layout">
      <div className="settings-sidebar">
        <h1 className="text-2xl font-semibold tracking-tight">Settings</h1>
        <nav aria-label="Settings sections">
          {sidebarItems.map((item) => (
            <button
              key={item.id}
              onClick={() => setActiveSection(item.id)}
              aria-current={activeSection === item.id ? 'page' : undefined}
              className={cn(
                'settings-tab',
                activeSection === item.id
                  ? 'settings-tab-active'
                  : 'text-muted-foreground hover:text-foreground'
              )}
            >
              {item.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        {activeSection === 'source-control' && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Source Control</CardTitle>
                  <CardDescription>
                    Connect your Git providers to deploy applications.
                  </CardDescription>
                </div>
                <Button onClick={() => navigate('/settings/git-providers/new')}>
                  <PlusIcon className="h-4 w-4 mr-2" />
                  Add Provider
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {providers.length === 0 ? (
                <div className="text-center py-8">
                  <p className="text-muted-foreground mb-4">No git providers connected yet.</p>
                  <Button onClick={() => navigate('/settings/git-providers/new')}>
                    <PlusIcon className="h-4 w-4 mr-2" />
                    Connect Your First Provider
                  </Button>
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Provider</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead>Host</TableHead>
                      <TableHead>Applications</TableHead>
                      <TableHead>Default</TableHead>
                      <TableHead className="w-[60px]"></TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {providers.map((provider) => (
                      <TableRow key={provider.id}>
                        <TableCell>
                          <Link
                            to={`/settings/git-providers/${provider.id}`}
                            className="font-medium text-primary hover:underline flex items-center gap-2"
                          >
                            <span>{getProviderIcon(provider.type)}</span>
                            {provider.name}
                          </Link>
                        </TableCell>
                        <TableCell className="capitalize">{provider.type}</TableCell>
                        <TableCell className="text-muted-foreground">
                          {provider.host || '-'}
                        </TableCell>
                        <TableCell>{provider.applications_count || 0}</TableCell>
                        <TableCell>
                          {provider.is_default && (
                            <StatusBadge status="active" />
                          )}
                        </TableCell>
                        <TableCell>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setDeleteId(provider.id)}
                            disabled={(provider.applications_count || 0) > 0}
                          >
                            <TrashIcon className="h-4 w-4 text-destructive" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        )}

        {activeSection === 'account' && <AccountSettings />}

        {activeSection === 'notifications' && <NotificationChannels />}

        {activeSection === 'members' && <OrganizationMembers />}

        {activeSection === 'general' && <OrganizationGeneral />}

        {activeSection === 'trash' && <ServerTrash />}

        {activeSection === 'system' && (
          <div className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>System Updates</CardTitle>
                <CardDescription>
                  Keep ShipYard up to date with the latest features and fixes.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div>
                  {/* Version Info */}
                  <div className="settings-row border-b">
                    <div>
                      <p className="font-medium">Installed version</p>
                      <p className="text-sm text-muted-foreground">
                        {versionInfo?.version_source === 'fallback'
                          ? 'The VERSION file is not reachable from the container, so this is the built-in default.'
                          : 'Read from the checkout: version file, commit, and branch.'}
                      </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                      {checkingVersion && !versionInfo ? (
                        <LoadingSpinner size="sm" />
                      ) : (
                        <>
                          <Badge variant="outline" className="font-mono">v{versionInfo?.current_version || '…'}</Badge>
                          {versionInfo?.current_commit && (
                            <Badge variant="outline" className="font-mono" title={versionInfo.current_commit}>
                              {versionInfo.current_commit.slice(0, 7)}
                            </Badge>
                          )}
                          {versionInfo?.branch && (
                            <Badge variant={versionInfo.on_target_branch ? 'secondary' : 'destructive'} className="font-mono">
                              {versionInfo.branch}
                            </Badge>
                          )}
                        </>
                      )}
                    </div>
                  </div>

                  {/* Update Status */}
                  <div className="settings-row">
                    <div>
                      <p className="font-medium">Updates</p>
                      {!versionInfo ? (
                        <p className="text-sm text-muted-foreground">Checking…</p>
                      ) : !versionInfo.updater_available ? (
                        <p className="text-sm text-red-700 dark:text-red-400">
                          The update script is not reachable from the containers. Mount the checkout at /var/www/shipyard and recreate the containers (see README, Updating).
                        </p>
                      ) : !versionInfo.on_target_branch ? (
                        <p className="text-sm text-amber-700 dark:text-amber-400">
                          This checkout is on {versionInfo.branch}. The updater only runs on {versionInfo.target_branch}.
                        </p>
                      ) : versionInfo.comparison === 'unknown' ? (
                        <p className="text-sm text-amber-700 dark:text-amber-400">
                          Could not check {versionInfo.repo}: {versionInfo.check_error || 'no response'}.
                        </p>
                      ) : versionInfo.update_available ? (
                        <p className="text-sm text-emerald-700 dark:text-emerald-400">
                          Update available: v{versionInfo.latest_version}
                          {versionInfo.latest_commit && <> at <span className="font-mono">{versionInfo.latest_commit.slice(0, 7)}</span></>}
                          {' '}on {versionInfo.repo} {versionInfo.target_branch}.
                        </p>
                      ) : (
                        <p className="text-sm text-muted-foreground">
                          Up to date with {versionInfo.repo} {versionInfo.target_branch}. Checked {new Date(versionInfo.checked_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.
                        </p>
                      )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                      <Button variant="outline" onClick={() => checkVersion(true)} disabled={checkingVersion || updating}>
                        <ArrowPathIcon className={cn('h-4 w-4', checkingVersion && 'animate-spin')} />
                        Check for updates
                      </Button>
                      <Button
                        variant={versionInfo?.update_available ? 'default' : 'secondary'}
                        onClick={startUpdate}
                        disabled={updating || checkingVersion || !versionInfo?.updater_available || !versionInfo?.on_target_branch}
                      >
                        {updating ? (
                          <>
                            <LoadingSpinner size="sm" />
                            Updating…
                          </>
                        ) : versionInfo?.update_available ? 'Update now' : 'Run update anyway'}
                      </Button>
                    </div>
                  </div>

                  {/* Update Log */}
                  {updateStatus && (
                    <div className="mt-2 space-y-2 border-t pt-4">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                          <p className="text-sm font-medium">Update log</p>
                          <StatusBadge status={updateStatus.status} />
                          {updateStatus.exit_code !== null && updateStatus.status === 'failed' && (
                            <span className="text-xs tabular-nums text-muted-foreground">exit {updateStatus.exit_code}</span>
                          )}
                        </div>
                        {updateStatus.status === 'completed' && (
                          <Button size="sm" onClick={() => window.location.reload()}>Reload to use the new version</Button>
                        )}
                      </div>
                      {updateStatus.message && updateStatus.status === 'failed' && (
                        <p className="text-sm text-red-700 dark:text-red-400">{updateStatus.message}</p>
                      )}
                      <pre
                        ref={logRef}
                        className="max-h-72 overflow-auto whitespace-pre-wrap rounded-lg bg-muted p-4 font-mono text-xs"
                      >
                        {updateStatus.log || 'Waiting for output…'}
                      </pre>
                      <p className="text-xs text-muted-foreground">
                        The update runs in the queue container. Changes to container definitions need <span className="font-mono">docker compose up -d --build</span> on the host afterwards; the log says so when that applies.
                      </p>
                    </div>
                  )}
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>System Information</CardTitle>
                <CardDescription>
                  Technical details about your installation.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <div className="space-y-4 text-sm">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Application</span>
                    <span className="font-medium">ShipYard</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Version</span>
                    <span className="font-mono">{versionInfo?.current_version || '…'}{versionInfo?.current_commit ? ` (${versionInfo.current_commit.slice(0, 7)})` : ''}</span>
                  </div>
                  <div className="flex justify-between py-2">
                    <span className="text-muted-foreground">Documentation</span>
                    <a
                      href="https://github.com/rmattone/shipyard"
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-primary hover:underline"
                    >
                      View on GitHub
                    </a>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>
        )}
      </div>

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={deleteId !== null} onOpenChange={() => setDeleteId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Git Provider</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this git provider? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleting}
            >
              {deleting ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
