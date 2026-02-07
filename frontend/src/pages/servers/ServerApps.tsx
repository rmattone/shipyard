import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { applicationsApi, Application, Deployment } from '../../services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { PlusIcon, EllipsisHorizontalIcon } from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'

interface AppWithLastDeploy extends Application {
  last_deployment?: Deployment | null
}

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

export default function ServerApps() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [applications, setApplications] = useState<AppWithLastDeploy[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!id) return

    const loadApps = async () => {
      try {
        const response = await applicationsApi.list()
        const serverApps = response.data.filter(app => app.server_id === parseInt(id))

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

    loadApps()
  }, [id])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
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
    // Extract repo name from URL like "https://github.com/user/repo"
    const match = url.match(/\/([^/]+)\/?$/)
    return match ? match[1] : url
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Applications</h1>
          <p className="text-muted-foreground">
            {applications.length} application{applications.length !== 1 ? 's' : ''} on this server
          </p>
        </div>
        <Button onClick={() => navigate(`/servers/${id}/apps/new`)}>
          <PlusIcon className="h-4 w-4 mr-2" />
          Add Application
        </Button>
      </div>

      {applications.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center">
            <p className="text-muted-foreground mb-4">No applications on this server yet.</p>
            <Button onClick={() => navigate(`/servers/${id}/apps/new`)}>
              <PlusIcon className="h-4 w-4 mr-2" />
              Add Your First Application
            </Button>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <div className="divide-y">
            {applications.map((app) => {
              const color = getAvatarColor(app.name)
              return (
                <div
                  key={app.id}
                  className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                  onClick={() => navigate(`/apps/${app.id}`)}
                >
                  {/* Avatar */}
                  <div className={`h-10 w-10 rounded-full ${color.bg} ${color.text} flex items-center justify-center font-medium text-sm`}>
                    {app.name.charAt(0).toUpperCase()}
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="font-medium">{app.name}</div>
                    <div className="text-sm text-muted-foreground truncate">
                      {getRepoName(app.repository_url)}:{app.branch} · {getTypeLabel(app.type)}
                    </div>
                  </div>

                  {/* Right side - status/time */}
                  <div className="flex items-center gap-3">
                    <span className="text-sm text-muted-foreground">
                      {app.last_deployment
                        ? `Deployed ${formatDistanceToNow(new Date(app.last_deployment.created_at), { addSuffix: false })} ago`
                        : 'Never deployed'}
                    </span>
                    {app.last_deployment?.status === 'failed' && (
                      <StatusBadge status="failed" />
                    )}
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
        </Card>
      )}
    </div>
  )
}
