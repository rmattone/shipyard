import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { LoadingSpinner } from '@/components/custom'
import { SchedulerPanel } from '@/components/SchedulerPanel'
import { serversApi, applicationsApi, type Server, type Application } from '@/services/api'

export default function ServerScheduler() {
  const { id } = useParams<{ id: string }>()
  const serverId = parseInt(id!)

  const [server, setServer] = useState<Server | null>(null)
  const [apps, setApps] = useState<Application[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const loadData = async () => {
      try {
        const [serverRes, appsRes] = await Promise.all([
          serversApi.get(serverId),
          applicationsApi.list(),
        ])
        setServer(serverRes.data)
        setApps(appsRes.data.filter(app => app.server_id === serverId))
      } catch (error: unknown) {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load data')
      } finally {
        setLoading(false)
      }
    }
    loadData()
  }, [serverId])

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) {
    return (
      <div className="text-center py-12">
        <p className="text-muted-foreground">Server not found</p>
      </div>
    )
  }

  return (
    <SchedulerPanel
      serverId={server.id}
      serverName={server.name}
      serverPhpVersion={server.php_version}
      apps={apps}
    />
  )
}
