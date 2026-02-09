import { useState, useEffect, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { serversApi, sshKeysApi, systemApi } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { LoadingSpinner } from '@/components/custom'
import { KeyIcon, ComputerDesktopIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'

export default function ServerNew() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [publicKey, setPublicKey] = useState('')
  const [isDocker, setIsDocker] = useState(false)
  const [dockerHostIp, setDockerHostIp] = useState<string | null>(null)
  const [formData, setFormData] = useState({
    name: '',
    host: '',
    port: '22',
    username: 'root',
    private_key: '',
    status: 'active' as 'active' | 'inactive',
    is_local: false,
  })

  useEffect(() => {
    systemApi.getEnvironment().then((res) => {
      setIsDocker(res.data.is_docker)
      setDockerHostIp(res.data.docker_host_ip)
    }).catch(() => {
      // Ignore errors - assume not Docker
    })
  }, [])

  const handleGenerateKey = async () => {
    setGenerating(true)
    try {
      const response = await sshKeysApi.generate()
      setFormData({ ...formData, private_key: response.data.private_key })
      setPublicKey(response.data.public_key)
      toast.success('SSH key pair generated')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to generate SSH key')
    } finally {
      setGenerating(false)
    }
  }

  const copyPublicKey = () => {
    navigator.clipboard.writeText(publicKey)
    toast.success('Public key copied to clipboard')
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)

    try {
      const response = await serversApi.create({
        ...formData,
        port: parseInt(formData.port),
      })
      toast.success('Server created successfully')
      navigate(`/servers/${response.data.id}`)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create server')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">Add New Server</h1>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="name">Name</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="Production Server"
                required
              />
            </div>

            <div className="flex items-center justify-between p-4 bg-muted/50 rounded-lg border">
              <div className="flex items-center gap-3">
                <ComputerDesktopIcon className="h-5 w-5 text-muted-foreground" />
                <div>
                  <Label htmlFor="is_local" className="text-sm font-medium cursor-pointer">
                    Local Server
                  </Label>
                  <p className="text-xs text-muted-foreground">
                    This is the server where ShipYard is installed
                  </p>
                </div>
              </div>
              <Switch
                id="is_local"
                checked={formData.is_local}
                onCheckedChange={(checked) => setFormData({ ...formData, is_local: checked })}
              />
            </div>

            {!formData.is_local && (
              <>
                <div className="grid grid-cols-3 gap-4">
                  <div className="col-span-2 space-y-2">
                    <Label htmlFor="host">Host</Label>
                    <Input
                      id="host"
                      value={formData.host}
                      onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                      placeholder="192.168.1.100 or server.example.com"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="port">Port</Label>
                    <Input
                      id="port"
                      type="number"
                      value={formData.port}
                      onChange={(e) => setFormData({ ...formData, port: e.target.value })}
                      required
                    />
                  </div>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="username">Username</Label>
                  <Input
                    id="username"
                    value={formData.username}
                    onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                    required
                  />
                </div>

                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="private_key">Private Key</Label>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={handleGenerateKey}
                      disabled={generating}
                    >
                      {generating ? (
                        <LoadingSpinner size="sm" className="mr-1" />
                      ) : (
                        <KeyIcon className="h-4 w-4 mr-1" />
                      )}
                      Generate Key Pair
                    </Button>
                  </div>
                  <Textarea
                    id="private_key"
                    value={formData.private_key}
                    onChange={(e) => setFormData({ ...formData, private_key: e.target.value })}
                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                    rows={8}
                    required
                  />
                </div>
              </>
            )}

            {publicKey && !formData.is_local && (
              <Alert className="bg-blue-50 border-blue-200">
                <AlertDescription>
                  <div className="flex items-center justify-between mb-2">
                    <h4 className="text-sm font-medium text-blue-800">Public Key</h4>
                    <Button type="button" variant="outline" size="sm" onClick={copyPublicKey}>
                      Copy
                    </Button>
                  </div>
                  <p className="text-xs text-blue-700 mb-2">
                    Add this public key to your server's <code className="bg-blue-100 px-1 rounded">~/.ssh/authorized_keys</code> file:
                  </p>
                  <pre className="bg-blue-100 p-2 rounded text-xs overflow-x-auto text-blue-900">
                    {publicKey}
                  </pre>
                  <div className="mt-3 text-xs text-blue-700">
                    <strong>Quick setup command:</strong>
                    <pre className="bg-blue-100 p-2 rounded mt-1 overflow-x-auto">
                      {`echo "${publicKey}" >> ~/.ssh/authorized_keys`}
                    </pre>
                  </div>
                </AlertDescription>
              </Alert>
            )}

            {formData.is_local && isDocker && (
              <Alert className="bg-amber-50 border-amber-200">
                <AlertDescription>
                  <div className="flex gap-3">
                    <ExclamationTriangleIcon className="h-5 w-5 text-amber-600 flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="text-sm font-medium text-amber-800 mb-2">
                        Local server mode is not recommended with Docker
                      </p>
                      <p className="text-sm text-amber-700 mb-3">
                        ShipYard runs inside a Docker container and cannot directly manage the host server.
                        Use SSH to connect to the host instead.
                      </p>
                      <div className="text-sm text-amber-700">
                        <strong>To manage this server via SSH:</strong>
                        <ol className="list-decimal list-inside mt-1 space-y-1">
                          <li>Disable "Local Server" above</li>
                          <li>Use host: <code className="bg-amber-100 px-1 rounded">{dockerHostIp || '172.17.0.1'}</code> or the server's IP</li>
                          <li>Generate an SSH key and add it to the host's authorized_keys</li>
                        </ol>
                      </div>
                    </div>
                  </div>
                </AlertDescription>
              </Alert>
            )}

            {formData.is_local && !isDocker && (
              <Alert className="bg-green-50 border-green-200">
                <AlertDescription>
                  <p className="text-sm text-green-800">
                    Commands will run directly on this server without SSH. No additional configuration needed.
                  </p>
                </AlertDescription>
              </Alert>
            )}

            <div className="space-y-2">
              <Label htmlFor="status">Status</Label>
              <Select
                value={formData.status}
                onValueChange={(value) => setFormData({ ...formData, status: value as 'active' | 'inactive' })}
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

            <div className="flex justify-end gap-4">
              <Button type="button" variant="outline" onClick={() => navigate('/servers')}>
                Cancel
              </Button>
              <Button type="submit" disabled={loading}>
                {loading && <LoadingSpinner size="sm" className="mr-2" />}
                Create Server
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
