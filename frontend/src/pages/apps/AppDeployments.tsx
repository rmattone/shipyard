import { useEffect, useState, useCallback } from 'react'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { applicationsApi, Application, Deployment } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { RocketLaunchIcon, EllipsisHorizontalIcon, CheckCircleIcon, XCircleIcon, ArrowPathIcon } from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'

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

export default function AppDeployments() {
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

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!app) return null

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Deployments</h1>
          <p className="text-muted-foreground">
            {deployments.length} deployment{deployments.length !== 1 ? 's' : ''} for {app.name}
          </p>
        </div>
        <Button onClick={handleDeploy} disabled={deploying}>
          {deploying ? (
            <LoadingSpinner size="sm" className="mr-2" />
          ) : (
            <RocketLaunchIcon className="h-4 w-4 mr-2" />
          )}
          Deploy
        </Button>
      </div>

      <Card>
        <CardContent className="p-0">
          {deployments.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground mb-4">No deployments yet</p>
              <Button onClick={handleDeploy} disabled={deploying}>
                <RocketLaunchIcon className="h-4 w-4 mr-2" />
                Deploy Now
              </Button>
            </div>
          ) : (
            <div className="divide-y">
              {deployments.map((deployment) => {
                const colors = statusColors[deployment.status] || { bg: 'bg-gray-100', text: 'text-gray-600' }
                return (
                  <div
                    key={deployment.id}
                    className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                    onClick={() => navigate(`/apps/${appId}/deployments/${deployment.id}`)}
                  >
                    {/* Avatar with status icon */}
                    <div className={`h-10 w-10 rounded-full ${colors.bg} ${colors.text} flex items-center justify-center`}>
                      {getStatusIcon(deployment.status)}
                    </div>

                    {/* Content */}
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

                    {/* Right side - time and status */}
                    <div className="flex items-center gap-3">
                      <span className="text-sm text-muted-foreground">
                        {formatDistanceToNow(new Date(deployment.created_at), { addSuffix: true })}
                      </span>
                      <StatusBadge status={deployment.status} />
                    </div>

                    {/* Actions */}
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
        </CardContent>
      </Card>
    </div>
  )
}
