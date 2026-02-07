import { useEffect, useState, useCallback } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { applicationsApi, Application, Deployment } from '../../services/api'
import { toast } from 'sonner'
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

const avatarColors = [
  { bg: 'bg-emerald-100', text: 'text-emerald-600' },
  { bg: 'bg-blue-100', text: 'text-blue-600' },
  { bg: 'bg-purple-100', text: 'text-purple-600' },
  { bg: 'bg-orange-100', text: 'text-orange-600' },
  { bg: 'bg-pink-100', text: 'text-pink-600' },
  { bg: 'bg-cyan-100', text: 'text-cyan-600' },
]

function getAvatarColor(name: string) {
  let hash = 0
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash)
  }
  return avatarColors[Math.abs(hash) % avatarColors.length]
}

const statusColors: Record<string, { bg: string; text: string }> = {
  success: { bg: 'bg-emerald-100', text: 'text-emerald-600' },
  failed: { bg: 'bg-red-100', text: 'text-red-600' },
  running: { bg: 'bg-blue-100', text: 'text-blue-600' },
  pending: { bg: 'bg-yellow-100', text: 'text-yellow-600' },
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
  const navigate = useNavigate()
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
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <div className={`h-12 w-12 rounded-lg ${color.bg} ${color.text} flex items-center justify-center text-xl font-bold`}>
            {app.name.charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold">{app.name}</h1>
              <StatusBadge status={app.status} />
            </div>
            <p className="text-muted-foreground">{app.domain}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button onClick={handleDeploy} disabled={deploying}>
                {deploying ? (
                  <LoadingSpinner size="sm" className="mr-2" />
                ) : (
                  <RocketLaunchIcon className="h-4 w-4 mr-2" />
                )}
                Deploy
                <ChevronDownIcon className="h-4 w-4 ml-2" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={handleDeploy} disabled={deploying}>
                Deploy from {app.branch}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon">
                <EllipsisHorizontalIcon className="h-5 w-5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem asChild>
                <Link to={`/apps/${app.id}/domains`}>Domains</Link>
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
              <Button variant="ghost" size="sm" onClick={handleDeploy} disabled={deploying}>
                <RocketLaunchIcon className="h-4 w-4 mr-2" />
                Deploy
              </Button>
            </div>
            {deployments.length === 0 ? (
              <div className="p-8 text-center">
                <RocketLaunchIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <p className="text-muted-foreground mb-4">No deployments yet</p>
                <Button onClick={handleDeploy} disabled={deploying}>
                  <RocketLaunchIcon className="h-4 w-4 mr-2" />
                  Deploy Now
                </Button>
              </div>
            ) : (
              <div className="divide-y">
                {deployments.slice(0, 10).map((deployment) => {
                  const colors = statusColors[deployment.status] || { bg: 'bg-gray-100', text: 'text-gray-600' }
                  return (
                    <div
                      key={deployment.id}
                      className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                      onClick={() => navigate(`/apps/${appId}/deployments/${deployment.id}`)}
                    >
                      <div className={`h-10 w-10 rounded-full ${colors.bg} ${colors.text} flex items-center justify-center`}>
                        {getStatusIcon(deployment.status)}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="font-medium truncate">
                          {deployment.commit_message || 'Manual deployment'}
                        </div>
                        <div className="text-sm text-muted-foreground truncate">
                          #{deployment.id}
                          {deployment.commit_hash && (
                            <> · <span className="font-mono">{deployment.commit_hash.substring(0, 7)}</span></>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="text-sm text-muted-foreground">
                          {formatDistanceToNow(new Date(deployment.created_at), { addSuffix: true })}
                        </span>
                        <StatusBadge status={deployment.status} />
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                          <Button variant="ghost" size="icon">
                            <EllipsisHorizontalIcon className="h-5 w-5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/apps/${appId}/deployments/${deployment.id}`}>View Log</Link>
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
              <h2 className="font-semibold">Domains</h2>
              <Button variant="ghost" size="icon" asChild>
                <Link to={`/apps/${app.id}/domains`}>
                  <GlobeAltIcon className="h-4 w-4" />
                </Link>
              </Button>
            </div>
            <div className="divide-y">
              {app.domains && app.domains.length > 0 ? (
                app.domains.map((domain) => (
                  <div
                    key={domain.id}
                    className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                    onClick={() => navigate(`/apps/${app.id}/domains`)}
                  >
                    <div className="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                      {domain.ssl_enabled ? (
                        <LockClosedIcon className="h-5 w-5" />
                      ) : (
                        <GlobeAltIcon className="h-5 w-5" />
                      )}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="font-medium">{domain.domain}</div>
                      <div className="text-sm text-muted-foreground">
                        {domain.is_primary && 'Primary · '}
                        {domain.ssl_enabled ? 'SSL enabled' : 'No SSL'}
                      </div>
                    </div>
                  </div>
                ))
              ) : (
                <div
                  className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                  onClick={() => navigate(`/apps/${app.id}/domains`)}
                >
                  <div className="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                    <GlobeAltIcon className="h-5 w-5" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="font-medium">{app.domain}</div>
                    <div className="text-sm text-muted-foreground">
                      Primary · {app.ssl_enabled ? 'SSL enabled' : 'No SSL'}
                    </div>
                  </div>
                </div>
              )}
            </div>
          </Card>
        </div>

        {/* Right column - Details */}
        <div className="space-y-6">
          <Card className="p-6">
            <h3 className="font-semibold mb-4">Details</h3>
            <dl className="space-y-3">
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
                <dd className="font-medium">{app.branch}</dd>
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
            <dl className="space-y-3">
              <div>
                <dt className="text-muted-foreground text-sm">URL</dt>
                <dd className="font-mono text-sm break-all mt-1">{getRepoName(app.repository_url)}</dd>
              </div>
              <div>
                <dt className="text-muted-foreground text-sm">Deploy path</dt>
                <dd className="font-mono text-sm break-all mt-1">{app.deploy_path}</dd>
              </div>
            </dl>
          </Card>

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Deployments</h3>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Total</dt>
                <dd className="font-medium">{deployments.length}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Successful</dt>
                <dd className="font-medium text-emerald-600">{successfulDeploys}</dd>
              </div>
              {failedDeploys > 0 && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">Failed</dt>
                  <dd className="font-medium text-red-600">{failedDeploys}</dd>
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
