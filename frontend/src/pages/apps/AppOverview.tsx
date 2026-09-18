import { useEffect, useState, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import { applicationsApi, Application, Deployment } from '../../services/api'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import {
  EllipsisHorizontalIcon,
  RocketLaunchIcon,
  ChevronDownIcon,
  CheckCircleIcon,
  XCircleIcon,
  ArrowPathIcon,
  GlobeAltIcon,
  LockClosedIcon,
} from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'
import { getAvatarColor } from '@/lib/utils'

const statusColors: Record<string, { bg: string; text: string }> = {
  success: { bg: 'bg-emerald-500/15', text: 'text-emerald-700 dark:text-emerald-400' },
  failed: { bg: 'bg-red-500/15', text: 'text-red-700 dark:text-red-400' },
  running: { bg: 'bg-primary/15', text: 'text-primary' },
  pending: { bg: 'bg-amber-500/15', text: 'text-amber-700 dark:text-amber-400' },
}

function getStatusIcon(status: string) {
  switch (status) {
    case 'success':
      return <CheckCircleIcon className="h-5 w-5" />
    case 'failed':
      return <XCircleIcon className="h-5 w-5" />
    case 'running':
    case 'pending':
      return <ArrowPathIcon className="h-5 w-5 animate-spin" />
    default:
      return null
  }
}

export default function AppOverview() {
  const { id } = useParams<{ id: string }>()
  const [app, setApp] = useState<Application | null>(null)
  const [deployments, setDeployments] = useState<Deployment[]>([])
  const [loading, setLoading] = useState(true)
  const [deploying, setDeploying] = useState(false)

  const appId = parseInt(id || '0')

  const fetchDeployments = useCallback(() => {
    if (appId) {
      applicationsApi.getDeployments(appId).then((res) => {
        setDeployments(res.data.data)
      })
    }
  }, [appId])

  useEffect(() => {
    if (appId) {
      Promise.all([
        applicationsApi.get(appId),
        applicationsApi.getDeployments(appId),
      ])
        .then(([appRes, deploymentsRes]) => {
          setApp(appRes.data)
          setDeployments(deploymentsRes.data.data)
        })
        .finally(() => setLoading(false))
    }
  }, [appId])

  useEffect(() => {
    const hasRunning = deployments.some((d) => d.status === 'running' || d.status === 'pending')
    if (hasRunning) {
      const interval = setInterval(fetchDeployments, 5000)
      return () => clearInterval(interval)
    }
  }, [deployments, fetchDeployments])

  const handleDeploy = async () => {
    if (!app) return
    setDeploying(true)
    try {
      await applicationsApi.deploy(app.id)
      toast.success('Deployment started')
      fetchDeployments()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to start deployment')
    } finally {
      setDeploying(false)
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

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!app) return null

  const color = getAvatarColor(app.name)
  const successfulDeploys = deployments.filter(d => d.status === 'success').length
  const failedDeploys = deployments.filter(d => d.status === 'failed').length
  const lastDeploy = deployments[0]

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="resource-header">
        <div className="flex items-center gap-4">
          <div className={`flex h-12 w-12 shrink-0 items-center justify-center rounded-xl ${color.bg} ${color.text} text-xl font-semibold`}>
            {app.name.charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="flex flex-wrap items-center gap-3">
              <h1 className="break-all text-2xl font-semibold tracking-tight">{app.name}</h1>
              <StatusBadge status={app.status} />
              {!app.git_provider_id && (
                <Badge variant="outline" className="text-amber-500 border-amber-500/40">
                  Git provider not connected
                </Badge>
              )}
            </div>
            <p className="font-mono text-sm text-muted-foreground">{app.domain}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/* Split button: the primary action deploys, only the chevron opens
              the menu. A single button doing both used to fire a deployment
              AND open the menu, allowing a second concurrent deployment. */}
          <div className="flex items-center">
            <Button onClick={handleDeploy} disabled={deploying} className="rounded-r-none">
              {deploying ? (
                <LoadingSpinner size="sm" className="mr-2" />
              ) : (
                <RocketLaunchIcon className="h-4 w-4 mr-2" />
              )}
              Deploy
            </Button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  disabled={deploying}
                  size="icon"
                  className="rounded-l-none border-l border-primary-foreground/20"
                  aria-label="Deploy options"
                >
                  <ChevronDownIcon className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={handleDeploy} disabled={deploying}>
                  Deploy from {app.branch}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" aria-label="Application actions">
                <EllipsisHorizontalIcon className="h-5 w-5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem asChild>
                <Link to={`/apps/${app.id}/domains`}>Domains & TLS</Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to={`/apps/${app.id}/settings`}>Settings</Link>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Main content */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left column - Deployments list */}
        <div className="lg:col-span-2 space-y-6">
          {/* Recent deployments */}
          <Card>
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="font-semibold">Recent deployments</h2>
              <Button variant="ghost" size="sm" asChild>
                <Link to={`/apps/${app.id}/deployments`}>View history</Link>
              </Button>
            </div>
            {deployments.length === 0 ? (
              <div className="p-8 text-center">
                <RocketLaunchIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <p className="text-muted-foreground mb-4">No deployments yet</p>
                <p className="text-sm text-muted-foreground">Use Deploy above to publish your first release.</p>
              </div>
            ) : (
              <div className="divide-y">
                {deployments.slice(0, 10).map((deployment) => {
                  const colors = statusColors[deployment.status] || { bg: 'bg-gray-100', text: 'text-gray-600' }
                  return (
                    <div
                      key={deployment.id}
                      className="row-link group flex items-center gap-4 p-4"
                    >
                      <div className={`h-10 w-10 shrink-0 rounded-xl ${colors.bg} ${colors.text} flex items-center justify-center`}>
                        {getStatusIcon(deployment.status)}
                      </div>
                      <div className="flex-1 min-w-0">
                        <Link to={`/apps/${appId}/deployments/${deployment.id}`} className="row-cover block truncate font-medium">
                          {deployment.commit_message || 'Manual deployment'}
                        </Link>
                        <div className="text-sm text-muted-foreground truncate">
                          #{deployment.id}
                          {deployment.commit_hash && (
                            <> · <span className="font-mono">{deployment.commit_hash.substring(0, 7)}</span></>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="hidden text-xs tabular-nums text-muted-foreground md:inline">
                          {formatDistanceToNow(new Date(deployment.created_at), { addSuffix: true })}
                        </span>
                        <StatusBadge status={deployment.status} />
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="row-action" aria-label={`Actions for deployment ${deployment.id}`}>
                            <EllipsisHorizontalIcon className="h-5 w-5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/apps/${appId}/deployments/${deployment.id}`}>View output</Link>
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  )
                })}
              </div>
            )}
          </Card>

          {/* Domains */}
          <Card>
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="font-semibold">Domains & TLS</h2>
              <Button variant="ghost" size="icon" asChild>
                <Link to={`/apps/${app.id}/domains`}>
                  <GlobeAltIcon className="h-4 w-4" />
                </Link>
              </Button>
            </div>
            <div className="divide-y">
              {app.domains && app.domains.length > 0 ? (
                app.domains.map((domain) => (
                  <Link
                    key={domain.id}
                    to={`/apps/${app.id}/domains`}
                    className="row-link flex items-center gap-4 p-4"
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                      {domain.ssl_enabled ? (
                        <LockClosedIcon className="h-5 w-5" />
                      ) : (
                        <GlobeAltIcon className="h-5 w-5" />
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="truncate font-medium">{domain.domain}</div>
                      <div className="text-sm text-muted-foreground">
                        {domain.is_primary && 'Primary · '}
                        {domain.ssl_enabled ? 'TLS enabled' : 'No TLS'}
                      </div>
                    </div>
                  </Link>
                ))
              ) : (
                <Link
                  to={`/apps/${app.id}/domains`}
                  className="row-link flex items-center gap-4 p-4"
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <GlobeAltIcon className="h-5 w-5" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="truncate font-medium">{app.domain}</div>
                    <div className="text-sm text-muted-foreground">
                      Primary · {app.ssl_enabled ? 'TLS enabled' : 'No TLS'}
                    </div>
                  </div>
                </Link>
              )}
            </div>
          </Card>
        </div>

        {/* Right column - Details */}
        <div className="space-y-6">
          <Card className="p-6">
            <h3 className="font-semibold mb-4">Details</h3>
            <dl className="space-y-3 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">ID</dt>
                <dd className="font-medium">{app.id}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Type</dt>
                <dd className="font-medium">{getTypeLabel(app.type)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Server</dt>
                <dd className="font-medium">
                  <Link to={`/servers/${app.server_id}`} className="text-primary hover:underline">
                    {app.server?.name || '-'}
                  </Link>
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Branch</dt>
                <dd className="font-mono text-sm font-medium">{app.branch}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Created</dt>
                <dd className="font-medium">
                  {new Date(app.created_at).toLocaleDateString('en-US', {
                    month: 'short',
                    day: '2-digit',
                    year: 'numeric',
                  })}
                </dd>
              </div>
            </dl>
          </Card>

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Repository</h3>
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-muted-foreground text-sm">URL</dt>
                <dd className="font-mono text-sm break-all mt-1">{app.repository_url ? getRepoName(app.repository_url) : 'Not connected'}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground text-sm">Deploy path</dt>
                <dd className="font-mono text-sm break-all mt-1">{app.deploy_path}</dd>
              </div>
            </dl>
          </Card>

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Deployments</h3>
            <dl className="space-y-3 text-sm">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Total</dt>
                <dd className="font-medium">{deployments.length}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Successful</dt>
                <dd className="font-medium tabular-nums text-emerald-700 dark:text-emerald-400">{successfulDeploys}</dd>
              </div>
              {failedDeploys > 0 && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Failed</dt>
                  <dd className="font-medium tabular-nums text-red-700 dark:text-red-400">{failedDeploys}</dd>
                </div>
              )}
              {lastDeploy && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Last deploy</dt>
                  <dd className="font-medium">
                    {formatDistanceToNow(new Date(lastDeploy.created_at), { addSuffix: true })}
                  </dd>
                </div>
              )}
            </dl>
          </Card>
        </div>
      </div>
    </div>
  )
}
