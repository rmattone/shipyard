import { useEffect, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { serversApi, Server, getErrorMessage } from '../services/api'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Search, ChevronRight } from 'lucide-react'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { getAvatarColor } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { StatusBadge } from '@/components/custom'
import {
  PlusIcon,
  EllipsisHorizontalIcon,
  ServerIcon,
} from '@heroicons/react/24/outline'
import { formatDistanceToNow } from 'date-fns'

export default function Servers() {
  const navigate = useNavigate()
  const [servers, setServers] = useState<Server[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('all')

  const loadServers = () => {
    setLoading(true)
    setError(null)
    serversApi.list()
      .then((res) => setServers(res.data))
      .catch((err) => setError(getErrorMessage(err, 'Unable to load servers')))
      .finally(() => setLoading(false))
  }

  useEffect(() => { loadServers() }, [])

  const filteredServers = servers.filter(server =>
    `${server.name} ${server.host}`.toLowerCase().includes(query.toLowerCase()) &&
    (status === 'all' || server.status === status)
  )

  if (loading) {
    return (
      <div className="space-y-6" aria-label="Loading servers" role="status">
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-12 w-full rounded-xl" />
        {[0, 1, 2].map(row => <Skeleton key={row} className="h-20 w-full rounded-xl" />)}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="resource-header">
        <div>
          <p className="mb-2 text-xs font-medium uppercase tracking-widest text-muted-foreground">Infrastructure</p>
          <h1 className="text-2xl font-semibold tracking-tight">Servers</h1>
          <p className="mt-1 text-sm text-muted-foreground">Your infrastructure, in one place.</p>
        </div>
        <Button onClick={() => navigate('/servers/new')}>
          <PlusIcon className="h-4 w-4 mr-2" />
          New server
        </Button>
      </div>

      {error ? (
        <Card className="p-6" role="alert">
          <p className="font-medium">Couldn't load your servers</p>
          <p className="mt-1 text-sm text-muted-foreground">{error}</p>
          <Button variant="outline" className="mt-4" onClick={loadServers}>Try again</Button>
        </Card>
      ) : <>
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-0 flex-1 sm:max-w-sm">
          <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
          <Input aria-label="Search servers" placeholder="Search by name or address…" value={query} onChange={event => setQuery(event.target.value)} className="bg-card pl-9" />
        </div>
        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger aria-label="Filter by server status" className="w-auto min-w-[9.5rem] gap-2">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {Array.from(new Set(servers.map(server => server.status))).map(value => (
              <SelectItem key={value} value={value} className="capitalize">{value}</SelectItem>
            ))}
          </SelectContent>
        </Select>
        <span className="text-xs tabular-nums text-muted-foreground" aria-live="polite">{filteredServers.length} of {servers.length} servers</span>
      </div>
      {/* Servers list */}
      <Card className="overflow-hidden">
        {servers.length === 0 ? (
          <div className="p-8 text-center">
            <ServerIcon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <p className="text-muted-foreground mb-4">No servers configured yet</p>
            <Button onClick={() => navigate('/servers/new')}>
              <PlusIcon className="h-4 w-4 mr-2" />
              Add your first server
            </Button>
          </div>
        ) : filteredServers.length === 0 ? (
          <div className="p-10 text-center">
            <p className="font-medium">No matching servers</p>
            <p className="mt-1 text-sm text-muted-foreground">Try a different name, address, or status.</p>
            <Button variant="outline" className="mt-4" onClick={() => { setQuery(''); setStatus('all') }}>Clear filters</Button>
          </div>
        ) : (
          <div className="divide-y">
            {filteredServers.map((server) => {
              const color = getAvatarColor(server.name)
              return (
                <div
                  key={server.id}
                  className="row-link group flex items-center gap-3 p-4 sm:gap-4 sm:p-5"
                >
                  <div className={`hidden h-10 w-10 shrink-0 rounded-xl ${color.bg} ${color.text} sm:flex items-center justify-center font-medium`}>
                    <ServerIcon className="h-5 w-5" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <Link to={`/servers/${server.id}`} className="row-cover block truncate font-medium">{server.name}</Link>
                    <div className="mt-1 truncate font-mono text-xs text-muted-foreground">
                      {server.host}:{server.port}
                    </div>
                    <p className="mt-1 text-xs tabular-nums text-muted-foreground">{server.applications_count || 0} {server.applications_count === 1 ? 'application' : 'applications'}</p>
                  </div>
                  <div className="flex items-center gap-3">
                    <span className="hidden text-xs tabular-nums text-muted-foreground xl:inline">
                      Added {formatDistanceToNow(new Date(server.created_at), { addSuffix: false })} ago
                    </span>
                    <StatusBadge status={server.status} />
                  </div>
                  <ChevronRight className="row-hint hidden h-4 w-4 sm:block" aria-hidden />
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="row-action" aria-label={`Actions for ${server.name}`}>
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
      </>}
    </div>
  )
}
