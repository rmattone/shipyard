import { useEffect, useState, useRef, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import { deploymentsApi, Deployment } from '../../services/api'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { StatusBadge, LoadingSpinner } from '@/components/custom'

export default function DeploymentDetail() {
  const { id, deploymentId } = useParams<{ id: string; deploymentId: string }>()
  const [deployment, setDeployment] = useState<Deployment | null>(null)
  const [loading, setLoading] = useState(true)
  const [useSSE, setUseSSE] = useState(true)
  const [isStreaming, setIsStreaming] = useState(false)
  const logRef = useRef<HTMLPreElement>(null)
  const eventSourceRef = useRef<EventSource | null>(null)

  const fetchDeployment = useCallback(async () => {
    if (deploymentId) {
      try {
        const res = await deploymentsApi.get(parseInt(deploymentId))
        setDeployment(res.data)
      } finally {
        setLoading(false)
      }
    }
  }, [deploymentId])

  // Initial fetch
  useEffect(() => {
    fetchDeployment()
  }, [fetchDeployment])

  // SSE connection for real-time streaming
  useEffect(() => {
    if (!deployment || !deploymentId || !useSSE) return
    if (deployment.status !== 'running' && deployment.status !== 'pending') return

    const token = localStorage.getItem('token')
    if (!token) {
      setUseSSE(false)
      return
    }

    // Create EventSource with auth token in URL (SSE doesn't support headers)
    const streamUrl = `/api/deployments/${deploymentId}/stream?token=${encodeURIComponent(token)}`
    const eventSource = new EventSource(streamUrl)
    eventSourceRef.current = eventSource

    eventSource.addEventListener('connected', (event) => {
      setIsStreaming(true)
      const data = JSON.parse(event.data)
      // Update with initial state from SSE
      setDeployment(prev => prev ? {
        ...prev,
        status: data.status,
        log: data.log || prev.log,
      } : prev)
    })

    eventSource.addEventListener('log', (event) => {
      const data = JSON.parse(event.data)
      // Append new log chunk
      setDeployment(prev => prev ? {
        ...prev,
        log: (prev.log || '') + data.chunk,
      } : prev)
    })

    eventSource.addEventListener('complete', (event) => {
      const data = JSON.parse(event.data)
      setDeployment(prev => prev ? {
        ...prev,
        status: data.status,
      } : prev)
      setIsStreaming(false)
      eventSource.close()
      // Fetch final state to get finished_at timestamp
      fetchDeployment()
    })

    eventSource.addEventListener('heartbeat', () => {
      // Keep-alive, no action needed
    })

    eventSource.onerror = () => {
      setIsStreaming(false)
      eventSource.close()
      // Fallback to polling if SSE fails
      setUseSSE(false)
    }

    return () => {
      eventSource.close()
      eventSourceRef.current = null
      setIsStreaming(false)
    }
  }, [deployment?.status, deploymentId, useSSE, fetchDeployment])

  // Fallback polling when SSE is not available
  useEffect(() => {
    if (useSSE) return
    if (!deployment || (deployment.status !== 'running' && deployment.status !== 'pending')) return

    const interval = setInterval(fetchDeployment, 2000)
    return () => clearInterval(interval)
  }, [deployment?.status, useSSE, fetchDeployment])

  useEffect(() => {
    if (logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight
    }
  }, [deployment?.log])

  const formatDuration = () => {
    if (!deployment?.started_at) return '-'
    const start = new Date(deployment.started_at).getTime()
    const end = deployment.finished_at
      ? new Date(deployment.finished_at).getTime()
      : Date.now()
    const seconds = Math.round((end - start) / 1000)
    if (seconds < 60) return `${seconds}s`
    const minutes = Math.floor(seconds / 60)
    const remainingSeconds = seconds % 60
    return `${minutes}m ${remainingSeconds}s`
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!deployment) {
    return <div>Deployment not found</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <div className="flex items-center gap-2 text-sm text-muted-foreground mb-1">
            <Link to="/apps" className="hover:text-foreground">
              Applications
            </Link>
            <span>/</span>
            <Link to={`/apps/${id}`} className="hover:text-foreground">
              {deployment.application?.name || 'Application'}
            </Link>
            <span>/</span>
            <Link to={`/apps/${id}/deployments`} className="hover:text-foreground">
              Deployments
            </Link>
            <span>/</span>
            <span>#{deployment.id}</span>
          </div>
          <h1 className="text-2xl font-bold">Deployment Log</h1>
        </div>
        <div className="flex items-center gap-4">
          <StatusBadge status={deployment.status} />
          {(deployment.status === 'running' || deployment.status === 'pending') && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <LoadingSpinner size="sm" />
              <span>{isStreaming ? 'Live streaming...' : 'Auto-refreshing...'}</span>
            </div>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div className="lg:col-span-1 space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Details</CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="space-y-4">
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Commit</dt>
                  <dd className="mt-1 font-mono text-sm">
                    {deployment.commit_hash?.substring(0, 7) || 'Manual deployment'}
                  </dd>
                </div>
                {deployment.commit_message && (
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Message</dt>
                    <dd className="mt-1 text-sm">{deployment.commit_message}</dd>
                  </div>
                )}
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Started</dt>
                  <dd className="mt-1 text-sm">
                    {deployment.started_at
                      ? new Date(deployment.started_at).toLocaleString()
                      : 'Not started'}
                  </dd>
                </div>
                {deployment.finished_at && (
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Finished</dt>
                    <dd className="mt-1 text-sm">
                      {new Date(deployment.finished_at).toLocaleString()}
                    </dd>
                  </div>
                )}
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Duration</dt>
                  <dd className="mt-1 text-sm">{formatDuration()}</dd>
                </div>
              </dl>
            </CardContent>
          </Card>
        </div>

        <div className="lg:col-span-3">
          <Card>
            <CardHeader>
              <CardTitle>Output</CardTitle>
            </CardHeader>
            <CardContent>
              <pre
                ref={logRef}
                className="bg-gray-900 text-gray-100 p-4 rounded-lg overflow-x-auto text-xs font-mono h-[600px] overflow-y-auto whitespace-pre-wrap"
              >
                {deployment.log || (
                  <span className="text-gray-500">
                    {deployment.status === 'pending'
                      ? 'Waiting for deployment to start...'
                      : 'No output yet...'}
                  </span>
                )}
              </pre>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  )
}
