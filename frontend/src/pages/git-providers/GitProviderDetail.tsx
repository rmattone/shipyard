import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { gitProvidersApi, GitProvider, Application } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { StatusBadge, LoadingSpinner } from '@/components/custom'
import { CheckCircleIcon, KeyIcon, LockClosedIcon } from '@heroicons/react/24/outline'

const providerTypeLabels: Record<string, string> = {
  gitlab: 'GitLab',
  github: 'GitHub',
  bitbucket: 'Bitbucket',
}

export default function GitProviderDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [provider, setProvider] = useState<GitProvider & { applications?: Application[] } | null>(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [testing, setTesting] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    type: 'gitlab' as 'gitlab' | 'github' | 'bitbucket',
    host: '',
    access_token: '',
    private_key: '',
    username: '',
    is_default: false,
  })

  useEffect(() => {
    if (id) {
      gitProvidersApi.get(parseInt(id))
        .then((res) => {
          setProvider(res.data)
          setFormData({
            name: res.data.name,
            type: res.data.type,
            host: res.data.host || '',
            access_token: '',
            private_key: '',
            username: res.data.username || '',
            is_default: res.data.is_default,
          })
        })
        .finally(() => setLoading(false))
    }
  }, [id])

  const handleTestConnection = async () => {
    if (!provider) return
    setTesting(true)

    try {
      const response = await gitProvidersApi.testConnection(provider.id)
      if (response.data.success) {
        toast.success(`Connected as ${response.data.name} (@${response.data.username})`)
      } else {
        toast.error(response.data.message || 'Connection failed')
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Connection failed')
    } finally {
      setTesting(false)
    }
  }

  const handleSave = async () => {
    if (!provider) return
    setSaving(true)

    try {
      const updateData: Record<string, unknown> = {
        name: formData.name,
        type: formData.type,
        host: formData.host || null,
        is_default: formData.is_default,
      }

      if (formData.type === 'bitbucket') {
        updateData.username = formData.username
      }

      if (formData.access_token) {
        updateData.access_token = formData.access_token
      }

      if (formData.private_key) {
        updateData.private_key = formData.private_key
      }

      await gitProvidersApi.update(provider.id, updateData)
      toast.success('Provider updated')
      setEditing(false)
      setFormData(prev => ({ ...prev, access_token: '', private_key: '' }))
      gitProvidersApi.get(provider.id).then((res) => setProvider(res.data))
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to update')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!provider || !confirm('Are you sure you want to delete this git provider?')) return
    setDeleting(true)

    try {
      await gitProvidersApi.delete(provider.id)
      toast.success('Provider deleted')
      navigate('/git-providers')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete')
    } finally {
      setDeleting(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!provider) {
    return <div>Provider not found</div>
  }

  const defaultHost = provider.type === 'gitlab' ? 'gitlab.com' :
    provider.type === 'github' ? 'github.com' : 'bitbucket.org'

  const hasSSHKey = provider.has_private_key
  const hasToken = provider.has_access_token

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-bold">{provider.name}</h1>
            {provider.is_default && (
              <Badge variant="secondary" className="bg-green-100 text-green-800">
                <CheckCircleIcon className="h-3 w-3 mr-1" />
                Default
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground">
            {providerTypeLabels[provider.type]} - {provider.host || defaultHost}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleTestConnection} disabled={testing}>
            {testing && <LoadingSpinner size="sm" className="mr-2" />}
            Test Connection
          </Button>
          {!editing && (
            <Button onClick={() => setEditing(true)}>Edit</Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Provider Details</CardTitle>
          </CardHeader>
          <CardContent>
            {editing ? (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Name</Label>
                  <Input
                    id="name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label>Provider Type</Label>
                  <Select
                    value={formData.type}
                    onValueChange={(value) => setFormData({ ...formData, type: value as 'gitlab' | 'github' | 'bitbucket' })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="gitlab">GitLab</SelectItem>
                      <SelectItem value="github">GitHub</SelectItem>
                      <SelectItem value="bitbucket">Bitbucket</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="host">Host (Optional)</Label>
                  <Input
                    id="host"
                    value={formData.host}
                    onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                    placeholder={`Leave empty for ${defaultHost}`}
                  />
                </div>
                {formData.type === 'bitbucket' && (
                  <div className="space-y-2">
                    <Label htmlFor="username">Username</Label>
                    <Input
                      id="username"
                      value={formData.username}
                      onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                    />
                  </div>
                )}
                <div className="space-y-2">
                  <Label htmlFor="access_token">
                    Access Token {hasToken && '(configured)'}
                  </Label>
                  <Input
                    id="access_token"
                    type="password"
                    value={formData.access_token}
                    onChange={(e) => setFormData({ ...formData, access_token: e.target.value })}
                    placeholder={hasToken ? 'Enter new token to replace' : 'Enter token to add'}
                  />
                  <p className="text-xs text-muted-foreground">
                    Leave empty to keep current token
                  </p>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="private_key">
                    SSH Private Key {hasSSHKey && '(configured)'}
                  </Label>
                  <Textarea
                    id="private_key"
                    value={formData.private_key}
                    onChange={(e) => setFormData({ ...formData, private_key: e.target.value })}
                    placeholder={hasSSHKey ? 'Enter new key to replace' : 'Enter key to add'}
                    rows={4}
                    className="font-mono text-sm"
                  />
                  <p className="text-xs text-muted-foreground">
                    Leave empty to keep current key
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    id="is_default"
                    checked={formData.is_default}
                    onChange={(e) => setFormData({ ...formData, is_default: e.target.checked })}
                    className="h-4 w-4 rounded border-input text-primary focus:ring-primary"
                  />
                  <Label htmlFor="is_default" className="font-normal">
                    Set as default provider
                  </Label>
                </div>
                <div className="flex justify-end gap-2">
                  <Button variant="outline" onClick={() => {
                    setEditing(false)
                    setFormData(prev => ({ ...prev, access_token: '', private_key: '' }))
                  }}>
                    Cancel
                  </Button>
                  <Button onClick={handleSave} disabled={saving}>
                    {saving && <LoadingSpinner size="sm" className="mr-2" />}
                    Save
                  </Button>
                </div>
              </div>
            ) : (
              <dl className="space-y-4">
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Type</dt>
                  <dd className="mt-1">{providerTypeLabels[provider.type]}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Host</dt>
                  <dd className="mt-1">{provider.host || defaultHost}</dd>
                </div>
                {provider.type === 'bitbucket' && provider.username && (
                  <div>
                    <dt className="text-sm font-medium text-muted-foreground">Username</dt>
                    <dd className="mt-1">{provider.username}</dd>
                  </div>
                )}
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Default Provider</dt>
                  <dd className="mt-1">{provider.is_default ? 'Yes' : 'No'}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Authentication</dt>
                  <dd className="mt-1 space-y-2">
                    <div className="flex items-center gap-2">
                      <LockClosedIcon className={`h-4 w-4 ${hasToken ? 'text-green-600' : 'text-muted-foreground/50'}`} />
                      <span className={hasToken ? '' : 'text-muted-foreground/50'}>
                        Access Token {hasToken ? '(configured)' : '(not set)'}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <KeyIcon className={`h-4 w-4 ${hasSSHKey ? 'text-green-600' : 'text-muted-foreground/50'}`} />
                      <span className={hasSSHKey ? '' : 'text-muted-foreground/50'}>
                        SSH Private Key {hasSSHKey ? '(configured)' : '(not set)'}
                      </span>
                    </div>
                    {hasSSHKey && (
                      <p className="text-xs text-muted-foreground ml-6">
                        SSH key takes priority over access token for git operations
                      </p>
                    )}
                  </dd>
                </div>
              </dl>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Linked Applications</CardTitle>
          </CardHeader>
          <CardContent>
            {provider.applications && provider.applications.length > 0 ? (
              <ul className="divide-y divide-border">
                {provider.applications.map((app) => (
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
            ) : (
              <p className="text-muted-foreground">No applications using this provider</p>
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Danger Zone</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-center justify-between">
            <div>
              <p className="font-medium">Delete this provider</p>
              <p className="text-sm text-muted-foreground">
                This action cannot be undone. All linked applications must be reassigned first.
              </p>
            </div>
            <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
              {deleting && <LoadingSpinner size="sm" className="mr-2" />}
              Delete Provider
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
