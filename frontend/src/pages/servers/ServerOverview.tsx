import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { serversApi, applicationsApi, tagsApi, Server, Application, Deployment, Tag } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge, LoadingSpinner, ServerMetricsCard, ServerMetricsHistoryCard } from '@/components/custom'
import type { MetricKey } from '@/components/custom/ServerMetricsCard'
import { TagBadge } from '@/components/custom/TagBadge'
import {
  PlusIcon,
  EllipsisHorizontalIcon,
  ServerIcon,
  CubeIcon,
  XMarkIcon,
  ArrowDownTrayIcon,
  CheckCircleIcon,
  XCircleIcon,
  SignalIcon,
} from '@heroicons/react/24/outline'
import { formatDistanceToNow, format } from 'date-fns'
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
  const [connection, setConnection] = useState<{ ok: boolean; message: string; at: Date } | null>(null)
  // Shared between the vitals strip and the history chart: tap a vital, see its trend.
  const [metric, setMetric] = useState<MetricKey>('cpu')

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
      setConnection({
        ok: response.data.success,
        message: response.data.success ? 'Connected' : (response.data.message || 'Connection failed'),
        at: new Date(),
      })
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      setConnection({ ok: false, message: err.response?.data?.message || 'Connection failed', at: new Date() })
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
      <div className="space-y-6" role="status" aria-label="Loading server">
        <Skeleton className="h-[104px] w-full rounded-xl" />
        <Skeleton className="h-[108px] w-full rounded-xl" />
        <Skeleton className="h-56 w-full rounded-xl" />
        <Skeleton className="h-72 w-full rounded-xl" />
      </div>
    )
  }

  if (!server) {
    return (
      <Card className="mx-auto max-w-md p-8 text-center">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-muted text-muted-foreground">
          <ServerIcon className="h-6 w-6" />
        </div>
        <h1 className="text-lg font-semibold">Server not found</h1>
        <p className="mt-1 text-sm text-muted-foreground">It may have been moved to the trash or belong to another organization.</p>
        <Button variant="outline" className="mt-5" asChild>
          <Link to="/">Back to servers</Link>
        </Button>
      </Card>
    )
  }

  const activeApps = filteredApplications.filter(a => a.status === 'active').length
  const failedApps = filteredApplications.filter(a => a.status === 'failed').length

  const addressLine = server.is_local
    ? 'Local execution'
    : `${server.username}@${server.host}:${server.port}`

  return (
    <div className="space-y-6">
      {/* Identity: who this server is, whether we can reach it, what to do next */}
      <div className="resource-header">
        <div className="flex min-w-0 items-center gap-4">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-muted text-foreground">
            <ServerIcon className="h-6 w-6" />
          </div>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="break-all text-2xl font-semibold tracking-tight">{server.name}</h1>
              <StatusBadge status={server.status} label={server.status === 'active' ? 'Connected' : 'Inactive'} />
            </div>
            <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
              <span className="font-mono">{addressLine}</span>
              <span aria-hidden>·</span>
              <span>Added {format(new Date(server.created_at), 'MMM d, yyyy')}</span>
            </p>
            {connection && (
              <p
                role="status"
                className={`mt-1 flex items-center gap-1.5 text-xs animate-in fade-in-0 slide-in-from-top-1 duration-200 ${connection.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'}`}
              >
                {connection.ok ? <CheckCircleIcon className="h-3.5 w-3.5" /> : <XCircleIcon className="h-3.5 w-3.5" />}
                <span>{connection.message}</span>
                <span className="tabular-nums text-muted-foreground">· {connection.at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</span>
              </p>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {!server.is_local && (
            <Button variant="outline" onClick={handleTestConnection} disabled={testing}>
              {testing ? <LoadingSpinner size="sm" /> : <SignalIcon className="h-4 w-4" />}
              {testing ? 'Testing…' : 'Test connection'}
            </Button>
          )}
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
              <DropdownMenuItem onClick={handleImportApps} disabled={importing}>
                {importing ? 'Importing…' : 'Import existing applications'}
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to={`/servers/${id}/terminal`}>Open terminal</Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to={`/servers/${id}/settings`}>Settings</Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Vitals: the four numbers that answer "is this box healthy right now" */}
      <ServerMetricsCard serverId={parseInt(id!)} autoRefresh={true} selected={metric} onSelect={setMetric} />

      {/* Applications: what this server is for */}
      <Card className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 className="font-semibold">Applications</h2>
            <p className="text-xs tabular-nums text-muted-foreground">
              {selectedTagIds.length > 0 ? `${filteredApplications.length} of ${applications.length}` : applications.length}
              {' '}{applications.length === 1 ? 'application' : 'applications'}
              {activeApps > 0 && <> · <span className="text-emerald-700 dark:text-emerald-400">{activeApps} active</span></>}
              {failedApps > 0 && <> · <span className="text-red-700 dark:text-red-400">{failedApps} failed</span></>}
            </p>
          </div>
          <div className="flex items-center gap-1">
            <Button variant="ghost" size="sm" onClick={handleImportApps} disabled={importing}>
              {importing ? <LoadingSpinner size="sm" /> : <ArrowDownTrayIcon className="h-4 w-4" />}
              Import existing
            </Button>
            <Button variant="ghost" size="sm" onClick={() => navigate(`/servers/${id}/apps/new`)}>
              <PlusIcon className="h-4 w-4" />
              New
            </Button>
          </div>
        </div>

        {serverTags.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-2.5">
            <span className="mr-1 text-xs text-muted-foreground">Filter by tag</span>
            {serverTags.map((tag) => (
              <TagBadge
                key={tag.id}
                tag={tag}
                onClick={() => toggleTagFilter(tag.id)}
                selected={selectedTagIds.includes(tag.id)}
              />
            ))}
            {selectedTagIds.length > 0 && (
              <Button variant="ghost" size="sm" onClick={clearTagFilters} className="h-6 px-2 text-xs">
                <XMarkIcon className="h-3 w-3" />
                Clear
              </Button>
            )}
          </div>
        )}

        {filteredApplications.length === 0 ? (
          <div className="p-10 text-center">
            <CubeIcon className="mx-auto mb-4 h-12 w-12 text-muted-foreground" />
            {selectedTagIds.length > 0 ? (
              <>
                <p className="font-medium">No applications match the selected tags</p>
                <Button variant="outline" className="mt-4" onClick={clearTagFilters}>
                  Clear filters
                </Button>
              </>
            ) : (
              <>
                <p className="font-medium">Nothing deployed here yet</p>
                <p className="mt-1 text-sm text-muted-foreground">Create an application, or import ones that already live on this server.</p>
                <div className="mt-4 flex flex-wrap justify-center gap-2">
                  <Button onClick={() => navigate(`/servers/${id}/apps/new`)}>
                    <PlusIcon className="h-4 w-4" />
                    New application
                  </Button>
                  <Button variant="outline" onClick={handleImportApps} disabled={importing}>
                    {importing ? <LoadingSpinner size="sm" /> : <ArrowDownTrayIcon className="h-4 w-4" />}
                    Import existing
                  </Button>
                </div>
              </>
            )}
          </div>
        ) : (
          <div className="divide-y">
            {filteredApplications.map((app) => {
              const color = getAvatarColor(app.name)
              return (
                <div key={app.id} className="row-link group flex items-center gap-4 p-4">
                  <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl font-medium ${color.bg} ${color.text}`}>
                    {app.name.charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/apps/${app.id}`} className="row-cover truncate font-medium">{app.domain || app.name}</Link>
                      {app.tags && app.tags.length > 0 && (
                        <div className="flex gap-1">
                          {app.tags.map((tag) => (
                            <TagBadge key={tag.id} tag={tag} className="py-0 text-xs" />
                          ))}
                        </div>
                      )}
                    </div>
                    <div className="mt-0.5 truncate text-xs text-muted-foreground">
                      {app.repository_url ? <><span className="font-mono">{getRepoName(app.repository_url)}:{app.branch}</span> · </> : ''}{getTypeLabel(app.type)}
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="hidden text-xs tabular-nums text-muted-foreground md:inline">
                      {app.last_deployment
                        ? `Deployed ${formatDistanceToNow(new Date(app.last_deployment.created_at), { addSuffix: false })} ago`
                        : 'Never deployed'}
                    </span>
                    {app.last_deployment?.status === 'failed' ? (
                      <StatusBadge status="failed" label="Deploy failed" />
                    ) : app.status !== 'active' ? (
                      <StatusBadge status={app.status} />
                    ) : null}
                  </div>
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="row-action" aria-label={`Actions for ${app.name}`}>
                        <EllipsisHorizontalIcon className="h-5 w-5" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem asChild>
                        <Link to={`/apps/${app.id}`}>Overview</Link>
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

      {/* Trend for the selected vital */}
      <ServerMetricsHistoryCard serverId={parseInt(id!)} metric={metric} onMetricChange={setMetric} />
    </div>
  )
}
