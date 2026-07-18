import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { LoadingSpinner } from '@/components/custom'
import { DaemonsPanel } from '@/components/DaemonsPanel'
import { applicationsApi, type Application } from '@/services/api'

export default function AppDaemons() {
  const { id } = useParams<{ id: string }>()
  const appId = parseInt(id!)

  const [app, setApp] = useState<Application | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    applicationsApi.get(appId)
      .then(response => setApp(response.data))
      .catch((error: unknown) => {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load application')
      })
      .finally(() => setLoading(false))
  }, [appId])

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!app) {
    return (
      <div className="text-center py-12">
        <p className="text-muted-foreground">Application not found</p>
      </div>
    )
  }

  return (
    <DaemonsPanel
      serverId={app.server_id}
      serverName={app.server?.name ?? `server #${app.server_id}`}
      serverPhpVersion={app.server?.php_version}
      application={app}
    />
  )
}
