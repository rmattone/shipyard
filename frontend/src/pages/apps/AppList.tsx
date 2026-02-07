import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { applicationsApi, Application } from '../../services/api'
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

export default function AppList() {
  const navigate = useNavigate()
  const [applications, setApplications] = useState<Application[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    applicationsApi.list()
      .then((res) => setApplications(res.data))
      .finally(() => setLoading(false))
  }, [])

  const getTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
      laravel: 'Laravel',
      nodejs: 'Node.js',
      static: 'Static',
    }
    return labels[type] || type
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold">Applications</h1>
        <Link to="/apps/new">
          <Button>
            <PlusIcon className="h-5 w-5 mr-2" />
            Add Application
          </Button>
        </Link>
      </div>

      <Card>
        <CardContent className="p-0">
          {applications.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground mb-4">No applications configured yet</p>
              <Link to="/apps/new">
                <Button>Add your first application</Button>
              </Link>
            </div>
          ) : (
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
                        {app.domain} · {getTypeLabel(app.type)} · {app.server?.name || 'No server'}
                      </div>
                    </div>

                    {/* Right side - status */}
                    <div className="flex items-center gap-3">
                      <StatusBadge status={app.status} />
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
