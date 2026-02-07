import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { applicationsApi, Application, Deployment, Domain } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { StatusBadge, LoadingSpinner } from '@/components/custom'

export default function AppDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [app, setApp] = useState<Application & { deployments?: Deployment[], domains?: Domain[] } | null>(null)
  const [loading, setLoading] = useState(true)
  const [deploying, setDeploying] = useState(false)
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    if (id) {
      applicationsApi.get(parseInt(id))
        .then((res) => setApp(res.data))
        .finally(() => setLoading(false))
    }
  }, [id])

  const handleDeploy = async () => {
    if (!app) return
    setDeploying(true)

    try {
      const response = await applicationsApi.deploy(app.id)
      toast.success('Deployment started')
      navigate(`/apps/${app.id}/deployments/${response.data.deployment_id}`)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to start deployment')
      setDeploying(false)
    }
  }

  const handleDelete = async () => {
    if (!app || !confirm('Are you sure you want to delete this application?')) return
    setDeleting(true)

    try {
      await applicationsApi.delete(app.id)
      toast.success('Application deleted')
      navigate('/apps')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete')
    } finally {
      setDeleting(false)
    }
  }

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

  if (!app) {
    return <div>Application not found</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">{app.name}</h1>
          <p className="text-muted-foreground">{app.domain}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleDeploy} disabled={deploying}>
            {deploying && <LoadingSpinner size="sm" className="mr-2" />}
            Deploy Now
          </Button>
          <Link to={`/apps/${app.id}/domains`}>
            <Button variant="outline">Domains</Button>
          </Link>
          <Link to={`/apps/${app.id}/env`}>
            <Button variant="outline">Environment</Button>
          </Link>
          <Link to={`/apps/${app.id}/settings`}>
            <Button variant="outline">Settings</Button>
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Application Details</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="space-y-4">
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Status</dt>
                <dd className="mt-1">
                  <StatusBadge status={app.status} />
                </dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Type</dt>
                <dd className="mt-1">{getTypeLabel(app.type)}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Server</dt>
                <dd className="mt-1">
                  <Link to={`/servers/${app.server_id}`} className="text-primary hover:underline">
                    {app.server?.name || '-'}
                  </Link>
                </dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Deploy Path</dt>
                <dd className="mt-1 font-mono text-sm">{app.deploy_path}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Domains</dt>
                <dd className="mt-1">
                  <Link to={`/apps/${app.id}/domains`} className="text-primary hover:underline">
                    {app.domains?.length || 1} domain{(app.domains?.length || 1) !== 1 ? 's' : ''}
                    {app.domains?.some(d => d.ssl_enabled) && (
                      <span className="ml-2 text-green-600 text-xs">(SSL enabled)</span>
                    )}
                  </Link>
                </dd>
              </div>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Repository</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="space-y-4">
              {app.git_provider && (
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Git Provider</dt>
                  <dd className="mt-1">
                    <Link to={`/git-providers/${app.git_provider.id}`} className="text-primary hover:underline">
                      {app.git_provider.name}
                    </Link>
                  </dd>
                </div>
              )}
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Repository</dt>
                <dd className="mt-1 font-mono text-sm break-all">{app.repository_url}</dd>
              </div>
              <div>
                <dt className="text-sm font-medium text-muted-foreground">Branch</dt>
                <dd className="mt-1">{app.branch}</dd>
              </div>
              {app.build_command && (
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Build Command</dt>
                  <dd className="mt-1 font-mono text-sm">{app.build_command}</dd>
                </div>
              )}
            </dl>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>Recent Deployments</CardTitle>
          <Link to={`/apps/${app.id}/deployments`} className="text-sm text-primary hover:underline">
            View all
          </Link>
        </CardHeader>
        <CardContent>
          {app.deployments && app.deployments.length > 0 ? (
            <ul className="divide-y divide-border">
              {app.deployments.slice(0, 5).map((deployment) => (
                <li key={deployment.id} className="py-3 flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium">
                      {deployment.commit_hash?.substring(0, 7) || 'Manual deployment'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                      {deployment.commit_message || 'No message'}
                    </p>
                  </div>
                  <div className="text-right">
                    <StatusBadge status={deployment.status} />
                    <p className="text-xs text-muted-foreground mt-1">
                      {new Date(deployment.created_at).toLocaleString()}
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-muted-foreground">No deployments yet</p>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Danger Zone</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <p className="font-medium">Delete this application</p>
              <p className="text-sm text-muted-foreground">
                This will remove the Nginx configuration and all deployment history.
              </p>
            </div>
            <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
              {deleting && <LoadingSpinner size="sm" className="mr-2" />}
              Delete Application
            </Button>
          </div>
        </CardContent>
      </Card>

    </div>
  )
}
