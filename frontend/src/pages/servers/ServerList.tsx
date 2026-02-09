import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { serversApi, Server } from '../../services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { Badge } from '@/components/ui/badge'
import { PlusIcon } from '@heroicons/react/24/outline'

export default function ServerList() {
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
      <div className="flex justify-between items-center">
        <h1 className="text-2xl font-bold">Servers</h1>
        <Link to="/servers/new">
          <Button>
            <PlusIcon className="h-5 w-5 mr-2" />
            Add Server
          </Button>
        </Link>
      </div>

      <Card>
        <CardContent className="p-0">
          {servers.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground mb-4">No servers configured yet</p>
              <Link to="/servers/new">
                <Button>Add your first server</Button>
              </Link>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Host</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Applications</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {servers.map((server) => (
                  <TableRow key={server.id}>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/servers/${server.id}`}
                          className="text-primary hover:underline font-medium"
                        >
                          {server.name}
                        </Link>
                        {server.is_local && (
                          <Badge variant="secondary" className="text-xs">Local</Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {server.is_local ? 'localhost' : `${server.host}:${server.port}`}
                    </TableCell>
                    <TableCell>
                      <StatusBadge status={server.status} />
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {server.applications_count || 0}
                    </TableCell>
                    <TableCell className="text-right">
                      <Link
                        to={`/servers/${server.id}`}
                        className="text-primary hover:underline"
                      >
                        View
                      </Link>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  )
}
