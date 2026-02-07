import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { serversApi, applicationsApi, Server, Application } from '../services/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import {
  ServerIcon,
  CubeIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
} from '@heroicons/react/24/outline'

export default function Dashboard() {
  const [servers, setServers] = useState<Server[]>([])
  const [applications, setApplications] = useState<Application[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    Promise.all([serversApi.list(), applicationsApi.list()])
      .then(([serversRes, appsRes]) => {
        setServers(serversRes.data)
        setApplications(appsRes.data)
      })
      .finally(() => setLoading(false))
  }, [])

  const activeServers = servers.filter((s) => s.status === 'active').length
  const activeApps = applications.filter((a) => a.status === 'active').length
  const failedApps = applications.filter((a) => a.status === 'failed').length

  const stats = [
    {
      name: 'Total Servers',
      value: servers.length,
      icon: ServerIcon,
      color: 'bg-blue-500',
    },
    {
      name: 'Active Servers',
      value: activeServers,
      icon: CheckCircleIcon,
      color: 'bg-green-500',
    },
    {
      name: 'Active Apps',
      value: activeApps,
      icon: CubeIcon,
      color: 'bg-indigo-500',
    },
    {
      name: 'Failed Apps',
      value: failedApps,
      icon: ExclamationCircleIcon,
      color: 'bg-red-500',
    },
  ]

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>

      <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
        {stats.map((stat) => (
          <Card key={stat.name}>
            <CardContent className="p-5">
              <div className="flex items-center">
                <div className={`flex-shrink-0 ${stat.color} rounded-md p-3`}>
                  <stat.icon className="h-6 w-6 text-white" />
                </div>
                <div className="ml-5">
                  <p className="text-sm font-medium text-muted-foreground">{stat.name}</p>
                  <p className="text-2xl font-semibold">{stat.value}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Recent Servers</CardTitle>
            <Link to="/servers" className="text-sm text-primary hover:underline">
              View all
            </Link>
          </CardHeader>
          <CardContent>
            {servers.length === 0 ? (
              <p className="text-muted-foreground">No servers yet</p>
            ) : (
              <ul className="divide-y divide-border">
                {servers.slice(0, 5).map((server) => (
                  <li key={server.id} className="py-3">
                    <Link
                      to={`/servers/${server.id}`}
                      className="flex items-center justify-between hover:bg-muted/50 -mx-4 px-4 py-2 rounded"
                    >
                      <div>
                        <p className="font-medium">{server.name}</p>
                        <p className="text-sm text-muted-foreground">{server.host}</p>
                      </div>
                      <StatusBadge status={server.status} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Recent Applications</CardTitle>
            <Link to="/apps" className="text-sm text-primary hover:underline">
              View all
            </Link>
          </CardHeader>
          <CardContent>
            {applications.length === 0 ? (
              <p className="text-muted-foreground">No applications yet</p>
            ) : (
              <ul className="divide-y divide-border">
                {applications.slice(0, 5).map((app) => (
                  <li key={app.id} className="py-3">
                    <Link
                      to={`/apps/${app.id}`}
                      className="flex items-center justify-between hover:bg-muted/50 -mx-4 px-4 py-2 rounded"
                    >
                      <div>
                        <p className="font-medium">{app.name}</p>
                        <p className="text-sm text-muted-foreground">{app.domain}</p>
                      </div>
                      <StatusBadge status={app.status} />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
