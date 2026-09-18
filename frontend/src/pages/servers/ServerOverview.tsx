import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { serversApi, applicationsApi, tagsApi, Server, Application, Deployment, Tag } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge, LoadingSpinner, ServerMetricsCard, ServerMetricsHistoryCard } from '@/components/custom'
import { TagBadge } from '@/components/custom/TagBadge'
import {
  PlusIcon,
  EllipsisHorizontalIcon,
  ServerIcon,
  CubeIcon,
  XMarkIcon,
  ArrowDownTrayIcon,
} from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'
import { getAvatarColor } from '@/lib/utils'

interface AppWithLastDeploy extends Application {
  last_deployment?: Deployment | null
}

export default function ServerOverview() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [server, setServer] = useState<Server | null>(null)
  const [applications, setApplications] = useState<AppWithLastDeploy[]>([])
  const [loading, setLoading] = useState(true)
  const [testing, setTesting] = useState(false)
  const [serverTags, setServerTags] = useState<Tag[]>([])
  const [selectedTagIds, setSelectedTagIds] = useState<number[]>([])
  const [importing, setImporting] = useState(false)

  const loadData = async () => {
    if (!id) return
    try {
      const [serverRes, appsRes, tagsRes] = await Promise.all([
        serversApi.get(parseInt(id)),
        applicationsApi.list(),
        tagsApi.list(parseInt(id)),
      ])

      setServer(serverRes.data)
      setServerTags(tagsRes.data)
      const serverApps = appsRes.data.filter(app => app.server_id === parseInt(id))

      // Load last deployment for each app
      const appsWithDeploys = await Promise.all(
        serverApps.map(async (app) => {
          try {
            const deploymentsRes = await applicationsApi.getDeployments(app.id)
            const lastDeploy = deploymentsRes.data.data[0] || null
            return { ...app, last_deployment: lastDeploy }
          } catch {
            return { ...app, last_deployment: null }
          }
        })
      )

      setApplications(appsWithDeploys)
    } catch {
      // Handle error
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
  }, [id])

  const handleImportApps = async () => {
    if (!server) return
    setImporting(true)

    try {
      const res = await applicationsApi.importFromServer(server.id)
      const imported = res.data.imported.length
      const skipped = res.data.skipped.length

      if (imported === 0) {
        toast.info(skipped > 0 ? `No new apps found (${skipped} skipped)` : 'No existing apps found on this server')
      } else {
        toast.success(`Imported ${imported} app${imported === 1 ? '' : 's'}${skipped > 0 ? ` (${skipped} skipped)` : ''}`)
        loadData()
      }

      const warnings = res.data.warnings ?? []
      if (warnings.length > 0) {
        toast.warning(
          `${warnings.length} app${warnings.length === 1 ? '' : 's'} outside the deploy user home`,
          { description: warnings.map((w) => w.path).join(', '), duration: 10000 }
        )
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to import apps')
    } finally {
      setImporting(false)
    }
  }

  const handleTestConnection = async () => {
    if (!server) return
    setTesting(true)

    try {
      const response = await serversApi.testConnection(server.id)
      if (response.data.success) {
        toast.success('Connection successful')
      } else {
        toast.error(response.data.message)
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Connection failed')
    } finally {
      setTesting(false)
    }
  }

  const getTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
      laravel: 'Laravel',
      nodejs: 'Node.js',
      static: 'Static',
    }
    return labels[type] || type
  }

  const getRepoName = (url: string) => {
    const match = url.match(/\/([^/]+)\/?$/)
    return match ? match[1] : url
  }

  const toggleTagFilter = (tagId: number) => {
    if (selectedTagIds.includes(tagId)) {
      setSelectedTagIds(selectedTagIds.filter(id => id !== tagId))
    } else {
      setSelectedTagIds([...selectedTagIds, tagId])
    }
  }

  const clearTagFilters = () => {
    setSelectedTagIds([])
  }

  // Filter apps based on selected tags (AND logic - must have ALL selected tags)
  const filteredApplications = selectedTagIds.length === 0
    ? applications
    : applications.filter(app => {
        const appTagIds = app.tags?.map(t => t.id) || []
        return selectedTagIds.every(tagId => appTagIds.includes(tagId))
      })

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) {
    return <div>Server not found</div>
  }

  const activeApps = filteredApplications.filter(a => a.status === 'active').length
  const failedApps = filteredApplications.filter(a => a.status === 'failed').length

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="resource-header">
        <div className="flex items-center gap-4">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-muted text-foreground">
            <ServerIcon className="h-6 w-6" />
          </div>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="break-all text-2xl font-semibold tracking-tight">{server.name}</h1>
              <StatusBadge status={server.status} label={server.status === 'active' ? 'Connected' : 'Inactive'} />
            </div>
            <p className="font-mono text-sm text-muted-foreground">{server.host}:{server.port}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={() => navigate(`/servers/${id}/apps/new`)}>
            <PlusIcon className="h-4 w-4" />
            New application
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" aria-label="Server actions">
                <EllipsisHorizontalIcon className="h-5 w-5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={handleTestConnection} disabled={testing}>
                {testing ? 'Testing connection…' : 'Test connection'}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => navigate(`/servers/${id}/settings`)}>
                Settings
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Main content */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left column - Apps list */}
        <div className="lg:col-span-2 space-y-6">
          {/* Tag Filter Bar */}
          {serverTags.length > 0 && (
            <div className="flex items-center gap-2 flex-wrap">
              <span className="text-sm text-muted-foreground mr-1">Filter by tag:</span>
              {serverTags.map((tag) => (
                <TagBadge
                  key={tag.id}
                  tag={tag}
                  onClick={() => toggleTagFilter(tag.id)}
                  selected={selectedTagIds.includes(tag.id)}
                />
              ))}
              {selectedTagIds.length > 0 && (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={clearTagFilters}
                  className="h-6 px-2 text-xs"
                >
                  <XMarkIcon className="h-3 w-3 mr-1" />
                  Clear
                </Button>
              )}
            </div>
          )}

          {/* Recent sites */}
          <Card>
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="font-semibold">
                Applications
                {selectedTagIds.length > 0 && (
                  <span className="ml-2 text-sm font-normal text-muted-foreground">
                    ({filteredApplications.length} of {applications.length})
                  </span>
                )}
              </h2>
              <div className="flex items-center gap-1">
                <Button variant="ghost" size="sm" onClick={handleImportApps} disabled={importing}>
                  {importing ? <LoadingSpinner size="sm" /> : <ArrowDownTrayIcon className="h-4 w-4 mr-1" />}
                  Import existing
                </Button>
                <Button variant="ghost" size="icon" onClick={() => navigate(`/servers/${id}/apps/new`)}>
                  <PlusIcon className="h-4 w-4" />
                </Button>
              </div>
            </div>
            {filteredApplications.length === 0 ? (
              <div className="p-8 text-center">
                <CubeIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                {selectedTagIds.length > 0 ? (
                  <>
                    <p className="text-muted-foreground mb-4">No applications match the selected tags</p>
                    <Button variant="outline" onClick={clearTagFilters}>
                      Clear filters
                    </Button>
                  </>
                ) : (
                  <>
                    <p className="text-muted-foreground mb-4">No applications on this server yet</p>
                    <Button onClick={() => navigate(`/servers/${id}/apps/new`)}>
                      <PlusIcon className="h-4 w-4 mr-2" />
                      Add your first application
                    </Button>
                  </>
                )}
              </div>
            ) : (
              <div className="divide-y">
                {filteredApplications.map((app) => {
                  const color = getAvatarColor(app.name)
                  return (
                    <div
                      key={app.id}
                      className="row-link group flex items-center gap-4 p-4"
                    >
                      <div className={`h-10 w-10 shrink-0 rounded-xl ${color.bg} ${color.text} flex items-center justify-center font-medium`}>
                        {app.name.charAt(0).toUpperCase()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <Link to={`/apps/${app.id}`} className="row-cover truncate font-medium">{app.domain || app.name}</Link>
                          {app.tags && app.tags.length > 0 && (
                            <div className="flex gap-1">
                              {app.tags.map((tag) => (
                                <TagBadge key={tag.id} tag={tag} className="text-xs py-0" />
                              ))}
                            </div>
                          )}
                        </div>
                        <div className="text-sm text-muted-foreground truncate">
                          {app.repository_url ? `${getRepoName(app.repository_url)}:${app.branch} · ` : ''}{getTypeLabel(app.type)}
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="hidden text-xs tabular-nums text-muted-foreground md:inline">
                          {app.last_deployment
                            ? `Deployed ${formatDistanceToNow(new Date(app.last_deployment.created_at), { addSuffix: false })} ago`
                            : 'Never deployed'}
                        </span>
                        {app.last_deployment?.status === 'failed' && (
                          <StatusBadge status="failed" label="Deploy failed" />
                        )}
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="row-action" aria-label={`Actions for ${app.name}`}>
                            <EllipsisHorizontalIcon className="h-5 w-5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/apps/${app.id}`}>View</Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem asChild>
                            <Link to={`/apps/${app.id}/deployments`}>Deployments</Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem asChild>
                            <Link to={`/apps/${app.id}/settings`}>Settings</Link>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  )
                })}
              </div>
            )}
          </Card>
        </div>

        {/* Right column - Details */}
        <div className="space-y-6">
          <ServerMetricsCard serverId={parseInt(id!)} autoRefresh={true} />

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Details</h3>
            <dl className="space-y-3 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">ID</dt>
                <dd className="font-medium">{server.id}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Host</dt>
                <dd className="font-medium font-mono text-sm">{server.host}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Port</dt>
                <dd className="font-medium">{server.port}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Username</dt>
                <dd className="font-medium">{server.username}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Status</dt>
                <dd><StatusBadge status={server.status} /></dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Created</dt>
                <dd className="font-medium">
                  {new Date(server.created_at).toLocaleDateString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric',
                  })}
                </dd>
              </div>
            </dl>
          </Card>

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Applications</h3>
            <dl className="space-y-3 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Total</dt>
                <dd className="font-medium">
                  {selectedTagIds.length > 0
                    ? `${filteredApplications.length} / ${applications.length}`
                    : applications.length}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Active</dt>
                <dd className="font-medium tabular-nums text-emerald-700 dark:text-emerald-400">{activeApps}</dd>
              </div>
              {failedApps > 0 && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Failed</dt>
                  <dd className="font-medium tabular-nums text-red-700 dark:text-red-400">{failedApps}</dd>
                </div>
              )}
            </dl>
          </Card>

        </div>
      </div>

      <ServerMetricsHistoryCard serverId={parseInt(id!)} />
    </div>
  )
}
