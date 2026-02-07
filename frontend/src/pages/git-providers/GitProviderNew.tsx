import { useState, FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { gitProvidersApi, sshKeysApi } from '../../services/api'
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

const providerSSHUrls: Record<string, { url: string; label: string }> = {
  gitlab: { url: 'https://gitlab.com/-/user_settings/ssh_keys', label: 'GitLab SSH Keys' },
  github: { url: 'https://github.com/settings/keys', label: 'GitHub SSH Keys' },
  bitbucket: { url: 'https://bitbucket.org/account/settings/ssh-keys/', label: 'Bitbucket SSH Keys' },
}

const providerHelpTexts: Record<string, { token: { title: string; steps: string[] }; ssh: { title: string; steps: string[] } }> = {
  gitlab: {
    token: {
      title: 'GitLab Personal Access Token',
      steps: [
        'Go to GitLab Settings > Access Tokens',
        'Create a new token with "read_repository" scope',
        'For group/project tokens, use "read_repository" scope',
        'Copy the generated token'
      ]
    },
    ssh: {
      title: 'GitLab SSH Key',
      steps: [
        'Click "Generate Key Pair" or paste an existing private key',
        'Copy the public key shown below',
        'Add the public key to GitLab Settings > SSH Keys',
        'The private key will be encrypted and stored securely'
      ]
    }
  },
  github: {
    token: {
      title: 'GitHub Personal Access Token',
      steps: [
        'Go to GitHub Settings > Developer settings > Personal access tokens',
        'Generate a new token (classic or fine-grained)',
        'Select "repo" scope for full repository access',
        'Copy the generated token'
      ]
    },
    ssh: {
      title: 'GitHub SSH Key',
      steps: [
        'Click "Generate Key Pair" or paste an existing private key',
        'Copy the public key shown below',
        'Add the public key to GitHub Settings > SSH and GPG keys',
        'The private key will be encrypted and stored securely'
      ]
    }
  },
  bitbucket: {
    token: {
      title: 'Bitbucket App Password',
      steps: [
        'Go to Bitbucket Settings > App passwords',
        'Create a new app password with "Repositories: Read" permission',
        'Enter your Bitbucket username in the Username field',
        'Copy the generated app password'
      ]
    },
    ssh: {
      title: 'Bitbucket SSH Key',
      steps: [
        'Click "Generate Key Pair" or paste an existing private key',
        'Copy the public key shown below',
        'Add the public key to Bitbucket Settings > SSH keys',
        'The private key will be encrypted and stored securely'
      ]
    }
  }
}

type AuthMethod = 'token' | 'ssh'

export default function GitProviderNew() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [testing, setTesting] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [publicKey, setPublicKey] = useState('')
  const [testResult, setTestResult] = useState<{ success: boolean; name?: string; username?: string; message?: string } | null>(null)
  const [authMethod, setAuthMethod] = useState<AuthMethod>('token')
  const [formData, setFormData] = useState({
    name: '',
    type: 'gitlab' as 'gitlab' | 'github' | 'bitbucket',
    host: '',
    access_token: '',
    private_key: '',
    username: '',
    is_default: false,
  })

  const handleGenerateKey = async () => {
    setGenerating(true)
    try {
      const response = await sshKeysApi.generate(`git-provider-${formData.name || formData.type}`)
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

  const handleTestConnection = async () => {
    if (authMethod === 'token' && !formData.access_token) {
      toast.error('Please enter an access token first')
      return
    }
    if (authMethod === 'ssh' && !formData.private_key) {
      toast.error('Please enter an SSH private key first')
      return
    }

    setTesting(true)
    setTestResult(null)

    try {
      const tempProvider = await gitProvidersApi.create({
        name: formData.name || 'Test Connection',
        type: formData.type,
        host: formData.host || undefined,
        access_token: authMethod === 'token' ? formData.access_token : undefined,
        private_key: authMethod === 'ssh' ? formData.private_key : undefined,
        username: formData.type === 'bitbucket' ? formData.username : undefined,
        is_default: false,
      })

      try {
        const result = await gitProvidersApi.testConnection(tempProvider.data.id)
        setTestResult(result.data)
        if (result.data.success) {
          toast.success(`Connected as ${result.data.name} (@${result.data.username})`)
        }
      } finally {
        await gitProvidersApi.delete(tempProvider.data.id)
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      setTestResult({ success: false, message: err.response?.data?.message || 'Connection test failed' })
      toast.error(err.response?.data?.message || 'Failed to test connection')
    } finally {
      setTesting(false)
    }
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLoading(true)

    try {
      const response = await gitProvidersApi.create({
        name: formData.name,
        type: formData.type,
        host: formData.host || undefined,
        access_token: authMethod === 'token' ? formData.access_token : undefined,
        private_key: authMethod === 'ssh' ? formData.private_key : undefined,
        username: formData.type === 'bitbucket' ? formData.username : undefined,
        is_default: formData.is_default,
      })
      toast.success('Git provider created successfully')
      navigate(`/git-providers/${response.data.id}`)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create git provider')
    } finally {
      setLoading(false)
    }
  }

  const helpText = providerHelpTexts[formData.type][authMethod]
  const hasCredentials = authMethod === 'token' ? formData.access_token : formData.private_key
  const sshUrl = providerSSHUrls[formData.type]

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      <h1 className="text-2xl font-bold">Add Git Provider</h1>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="name">Name</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="My GitLab Account"
                required
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
                placeholder={
                  formData.type === 'gitlab' ? 'gitlab.example.com (leave empty for gitlab.com)' :
                  formData.type === 'github' ? 'github.example.com (leave empty for github.com)' :
                  'Leave empty for bitbucket.org'
                }
              />
              <p className="text-xs text-muted-foreground">
                Only needed for self-hosted instances
              </p>
            </div>

            {formData.type === 'bitbucket' && authMethod === 'token' && (
              <div className="space-y-2">
                <Label htmlFor="username">Username</Label>
                <Input
                  id="username"
                  value={formData.username}
                  onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                  placeholder="your-bitbucket-username"
                  required
                />
              </div>
            )}

            <div className="space-y-2">
              <Label>Authentication Method</Label>
              <div className="flex gap-4">
                <label className="flex items-center">
                  <input
                    type="radio"
                    name="authMethod"
                    value="token"
                    checked={authMethod === 'token'}
                    onChange={() => setAuthMethod('token')}
                    className="h-4 w-4 text-primary focus:ring-primary border-input"
                  />
                  <span className="ml-2 text-sm">Access Token</span>
                </label>
                <label className="flex items-center">
                  <input
                    type="radio"
                    name="authMethod"
                    value="ssh"
                    checked={authMethod === 'ssh'}
                    onChange={() => setAuthMethod('ssh')}
                    className="h-4 w-4 text-primary focus:ring-primary border-input"
                  />
                  <span className="ml-2 text-sm">SSH Private Key</span>
                </label>
              </div>
            </div>

            {authMethod === 'token' ? (
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label htmlFor="access_token">Access Token</Label>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={handleTestConnection}
                    disabled={testing || !formData.access_token}
                  >
                    {testing && <LoadingSpinner size="sm" className="mr-2" />}
                    Test Connection
                  </Button>
                </div>
                <Input
                  id="access_token"
                  type="password"
                  value={formData.access_token}
                  onChange={(e) => setFormData({ ...formData, access_token: e.target.value })}
                  placeholder="Enter your access token"
                  required
                />
              </div>
            ) : (
              <div className="space-y-4">
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label htmlFor="private_key">SSH Private Key</Label>
                    <div className="flex gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleGenerateKey}
                        disabled={generating}
                      >
                        {generating ? (
                          <LoadingSpinner size="sm" className="mr-2" />
                        ) : (
                          <KeyIcon className="h-4 w-4 mr-1" />
                        )}
                        Generate Key Pair
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={handleTestConnection}
                        disabled={testing || !formData.private_key}
                      >
                        {testing && <LoadingSpinner size="sm" className="mr-2" />}
                        Test Connection
                      </Button>
                    </div>
                  </div>
                  <Textarea
                    id="private_key"
                    value={formData.private_key}
                    onChange={(e) => {
                      setFormData({ ...formData, private_key: e.target.value })
                      if (publicKey) setPublicKey('')
                    }}
                    placeholder={"-----BEGIN OPENSSH PRIVATE KEY-----\n...\n-----END OPENSSH PRIVATE KEY-----"}
                    rows={6}
                    className="font-mono text-sm"
                    required
                  />
                  <p className="text-xs text-muted-foreground">
                    Paste your SSH private key or generate a new one. It will be encrypted before storage.
                  </p>
                </div>

                {publicKey && (
                  <Alert className="bg-green-50 border-green-200">
                    <AlertDescription>
                      <div className="flex items-center justify-between mb-2">
                        <h4 className="text-sm font-medium text-green-800">Public Key Generated</h4>
                        <Button type="button" variant="outline" size="sm" onClick={copyPublicKey}>
                          Copy
                        </Button>
                      </div>
                      <p className="text-xs text-green-700 mb-2">
                        Add this public key to your {formData.type === 'gitlab' ? 'GitLab' : formData.type === 'github' ? 'GitHub' : 'Bitbucket'} account:
                      </p>
                      <pre className="bg-green-100 p-2 rounded text-xs overflow-x-auto text-green-900 whitespace-pre-wrap break-all">
                        {publicKey}
                      </pre>
                      {!formData.host && (
                        <div className="mt-3">
                          <a
                            href={sshUrl.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="text-sm text-green-700 hover:text-green-900 underline"
                          >
                            Open {sshUrl.label} →
                          </a>
                        </div>
                      )}
                    </AlertDescription>
                  </Alert>
                )}
              </div>
            )}

            {testResult && (
              <Alert className={testResult.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}>
                <AlertDescription>
                  {testResult.success ? (
                    <span className="text-green-800">
                      Connected successfully as <strong>{testResult.name}</strong> (@{testResult.username})
                    </span>
                  ) : (
                    <span className="text-red-800">{testResult.message}</span>
                  )}
                </AlertDescription>
              </Alert>
            )}

            <Alert className="bg-blue-50 border-blue-200">
              <AlertDescription>
                <h4 className="text-sm font-medium text-blue-800 mb-2">{helpText.title}</h4>
                <ol className="list-decimal list-inside text-sm text-blue-700 space-y-1">
                  {helpText.steps.map((step, i) => (
                    <li key={i}>{step}</li>
                  ))}
                </ol>
              </AlertDescription>
            </Alert>

            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="is_default"
                checked={formData.is_default}
                onChange={(e) => setFormData({ ...formData, is_default: e.target.checked })}
                className="h-4 w-4 rounded border-input text-primary focus:ring-primary"
              />
              <Label htmlFor="is_default" className="font-normal">
                Set as default provider for new applications
              </Label>
            </div>

            <div className="flex justify-end gap-4">
              <Button type="button" variant="outline" onClick={() => navigate('/git-providers')}>
                Cancel
              </Button>
              <Button type="submit" disabled={loading || !hasCredentials}>
                {loading && <LoadingSpinner size="sm" className="mr-2" />}
                Create Provider
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
