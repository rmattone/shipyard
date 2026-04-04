import { useState, useEffect, useRef } from 'react'
import { useParams } from 'react-router-dom'
import { toast } from 'sonner'
import {
  CheckCircleIcon,
  XCircleIcon,
  ArrowDownTrayIcon,
  ArrowPathIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { LoadingSpinner } from '@/components/custom'
import { serversApi, databasesApi, type Server, type ServerSoftware as ServerSoftwareType } from '@/services/api'

type InstallableEngine = 'mysql' | 'postgresql' | 'pm2' | 'php' | 'node'

const ENGINE_LABELS: Record<InstallableEngine, string> = {
  pm2: 'pm2',
  mysql: 'MySQL',
  postgresql: 'PostgreSQL',
  php: 'PHP',
  node: 'Node.js',
}

const ENGINE_DESCRIPTIONS: Record<InstallableEngine, string> = {
  pm2: 'Node.js process manager with built-in load balancer',
  mysql: 'Popular relational database server',
  postgresql: 'Advanced open-source relational database',
  php: 'PHP with PHP-FPM and Composer for Laravel',
  node: 'JavaScript runtime and package manager via NVM',
}

const ENGINE_COLORS: Record<InstallableEngine, string> = {
  pm2: 'bg-green-500/10 text-green-500',
  mysql: 'bg-blue-500/10 text-blue-500',
  postgresql: 'bg-indigo-500/10 text-indigo-500',
  php: 'bg-purple-500/10 text-purple-500',
  node: 'bg-amber-500/10 text-amber-500',
}

interface ComingSoonItem {
  name: string
  description: string
}

const COMING_SOON: ComingSoonItem[] = [
  { name: 'nginx', description: 'High-performance web server and reverse proxy' },
]

export default function ServerSoftware() {
  const { id } = useParams<{ id: string }>()
  const serverId = parseInt(id!)

  const [server, setServer] = useState<Server | null>(null)
  const [software, setSoftware] = useState<ServerSoftwareType | null>(null)
  const [loadingSoftware, setLoadingSoftware] = useState(true)
  const [loading, setLoading] = useState(true)

  // Install states
  const [showInstallDialog, setShowInstallDialog] = useState(false)
  const [installEngine, setInstallEngine] = useState<InstallableEngine>('pm2')
  const [installing, setInstalling] = useState(false)
  const [showInstallLogDialog, setShowInstallLogDialog] = useState(false)
  const [installLog, setInstallLog] = useState('')
  const [installStatus, setInstallStatus] = useState<'pending' | 'running' | 'success' | 'failed' | null>(null)
  const eventSourceRef = useRef<EventSource | null>(null)
  const logEndRef = useRef<HTMLDivElement>(null)

  // Node.js version states
  const [nodeVersions, setNodeVersions] = useState<string[]>([])
  const [selectedNodeVersion, setSelectedNodeVersion] = useState<string>('--lts')
  const [loadingVersions, setLoadingVersions] = useState(false)

  useEffect(() => {
    loadServer()
    loadSoftware()
  }, [serverId])

  // Auto-scroll log to bottom
  useEffect(() => {
    if (logEndRef.current) {
      logEndRef.current.scrollIntoView({ behavior: 'smooth' })
    }
  }, [installLog])

  // Cleanup EventSource on unmount
  useEffect(() => {
    return () => {
      if (eventSourceRef.current) {
        eventSourceRef.current.close()
      }
    }
  }, [])

  const loadServer = async () => {
    try {
      const res = await serversApi.get(serverId)
      setServer(res.data)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load server')
    } finally {
      setLoading(false)
    }
  }

  const loadSoftware = async () => {
    setLoadingSoftware(true)
    try {
      const res = await serversApi.checkSoftware(serverId)
      setSoftware(res.data)
    } catch {
      // Non-critical
    } finally {
      setLoadingSoftware(false)
    }
  }

  const openInstallDialog = async (engine: InstallableEngine) => {
    setInstallEngine(engine)
    setSelectedNodeVersion('--lts')

    if (engine === 'node') {
      setLoadingVersions(true)
      try {
        const res = await serversApi.getRemoteNodeVersions(serverId)
        setNodeVersions(res.data.versions)
      } catch {
        // Non-critical, we can still install LTS
        setNodeVersions([])
      } finally {
        setLoadingVersions(false)
      }
    }

    setShowInstallDialog(true)
  }

  const confirmInstall = async () => {
    setShowInstallDialog(false)
    setInstalling(true)
    setInstallLog('')
    setInstallStatus('pending')
    setShowInstallLogDialog(true)

    try {
      // For node engine, pass version if not LTS
      const version = installEngine === 'node' && selectedNodeVersion !== '--lts'
        ? selectedNodeVersion
        : undefined
      const response = await databasesApi.install(serverId, installEngine, version)
      const installationId = response.data.id

      // Connect to SSE stream
      const token = localStorage.getItem('token')
      const url = `/api/database-installations/${installationId}/stream?token=${token}`
      const es = new EventSource(url)
      eventSourceRef.current = es

      es.addEventListener('connected', (e) => {
        const data = JSON.parse(e.data)
        if (data.log) {
          setInstallLog(data.log)
        }
        setInstallStatus(data.status)
      })

      es.addEventListener('log', (e) => {
        const data = JSON.parse(e.data)
        setInstallLog(prev => prev + data.chunk)
      })

      es.addEventListener('complete', (e) => {
        const data = JSON.parse(e.data)
        setInstallStatus(data.status)
        es.close()
        eventSourceRef.current = null
        setInstalling(false)
        if (data.status === 'success') {
          toast.success(`${ENGINE_LABELS[installEngine]} installed successfully!`)
          loadSoftware()
        } else {
          toast.error(`${ENGINE_LABELS[installEngine]} installation failed.`)
        }
      })

      es.addEventListener('error', (e) => {
        try {
          const data = JSON.parse((e as MessageEvent).data)
          setInstallLog(prev => prev + '\n[ERROR] ' + data.message + '\n')
        } catch {
          // SSE connection error
        }
        setInstallStatus('failed')
        es.close()
        eventSourceRef.current = null
        setInstalling(false)
      })

      es.onerror = () => {
        if (eventSourceRef.current) {
          setInstallStatus('failed')
          es.close()
          eventSourceRef.current = null
          setInstalling(false)
        }
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to start installation')
      setInstalling(false)
      setShowInstallLogDialog(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) {
    return (
      <div className="text-center py-12">
        <p className="text-muted-foreground">Server not found</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Software</h1>
        <p className="text-muted-foreground">
          Detect and install software on {server.name}
        </p>
      </div>

      {/* Installed Software */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle className="text-lg">Installed Software</CardTitle>
              <CardDescription>Software detected on this server</CardDescription>
            </div>
            <Button variant="outline" size="sm" onClick={loadSoftware} disabled={loadingSoftware}>
              {loadingSoftware ? (
                <LoadingSpinner size="sm" />
              ) : (
                <>
                  <ArrowPathIcon className="h-4 w-4 mr-2" />
                  Refresh
                </>
              )}
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {loadingSoftware && !software ? (
            <div className="flex justify-center py-8">
              <LoadingSpinner size="md" />
            </div>
          ) : software ? (
            <div className="divide-y">
              {Object.entries(software).map(([name, info]) => (
                <div key={name} className="flex items-center justify-between py-3">
                  <div className="flex items-center gap-3">
                    {info.installed ? (
                      <CheckCircleIcon className="h-5 w-5 text-emerald-500" />
                    ) : (
                      <XCircleIcon className="h-5 w-5 text-muted-foreground" />
                    )}
                    <span className="font-medium capitalize">{name}</span>
                  </div>
                  <div>
                    {info.installed ? (
                      <span className="text-sm font-mono text-muted-foreground">{info.version}</span>
                    ) : (
                      <Badge variant="secondary">Not installed</Badge>
                    )}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground text-center py-8">
              Failed to detect software. Click Refresh to try again.
            </p>
          )}
        </CardContent>
      </Card>

      {/* Install Software */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Install Software</CardTitle>
          <CardDescription>Install software on this server</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {(Object.keys(ENGINE_LABELS) as InstallableEngine[]).map((engine) => (
              <div key={engine} className="flex items-center justify-between p-4 border rounded-lg">
                <div className="flex items-center gap-3">
                  <div className={`h-10 w-10 rounded-lg flex items-center justify-center ${ENGINE_COLORS[engine].split(' ')[0]}`}>
                    <span className={`text-sm font-bold ${ENGINE_COLORS[engine].split(' ')[1]}`}>
                      {engine === 'pm2' ? 'PM' : engine === 'mysql' ? 'My' : engine === 'php' ? 'PHP' : engine === 'node' ? 'Nd' : 'Pg'}
                    </span>
                  </div>
                  <div>
                    <p className="font-medium">{ENGINE_LABELS[engine]}</p>
                    <p className="text-sm text-muted-foreground">
                      {ENGINE_DESCRIPTIONS[engine]}
                    </p>
                  </div>
                </div>
                <Button
                  size="sm"
                  onClick={() => openInstallDialog(engine)}
                  disabled={installing}
                >
                  <ArrowDownTrayIcon className="h-4 w-4 mr-1" />
                  Install
                </Button>
              </div>
            ))}

            {/* Coming soon items */}
            {COMING_SOON.map((item) => (
              <div key={item.name} className="flex items-center justify-between p-4 border rounded-lg opacity-60">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-lg bg-muted flex items-center justify-center">
                    <span className="text-sm font-bold text-muted-foreground">
                      {item.name.slice(0, 2)}
                    </span>
                  </div>
                  <div>
                    <p className="font-medium">{item.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {item.description}
                    </p>
                  </div>
                </div>
                <Badge variant="outline">Coming soon</Badge>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* Install Confirmation Dialog */}
      <AlertDialog open={showInstallDialog} onOpenChange={setShowInstallDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Install {ENGINE_LABELS[installEngine]}
            </AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="space-y-4">
                {installEngine === 'pm2' ? (
                  <p>
                    This will install {ENGINE_LABELS[installEngine]} globally on {server.name} via npm.
                    Node.js and npm must already be installed. This operation requires Ubuntu or Debian.
                  </p>
                ) : installEngine === 'php' ? (
                  <p>
                    This will install the latest PHP version with PHP-FPM and Composer on {server.name}.
                    PHP extensions for MySQL, PostgreSQL, Redis, and common Laravel requirements will be included.
                    This operation requires Ubuntu or Debian.
                  </p>
                ) : installEngine === 'node' ? (
                  <>
                    <p>
                      This will install Node.js and npm on {server.name} via NVM (Node Version Manager).
                      This operation requires Ubuntu or Debian.
                    </p>
                    <div className="space-y-2">
                      <label className="text-sm font-medium text-foreground">Node.js Version</label>
                      {loadingVersions ? (
                        <div className="flex items-center gap-2 py-2">
                          <LoadingSpinner size="sm" />
                          <span className="text-sm text-muted-foreground">Loading versions...</span>
                        </div>
                      ) : (
                        <Select value={selectedNodeVersion} onValueChange={setSelectedNodeVersion}>
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select version" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="--lts">Latest LTS (Recommended)</SelectItem>
                            {nodeVersions.map((version) => (
                              <SelectItem key={version} value={version}>
                                v{version}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    </div>
                  </>
                ) : (
                  <p>
                    This will install {ENGINE_LABELS[installEngine]} on {server.name}.
                    The installation will run in the background and a root/admin password will be
                    auto-generated. This operation requires Ubuntu or Debian.
                  </p>
                )}
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={confirmInstall} disabled={loadingVersions}>
              Install
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Install Log Dialog */}
      <Dialog open={showInstallLogDialog} onOpenChange={(open) => {
        if (!open && !installing) {
          setShowInstallLogDialog(false)
        }
      }}>
        <DialogContent className="max-w-2xl max-h-[80vh]">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              Installing {ENGINE_LABELS[installEngine]}
              {installing && <LoadingSpinner size="sm" />}
              {installStatus === 'success' && (
                <Badge variant="default" className="bg-green-600">Success</Badge>
              )}
              {installStatus === 'failed' && (
                <Badge variant="destructive">Failed</Badge>
              )}
            </DialogTitle>
            <DialogDescription>
              Real-time installation output from the server.
            </DialogDescription>
          </DialogHeader>
          <div className="bg-black rounded-lg p-4 overflow-auto max-h-[50vh] font-mono text-sm text-green-400">
            <pre className="whitespace-pre-wrap break-words">
              {installLog || 'Waiting for output...'}
            </pre>
            <div ref={logEndRef} />
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setShowInstallLogDialog(false)}
              disabled={installing}
            >
              {installing ? 'Installing...' : 'Close'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
