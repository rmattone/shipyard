import { useEffect, useState, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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
import { PlusIcon, TrashIcon, ArrowPathIcon, CheckCircleIcon, ExclamationCircleIcon } from '@heroicons/react/24/outline'
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

type SettingsSection = 'source-control' | 'general' | 'system'

export default function Settings() {
  const navigate = useNavigate()
  const [providers, setProviders] = useState<GitProvider[]>([])
  const [loading, setLoading] = useState(true)
  const [deleteId, setDeleteId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState(false)
  const [activeSection, setActiveSection] = useState<SettingsSection>('source-control')

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

  // Load version info when switching to system section
  useEffect(() => {
    if (activeSection === 'system') {
      checkVersion()
    }
    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current)
      }
    }
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

  const checkVersion = async () => {
    setCheckingVersion(true)
    try {
      const response = await systemApi.getVersion()
      setVersionInfo(response.data)
    } catch {
      toast.error('Failed to check for updates')
    } finally {
      setCheckingVersion(false)
    }
  }

  const startUpdate = async () => {
    setUpdating(true)
    setUpdateStatus({ running: true, status: 'running', log: 'Starting update...\n' })

    try {
      await systemApi.startUpdate()

      // Start polling for status
      pollIntervalRef.current = setInterval(async () => {
        try {
          const response = await systemApi.getUpdateStatus()
          setUpdateStatus(response.data)

          // Auto-scroll log
          if (logRef.current) {
            logRef.current.scrollTop = logRef.current.scrollHeight
          }

          // Stop polling when complete
          if (response.data.status === 'completed' || response.data.status === 'failed') {
            if (pollIntervalRef.current) {
              clearInterval(pollIntervalRef.current)
            }
            setUpdating(false)

            if (response.data.status === 'completed') {
              toast.success('Update completed successfully!')
              checkVersion() // Refresh version info
            } else {
              toast.error('Update failed. Check the log for details.')
            }
          }
        } catch {
          // Ignore polling errors (app might be restarting)
        }
      }, 2000)
    } catch {
      toast.error('Failed to start update')
      setUpdating(false)
      setUpdateStatus(null)
    }
  }

  const sidebarItems = [
    { id: 'source-control' as const, label: 'Source Control' },
    { id: 'general' as const, label: 'General' },
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
    <div className="flex gap-8">
      {/* Sidebar */}
      <div className="w-48 flex-shrink-0">
        <h1 className="text-2xl font-bold mb-6">Settings</h1>
        <nav className="space-y-1">
          {sidebarItems.map((item) => (
            <button
              key={item.id}
              onClick={() => setActiveSection(item.id)}
              className={cn(
                'w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors',
                activeSection === item.id
                  ? 'bg-muted text-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
              )}
            >
              {item.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Content */}
      <div className="flex-1 max-w-3xl">
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

        {activeSection === 'general' && (
          <Card>
            <CardHeader>
              <CardTitle>General</CardTitle>
              <CardDescription>
                Organization settings and preferences.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="space-y-6">
                <div className="flex items-start justify-between py-4 border-b">
                  <div>
                    <p className="font-medium">Organization name</p>
                    <p className="text-sm text-muted-foreground">
                      The name of your organization.
                    </p>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    Coming soon...
                  </div>
                </div>

                <div className="flex items-start justify-between py-4">
                  <div>
                    <p className="font-medium">Organization avatar</p>
                    <p className="text-sm text-muted-foreground">
                      Your organization's profile picture.
                    </p>
                  </div>
                  <div className="text-sm text-muted-foreground">
                    Coming soon...
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

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
                <div className="space-y-6">
                  {/* Version Info */}
                  <div className="flex items-start justify-between py-4 border-b">
                    <div>
                      <p className="font-medium">Current Version</p>
                      <p className="text-sm text-muted-foreground">
                        The version of ShipYard you're running.
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      {checkingVersion ? (
                        <LoadingSpinner size="sm" />
                      ) : (
                        <>
                          <Badge variant="outline" className="font-mono">
                            v{versionInfo?.current_version || '...'}
                          </Badge>
                          <Button variant="ghost" size="sm" onClick={checkVersion}>
                            <ArrowPathIcon className="h-4 w-4" />
                          </Button>
                        </>
                      )}
                    </div>
                  </div>

                  {/* Update Status */}
                  <div className="flex items-start justify-between py-4">
                    <div>
                      <p className="font-medium">Updates</p>
                      {versionInfo?.update_available ? (
                        <p className="text-sm text-green-600">
                          New version available: v{versionInfo.latest_version}
                        </p>
                      ) : (
                        <p className="text-sm text-muted-foreground">
                          You're running the latest version.
                        </p>
                      )}
                    </div>
                    <Button
                      onClick={startUpdate}
                      disabled={updating || checkingVersion}
                    >
                      {updating ? (
                        <>
                          <LoadingSpinner size="sm" className="mr-2" />
                          Updating...
                        </>
                      ) : (
                        <>
                          <ArrowPathIcon className="h-4 w-4 mr-2" />
                          {versionInfo?.update_available ? 'Update Now' : 'Check & Update'}
                        </>
                      )}
                    </Button>
                  </div>

                  {/* Update Log */}
                  {updateStatus && (
                    <div className="space-y-2">
                      <div className="flex items-center gap-2">
                        <p className="font-medium text-sm">Update Log</p>
                        {updateStatus.status === 'completed' && (
                          <CheckCircleIcon className="h-4 w-4 text-green-600" />
                        )}
                        {updateStatus.status === 'failed' && (
                          <ExclamationCircleIcon className="h-4 w-4 text-red-600" />
                        )}
                      </div>
                      <pre
                        ref={logRef}
                        className="bg-muted p-4 rounded-lg text-xs font-mono overflow-auto max-h-64 whitespace-pre-wrap"
                      >
                        {updateStatus.log || 'Waiting for output...'}
                      </pre>
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
                    <span className="font-mono">{versionInfo?.current_version || '...'}</span>
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
