import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { serversApi, Server } from '../services/api'
import { useAuth } from '../hooks/useAuth'
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
  PlusIcon,
  EllipsisHorizontalIcon,
  ServerIcon,
  ChevronDownIcon,
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

export default function OrganizationOverview() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const [servers, setServers] = useState<Server[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    serversApi.list()
      .then((res) => setServers(res.data))
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  const totalApps = servers.reduce((sum, s) => sum + (s.applications_count || 0), 0)
  const activeServers = servers.filter(s => s.status === 'active').length

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <div className="h-12 w-12 rounded-lg bg-orange-500 flex items-center justify-center text-xl font-bold text-white">
            O
          </div>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold">Organization</h1>
              <StatusBadge status="active" />
            </div>
            <p className="text-muted-foreground">Personal workspace</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button>
                <PlusIcon className="h-4 w-4 mr-2" />
                New server
                <ChevronDownIcon className="h-4 w-4 ml-2" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => navigate('/servers/new')}>
                Add Server
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
              <DropdownMenuItem onClick={() => navigate('/settings')}>
                Settings
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Main content */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left column - Servers list */}
        <div className="lg:col-span-2 space-y-6">
          {/* Servers */}
          <Card>
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="font-semibold">Servers</h2>
              <Button variant="ghost" size="icon" onClick={() => navigate('/servers/new')}>
                <PlusIcon className="h-4 w-4" />
              </Button>
            </div>
            {servers.length === 0 ? (
              <div className="p-8 text-center">
                <ServerIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <p className="text-muted-foreground mb-4">No servers configured yet</p>
                <Button onClick={() => navigate('/servers/new')}>
                  <PlusIcon className="h-4 w-4 mr-2" />
                  Add your first server
                </Button>
              </div>
            ) : (
              <div className="divide-y">
                {servers.map((server) => {
                  const color = getAvatarColor(server.name)
                  return (
                    <div
                      key={server.id}
                      className="flex items-center gap-4 p-4 hover:bg-muted/50 cursor-pointer"
                      onClick={() => navigate(`/servers/${server.id}`)}
                    >
                      <div className={`h-10 w-10 rounded-full ${color.bg} ${color.text} flex items-center justify-center font-medium`}>
                        {server.name.charAt(0).toUpperCase()}
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="font-medium">{server.name}</div>
                        <div className="text-sm text-muted-foreground truncate">
                          {server.host}:{server.port} · {server.applications_count || 0} apps
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className="text-sm text-muted-foreground">
                          Added {formatDistanceToNow(new Date(server.created_at), { addSuffix: false })} ago
                        </span>
                        <StatusBadge status={server.status} />
                      </div>
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                          <Button variant="ghost" size="icon">
                            <EllipsisHorizontalIcon className="h-5 w-5" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem asChild>
                            <Link to={`/servers/${server.id}`}>View</Link>
                          </DropdownMenuItem>
                          <DropdownMenuItem asChild>
                            <Link to={`/servers/${server.id}/settings`}>Settings</Link>
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
          <Card className="p-6">
            <h3 className="font-semibold mb-4">Details</h3>
            <dl className="space-y-3">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Owner</dt>
                <dd className="font-medium">{user?.email?.split('@')[0] || 'User'}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Servers</dt>
                <dd className="font-medium">{servers.length}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Active</dt>
                <dd className="font-medium">{activeServers}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted-foreground">Applications</dt>
                <dd className="font-medium">{totalApps}</dd>
              </div>
            </dl>
          </Card>

          <Card className="p-6">
            <h3 className="font-semibold mb-4">Quick Actions</h3>
            <div className="space-y-2">
              <Button variant="outline" className="w-full justify-start" onClick={() => navigate('/servers/new')}>
                <PlusIcon className="h-4 w-4 mr-2" />
                Add Server
              </Button>
              <Button variant="outline" className="w-full justify-start" onClick={() => navigate('/settings')}>
                <ServerIcon className="h-4 w-4 mr-2" />
                Settings
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
