import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { serversApi, Server, Application } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { StatusBadge, LoadingSpinner } from '@/components/custom'

export default function ServerDetail() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [server, setServer] = useState<Server & { applications?: Application[] } | null>(null)
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(false)
  const [testing, setTesting] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formData, setFormData] = useState({
    name: '',
    host: '',
    port: '22',
    username: '',
    private_key: '',
    status: 'active',
  })

  useEffect(() => {
    if (id) {
      serversApi.get(parseInt(id))
        .then((res) => {
          setServer(res.data)
          setFormData({
            name: res.data.name,
            host: res.data.host,
            port: res.data.port.toString(),
            username: res.data.username,
            private_key: '',
            status: res.data.status,
          })
        })
        .finally(() => setLoading(false))
    }
  }, [id])

  const handleTestConnection = async () => {
    if (!server) return
    setTesting(true)

    try {
      const response = await serversApi.testConnection(server.id)
      if (response.data.success) {
        toast.success('Connection successful')
      } else {
        toast.error(response.data.message)
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Connection failed')
    } finally {
      setTesting(false)
    }
  }

  const handleSave = async () => {
    if (!server) return
    setSaving(true)

    try {
      const updateData: Record<string, unknown> = {
        name: formData.name,
        host: formData.host,
        port: parseInt(formData.port),
        username: formData.username,
        status: formData.status,
      }

      if (formData.private_key) {
        updateData.private_key = formData.private_key
      }

      await serversApi.update(server.id, updateData)
      toast.success('Server updated')
      setEditing(false)
      serversApi.get(server.id).then((res) => setServer(res.data))
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to update')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!server || !confirm('Are you sure you want to delete this server?')) return
    setDeleting(true)

    try {
      await serversApi.delete(server.id)
      toast.success('Server deleted')
      navigate('/servers')
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

  if (!server) {
    return <div>Server not found</div>
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">{server.name}</h1>
          <p className="text-muted-foreground">{server.host}:{server.port}</p>
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
            <CardTitle>Server Details</CardTitle>
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
                <div className="grid grid-cols-3 gap-4">
                  <div className="col-span-2 space-y-2">
                    <Label htmlFor="host">Host</Label>
                    <Input
                      id="host"
                      value={formData.host}
                      onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="port">Port</Label>
                    <Input
                      id="port"
                      type="number"
                      value={formData.port}
                      onChange={(e) => setFormData({ ...formData, port: e.target.value })}
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="username">Username</Label>
                  <Input
                    id="username"
                    value={formData.username}
                    onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="private_key">Private Key (leave empty to keep current)</Label>
                  <Textarea
                    id="private_key"
                    value={formData.private_key}
                    onChange={(e) => setFormData({ ...formData, private_key: e.target.value })}
                    rows={6}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="status">Status</Label>
                  <Select
                    value={formData.status}
                    onValueChange={(value) => setFormData({ ...formData, status: value })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="active">Active</SelectItem>
                      <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex justify-end gap-2">
                  <Button variant="outline" onClick={() => setEditing(false)}>
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
                  <dt className="text-sm font-medium text-muted-foreground">Status</dt>
                  <dd className="mt-1">
                    <StatusBadge status={server.status} />
                  </dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Host</dt>
                  <dd className="mt-1">{server.host}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Port</dt>
                  <dd className="mt-1">{server.port}</dd>
                </div>
                <div>
                  <dt className="text-sm font-medium text-muted-foreground">Username</dt>
                  <dd className="mt-1">{server.username}</dd>
                </div>
              </dl>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Applications</CardTitle>
          </CardHeader>
          <CardContent>
            {server.applications && server.applications.length > 0 ? (
              <ul className="divide-y divide-border">
                {server.applications.map((app) => (
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
              <p className="text-muted-foreground">No applications on this server</p>
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
              <p className="font-medium">Delete this server</p>
              <p className="text-sm text-muted-foreground">
                This action cannot be undone. All applications must be removed first.
              </p>
            </div>
            <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
              {deleting && <LoadingSpinner size="sm" className="mr-2" />}
              Delete Server
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
