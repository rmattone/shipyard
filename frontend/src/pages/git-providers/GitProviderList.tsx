import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { gitProvidersApi, GitProvider } from '../../services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { LoadingSpinner } from '@/components/custom'
import { PlusIcon, CheckCircleIcon } from '@heroicons/react/24/outline'
import { toast } from 'sonner'

const providerTypeLabels: Record<string, string> = {
  gitlab: 'GitLab',
  github: 'GitHub',
  bitbucket: 'Bitbucket',
}

export default function GitProviderList() {
  const [providers, setProviders] = useState<GitProvider[]>([])
  const [loading, setLoading] = useState(true)
  const [testing, setTesting] = useState<number | null>(null)

  useEffect(() => {
    gitProvidersApi.list()
      .then((res) => setProviders(res.data))
      .finally(() => setLoading(false))
  }, [])

  const handleTestConnection = async (provider: GitProvider) => {
    setTesting(provider.id)
    try {
      const res = await gitProvidersApi.testConnection(provider.id)
      if (res.data.success) {
        toast.success(`Connected as ${res.data.name} (@${res.data.username})`)
      } else {
        toast.error(res.data.message || 'Connection test failed')
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to test connection')
    } finally {
      setTesting(null)
    }
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
        <h1 className="text-2xl font-bold">Git Providers</h1>
        <Link to="/git-providers/new">
          <Button>
            <PlusIcon className="h-5 w-5 mr-2" />
            Add Provider
          </Button>
        </Link>
      </div>

      <Card>
        <CardContent className="p-0">
          {providers.length === 0 ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground mb-4">No git providers configured yet</p>
              <Link to="/git-providers/new">
                <Button>Add your first provider</Button>
              </Link>
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Type</TableHead>
                  <TableHead>Host</TableHead>
                  <TableHead>Applications</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {providers.map((provider) => (
                  <TableRow key={provider.id}>
                    <TableCell>
                      <div className="flex items-center">
                        <Link
                          to={`/git-providers/${provider.id}`}
                          className="text-primary hover:underline font-medium"
                        >
                          {provider.name}
                        </Link>
                        {provider.is_default && (
                          <Badge variant="secondary" className="ml-2 bg-green-100 text-green-800">
                            <CheckCircleIcon className="h-3 w-3 mr-1" />
                            Default
                          </Badge>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {providerTypeLabels[provider.type] || provider.type}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {provider.host || (
                        <span className="text-muted-foreground/60">
                          {provider.type === 'gitlab' && 'gitlab.com'}
                          {provider.type === 'github' && 'github.com'}
                          {provider.type === 'bitbucket' && 'bitbucket.org'}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {provider.applications_count || 0}
                    </TableCell>
                    <TableCell className="text-right space-x-3">
                      <button
                        onClick={() => handleTestConnection(provider)}
                        disabled={testing === provider.id}
                        className="text-muted-foreground hover:text-foreground disabled:opacity-50"
                      >
                        {testing === provider.id ? 'Testing...' : 'Test'}
                      </button>
                      <Link
                        to={`/git-providers/${provider.id}`}
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
