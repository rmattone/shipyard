import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { serversApi, Server } from '../services/api'
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
} from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'

const avatarColors = [
  { bg: 'bg-emerald-100 dark:bg-emerald-950', text: 'text-emerald-600 dark:text-emerald-400' },
  { bg: 'bg-blue-100 dark:bg-blue-950', text: 'text-blue-600 dark:text-blue-400' },
  { bg: 'bg-purple-100 dark:bg-purple-950', text: 'text-purple-600 dark:text-purple-400' },
  { bg: 'bg-orange-100 dark:bg-orange-950', text: 'text-orange-600 dark:text-orange-400' },
  { bg: 'bg-pink-100 dark:bg-pink-950', text: 'text-pink-600 dark:text-pink-400' },
  { bg: 'bg-cyan-100 dark:bg-cyan-950', text: 'text-cyan-600 dark:text-cyan-400' },
]

function getAvatarColor(name: string) {
  let hash = 0
  for (let i = 0; i < name.length; i++) {
    hash = name.charCodeAt(i) + ((hash << 5) - hash)
  }
  return avatarColors[Math.abs(hash) % avatarColors.length]
}

export default function Servers() {
  const navigate = useNavigate()
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

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Servers</h1>
        <Button onClick={() => navigate('/servers/new')}>
          <PlusIcon className="h-4 w-4 mr-2" />
          New server
        </Button>
      </div>

      {/* Servers list */}
      <Card>
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
  )
}
