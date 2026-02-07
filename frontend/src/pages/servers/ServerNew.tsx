import { useState, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { serversApi, sshKeysApi } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { LoadingSpinner } from '@/components/custom'
import { KeyIcon } from '@heroicons/react/24/outline'

export default function ServerNew() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [publicKey, setPublicKey] = useState('')
  const [formData, setFormData] = useState({
    name: '',
    host: '',
    port: '22',
    username: 'root',
    private_key: '',
    status: 'active' as 'active' | 'inactive',
  })

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

            {publicKey && (
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
