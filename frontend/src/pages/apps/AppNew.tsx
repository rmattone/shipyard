import { useState, useEffect, FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { applicationsApi, serversApi, gitProvidersApi, Server, GitProvider, Repository } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
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
import { MagnifyingGlassIcon, LockClosedIcon, GlobeAltIcon } from '@heroicons/react/24/outline'

export default function AppNew() {
  const navigate = useNavigate()
  const { serverId } = useParams<{ serverId: string }>()
  const [loading, setLoading] = useState(false)
  const [loadingServer, setLoadingServer] = useState(true)
  const [server, setServer] = useState<Server | null>(null)
  const [gitProviders, setGitProviders] = useState<GitProvider[]>([])
  const [step, setStep] = useState(1)
  const [webhookInfo, setWebhookInfo] = useState<{ url: string; secret: string } | null>(null)

  // Repository search state
  const [repositories, setRepositories] = useState<Repository[]>([])
  const [loadingRepos, setLoadingRepos] = useState(false)
  const [repoSearch, setRepoSearch] = useState('')
  const [selectedRepo, setSelectedRepo] = useState<Repository | null>(null)
  const [showManualInput, setShowManualInput] = useState(false)

  // Branch state
  const [branches, setBranches] = useState<{ name: string; default: boolean }[]>([])
  const [loadingBranches, setLoadingBranches] = useState(false)

  // Node.js versions state
  const [nodeVersions, setNodeVersions] = useState<string[]>([])
  const [loadingNodeVersions, setLoadingNodeVersions] = useState(false)

  const [formData, setFormData] = useState({
    git_provider_id: 'none',
    name: '',
    type: 'laravel' as 'laravel' | 'nodejs' | 'static',
    node_version: 'default',
    repository_url: '',
    branch: 'main',
    deploy_path: '',
    build_command: '',
  })

  const selectedProvider = gitProviders.find(p => p.id.toString() === formData.git_provider_id)
  const canSearchRepos = selectedProvider?.has_access_token

  useEffect(() => {
    if (!serverId) {
      navigate('/servers')
      return
    }

    Promise.all([
      serversApi.get(parseInt(serverId)),
      gitProvidersApi.list()
    ]).then(([serverRes, providersRes]) => {
      if (serverRes.data.status !== 'active') {
        toast.error('Server is not active')
        navigate(`/servers/${serverId}`)
        return
      }
      setServer(serverRes.data)
      setGitProviders(providersRes.data)
      const defaultProvider = providersRes.data.find(p => p.is_default)
      if (defaultProvider) {
        setFormData(prev => ({ ...prev, git_provider_id: defaultProvider.id.toString() }))
      }

      // Load Node.js versions from the server
      setLoadingNodeVersions(true)
      serversApi.getNodeVersions(parseInt(serverId))
        .then(res => setNodeVersions(res.data.versions))
        .catch(() => setNodeVersions([]))
        .finally(() => setLoadingNodeVersions(false))
    }).catch(() => {
      toast.error('Server not found')
      navigate('/servers')
    }).finally(() => {
      setLoadingServer(false)
    })
  }, [serverId, navigate])

  // Load repositories when provider changes
  useEffect(() => {
    if (formData.git_provider_id && formData.git_provider_id !== 'none' && selectedProvider?.has_access_token) {
      loadRepositories()
    } else {
      setRepositories([])
    }
    setSelectedRepo(null)
    setShowManualInput(false)
  }, [formData.git_provider_id, selectedProvider?.has_access_token])

  const loadRepositories = async (search?: string) => {
    if (!formData.git_provider_id || formData.git_provider_id === 'none') return

    setLoadingRepos(true)
    try {
      const res = await gitProvidersApi.listRepositories(
        parseInt(formData.git_provider_id),
        search,
        1,
        50
      )
      setRepositories(res.data.repositories)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { error?: string } } }
      if (err.response?.data?.error) {
        toast.error(err.response.data.error)
      }
      setRepositories([])
    } finally {
      setLoadingRepos(false)
    }
  }

  const handleRepoSearch = () => {
    loadRepositories(repoSearch)
  }

  const handleSelectRepo = async (repo: Repository) => {
    setSelectedRepo(repo)
    setFormData(prev => ({
      ...prev,
      repository_url: selectedProvider?.has_private_key ? repo.ssh_url : repo.https_url,
      branch: repo.default_branch,
    }))

    // Load branches for selected repo
    if (selectedProvider?.has_access_token) {
      setLoadingBranches(true)
      setBranches([])
      try {
        const res = await gitProvidersApi.listBranches(selectedProvider.id, repo.full_name)
        setBranches(res.data.branches)
      } catch {
        // Fallback to just the default branch
        setBranches([{ name: repo.default_branch, default: true }])
      } finally {
        setLoadingBranches(false)
      }
    }
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (!serverId) return
    setLoading(true)

    try {
      const response = await applicationsApi.create({
        server_id: parseInt(serverId),
        git_provider_id: formData.git_provider_id && formData.git_provider_id !== 'none' ? parseInt(formData.git_provider_id) : null,
        name: formData.name,
        type: formData.type,
        node_version: formData.node_version === 'default' ? null : formData.node_version,
        repository_url: formData.repository_url,
        branch: formData.branch,
        deploy_path: formData.deploy_path ? `/var/www/shipyard/${formData.deploy_path}` : undefined,
        build_command: formData.build_command || null,
      })
      setWebhookInfo({
        url: response.data.webhook_url,
        secret: response.data.webhook_secret,
      })
      setStep(3)
      toast.success('Application created successfully')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create application')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (formData.name && !formData.deploy_path) {
      const safeName = formData.name.toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
      setFormData((prev) => ({ ...prev, deploy_path: safeName }))
    }
  }, [formData.name])

  if (loadingServer) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) {
    return null
  }

  const steps = [
    { number: 1, name: 'Details' },
    { number: 2, name: 'Repository' },
    { number: 3, name: 'Complete' },
  ]

  return (
    <div className="max-w-2xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold">Add New Application</h1>
        <p className="text-muted-foreground mt-1">
          Creating application on <span className="font-medium text-foreground">{server.name}</span>
        </p>
      </div>

      <nav aria-label="Progress">
        <ol className="flex items-center">
          {steps.map((s, i) => (
            <li key={s.number} className={`flex items-center ${i < steps.length - 1 ? 'flex-1' : ''}`}>
              <span
                className={`flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium ${
                  step >= s.number
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground'
                }`}
              >
                {s.number}
              </span>
              <span className="ml-2 text-sm font-medium text-muted-foreground">{s.name}</span>
              {i < steps.length - 1 && (
                <div className="flex-1 ml-4 border-t-2 border-muted" />
              )}
            </li>
          ))}
        </ol>
      </nav>

      <Card>
        <CardContent className="pt-6">
          <form onSubmit={handleSubmit}>
            {step === 1 && (
              <div className="space-y-6">
                <div className="space-y-2">
                  <Label htmlFor="name">Application Name</Label>
                  <Input
                    id="name"
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="My Application"
                    required
                  />
                </div>

                <div className="space-y-2">
                  <Label>Application Type</Label>
                  <Select
                    value={formData.type}
                    onValueChange={(value) => setFormData({ ...formData, type: value as 'laravel' | 'nodejs' | 'static', node_version: 'default' })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="laravel">Laravel (PHP)</SelectItem>
                      <SelectItem value="nodejs">Node.js</SelectItem>
                      <SelectItem value="static">Vue/React</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                {(formData.type === 'nodejs' || formData.type === 'static') && (
                  <div className="space-y-2">
                    <Label>Node.js Version</Label>
                    <Select
                      value={formData.node_version}
                      onValueChange={(value) => setFormData({ ...formData, node_version: value })}
                      disabled={loadingNodeVersions}
                    >
                      <SelectTrigger>
                        <SelectValue placeholder={loadingNodeVersions ? 'Loading versions...' : 'Server default'} />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Server default</SelectItem>
                        {nodeVersions.filter(v => v).map((version) => (
                          <SelectItem key={version} value={version}>
                            Node.js {version}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <p className="text-xs text-muted-foreground">
                      {nodeVersions.length === 0 && !loadingNodeVersions
                        ? 'No Node.js versions found via nvm on the server.'
                        : 'Select a specific Node.js version or use server default.'}
                    </p>
                  </div>
                )}

                <div className="space-y-2">
                  <Label>Deploy Path</Label>
                  <div className="flex items-center">
                    <span className="px-3 py-2 bg-muted border border-r-0 rounded-l-md text-sm text-muted-foreground font-mono">
                      /var/www/shipyard/
                    </span>
                    <Input
                      value={formData.deploy_path}
                      onChange={(e) => setFormData({ ...formData, deploy_path: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-') })}
                      placeholder="my-app"
                      className="font-mono text-sm rounded-l-none"
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Folder name for your application.
                  </p>
                </div>

                <div className="flex justify-between">
                  <Button type="button" variant="outline" onClick={() => navigate(`/servers/${serverId}`)}>
                    Cancel
                  </Button>
                  <Button type="button" onClick={() => setStep(2)} disabled={!formData.name}>
                    Next
                  </Button>
                </div>
              </div>
            )}

            {step === 2 && (
              <div className="space-y-6">
                <div className="space-y-2">
                  <Label>Git Provider</Label>
                  <Select
                    value={formData.git_provider_id}
                    onValueChange={(value) => setFormData({ ...formData, git_provider_id: value, repository_url: '', branch: 'main' })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a git provider" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">None (SSH keys configured on server)</SelectItem>
                      {gitProviders.map((p) => (
                        <SelectItem key={p.id} value={p.id.toString()}>
                          {p.name} ({p.type === 'gitlab' ? 'GitLab' : p.type === 'github' ? 'GitHub' : 'Bitbucket'})
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {formData.git_provider_id === 'none' && (
                    <p className="text-xs text-yellow-600">
                      Without a git provider, you'll need to configure SSH keys on the target server.
                    </p>
                  )}
                  {selectedProvider && !selectedProvider.has_access_token && (
                    <p className="text-xs text-yellow-600">
                      This provider only has SSH key configured. Add an access token to enable repository search.
                    </p>
                  )}
                </div>

                {/* Repository Selection */}
                {canSearchRepos && !showManualInput ? (
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label>Search Repository</Label>
                      <div className="flex gap-2">
                        <Input
                          value={repoSearch}
                          onChange={(e) => setRepoSearch(e.target.value)}
                          placeholder="Search repositories..."
                          onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), handleRepoSearch())}
                        />
                        <Button type="button" variant="outline" onClick={handleRepoSearch} disabled={loadingRepos}>
                          {loadingRepos ? <LoadingSpinner size="sm" /> : <MagnifyingGlassIcon className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>

                    {selectedRepo && (
                      <div className="space-y-4">
                        <Alert className="bg-green-50 border-green-200">
                          <AlertDescription>
                            <div className="flex items-center justify-between">
                              <div>
                                <span className="font-medium text-green-800">{selectedRepo.full_name}</span>
                              </div>
                              <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                  setSelectedRepo(null)
                                  setBranches([])
                                  setFormData(prev => ({ ...prev, repository_url: '', branch: 'main' }))
                                }}
                              >
                                Change
                              </Button>
                            </div>
                          </AlertDescription>
                        </Alert>

                        <div className="space-y-2">
                          <Label>Branch</Label>
                          <Select
                            value={formData.branch}
                            onValueChange={(value) => setFormData(prev => ({ ...prev, branch: value }))}
                            disabled={loadingBranches}
                          >
                            <SelectTrigger>
                              <SelectValue placeholder={loadingBranches ? 'Loading branches...' : 'Select branch'} />
                            </SelectTrigger>
                            <SelectContent>
                              {loadingBranches ? (
                                <SelectItem value={formData.branch} disabled>Loading...</SelectItem>
                              ) : branches.length > 0 ? (
                                branches.map((branch) => (
                                  <SelectItem key={branch.name} value={branch.name}>
                                    {branch.name} {branch.default && '(default)'}
                                  </SelectItem>
                                ))
                              ) : (
                                <SelectItem value={formData.branch}>{formData.branch}</SelectItem>
                              )}
                            </SelectContent>
                          </Select>
                        </div>
                      </div>
                    )}

                    {!selectedRepo && (
                      <>
                        <div className="max-h-64 overflow-y-auto border rounded-md">
                          {loadingRepos ? (
                            <div className="flex items-center justify-center py-8">
                              <LoadingSpinner size="md" />
                            </div>
                          ) : repositories.length > 0 ? (
                            <ul className="divide-y">
                              {repositories.map((repo) => (
                                <li
                                  key={repo.id}
                                  className="p-3 hover:bg-muted cursor-pointer"
                                  onClick={() => handleSelectRepo(repo)}
                                >
                                  <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                      {repo.private ? (
                                        <LockClosedIcon className="h-4 w-4 text-muted-foreground" />
                                      ) : (
                                        <GlobeAltIcon className="h-4 w-4 text-muted-foreground" />
                                      )}
                                      <span className="font-medium">{repo.full_name}</span>
                                    </div>
                                    <span className="text-xs text-muted-foreground">{repo.default_branch}</span>
                                  </div>
                                  {repo.description && (
                                    <p className="text-xs text-muted-foreground mt-1 truncate">{repo.description}</p>
                                  )}
                                </li>
                              ))}
                            </ul>
                          ) : (
                            <div className="py-8 text-center text-muted-foreground">
                              {repoSearch ? 'No repositories found' : 'Search or browse your repositories'}
                            </div>
                          )}
                        </div>

                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="text-muted-foreground"
                          onClick={() => setShowManualInput(true)}
                        >
                          Or enter repository URL manually
                        </Button>
                      </>
                    )}
                  </div>
                ) : (
                  <>
                    <div className="space-y-2">
                      <Label htmlFor="repository_url">Repository URL</Label>
                      <Input
                        id="repository_url"
                        value={formData.repository_url}
                        onChange={(e) => setFormData({ ...formData, repository_url: e.target.value })}
                        placeholder={formData.git_provider_id ? 'user/repo or https://github.com/user/repo.git' : 'git@github.com:user/repo.git'}
                        required
                      />
                      {formData.git_provider_id && (
                        <p className="text-xs text-muted-foreground">
                          Can be HTTPS URL, SSH URL, or just owner/repo
                        </p>
                      )}
                      {canSearchRepos && showManualInput && (
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="text-muted-foreground p-0"
                          onClick={() => setShowManualInput(false)}
                        >
                          Search repositories instead
                        </Button>
                      )}
                    </div>
                  </>
                )}

                {(!canSearchRepos || showManualInput || !selectedRepo) && (
                  <div className="space-y-2">
                    <Label htmlFor="branch">Branch</Label>
                    <Input
                      id="branch"
                      value={formData.branch}
                      onChange={(e) => setFormData({ ...formData, branch: e.target.value })}
                      placeholder="main"
                    />
                  </div>
                )}

                {formData.type !== 'laravel' && (
                  <div className="space-y-2">
                    <Label htmlFor="build_command">Build Command</Label>
                    <Input
                      id="build_command"
                      value={formData.build_command}
                      onChange={(e) => setFormData({ ...formData, build_command: e.target.value })}
                      placeholder="npm run build"
                    />
                  </div>
                )}

                <div className="flex justify-between">
                  <Button type="button" variant="outline" onClick={() => setStep(1)}>
                    Back
                  </Button>
                  <Button type="submit" disabled={loading || !formData.repository_url}>
                    {loading && <LoadingSpinner size="sm" className="mr-2" />}
                    Create Application
                  </Button>
                </div>
              </div>
            )}

            {step === 3 && webhookInfo && (
              <div className="space-y-6">
                <Alert className="bg-green-50 border-green-200">
                  <AlertDescription>
                    <h3 className="text-green-800 font-medium">Application created successfully!</h3>
                    <p className="text-green-700 mt-1">
                      Configure the webhook in your git provider to enable automatic deployments.
                    </p>
                  </AlertDescription>
                </Alert>

                <div className="space-y-2">
                  <Label>Webhook URL</Label>
                  <div className="flex">
                    <Input
                      readOnly
                      value={webhookInfo.url}
                      className="rounded-r-none bg-muted"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-l-none border-l-0"
                      onClick={() => {
                        navigator.clipboard.writeText(webhookInfo.url)
                        toast.success('Copied!')
                      }}
                    >
                      Copy
                    </Button>
                  </div>
                </div>

                <div className="space-y-2">
                  <Label>Webhook Secret Token</Label>
                  <div className="flex">
                    <Input
                      readOnly
                      value={webhookInfo.secret}
                      className="rounded-r-none bg-muted"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      className="rounded-l-none border-l-0"
                      onClick={() => {
                        navigator.clipboard.writeText(webhookInfo.secret)
                        toast.success('Copied!')
                      }}
                    >
                      Copy
                    </Button>
                  </div>
                </div>

                <Alert className="bg-yellow-50 border-yellow-200">
                  <AlertDescription>
                    <h4 className="text-yellow-800 font-medium">Webhook Setup Instructions:</h4>
                    <ol className="list-decimal list-inside text-yellow-700 mt-2 space-y-1">
                      <li>Go to your repository Settings → Webhooks</li>
                      <li>Paste the Webhook URL above</li>
                      <li>Set the Secret Token</li>
                      <li>Select "Push events" as trigger</li>
                      <li>Save the webhook</li>
                    </ol>
                  </AlertDescription>
                </Alert>

                <div className="flex justify-end">
                  <Button type="button" onClick={() => navigate(`/servers/${serverId}`)}>
                    Back to Server
                  </Button>
                </div>
              </div>
            )}
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
