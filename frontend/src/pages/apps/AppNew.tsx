import { useState, useEffect, useRef, useCallback, FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { applicationsApi, serversApi, gitProvidersApi, databasesApi, Server, GitProvider, Repository, Database, getErrorMessage } from '../../services/api'
import { useNavigation } from '@/contexts/NavigationContext'
import { Textarea } from '@/components/ui/textarea'
import { Configuration, SetupProgress, setupApplication } from './new/setup'
import { ConfigurationStep } from './new/ConfigurationStep'
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
  const { refreshApps } = useNavigation()
  const { serverId } = useParams<{ serverId: string }>()
  const [loading, setLoading] = useState(false)
  const [loadingServer, setLoadingServer] = useState(true)
  const [server, setServer] = useState<Server | null>(null)
  const [gitProviders, setGitProviders] = useState<GitProvider[]>([])
  const [step, setStep] = useState(1)
  const heading = useRef<HTMLHeadingElement>(null)
  const progress = useRef<SetupProgress>({})
  const submitting = useRef(false)
  const [setupStarted, setSetupStarted] = useState(false)
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [databases, setDatabases] = useState<Database[]>([])
  const [databaseError, setDatabaseError] = useState(false)
  const [loadingDatabases, setLoadingDatabases] = useState(true)
  const [pathEdited, setPathEdited] = useState(false)
  const [loadingScript, setLoadingScript] = useState(false)
  const [configuration, setConfiguration] = useState<Configuration>({
    domain: '', environment: '', productionDefaults: false,
    databaseId: 'none', databaseName: '', databaseUsername: '', databasePassword: '',
  })
  const selectedDatabase = databases.find(database => String(database.id) === configuration.databaseId)


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
    deploy_script: '',
    php_version: '',
    port: '3000',
  })

  const selectedProvider = gitProviders.find(p => p.id.toString() === formData.git_provider_id)
  const canSearchRepos = selectedProvider?.has_access_token

  useEffect(() => {
    if (!serverId) {
      navigate('/')
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

      databasesApi.list(parseInt(serverId))
        .then(res => setDatabases(res.data.filter(database => database.status === 'active')))
        .catch(() => setDatabaseError(true))
        .finally(() => setLoadingDatabases(false))

      // Load Node.js versions from the server
      setLoadingNodeVersions(true)
      serversApi.getNodeVersions(parseInt(serverId))
        .then(res => setNodeVersions(res.data.versions))
        .catch(() => setNodeVersions([]))
        .finally(() => setLoadingNodeVersions(false))
    }).catch(() => {
      toast.error('Server not found')
      navigate('/')
    }).finally(() => {
      setLoadingServer(false)
    })
  }, [serverId, navigate])

  const repositoryRequest = useRef(0)
  const branchRequest = useRef(0)
  const loadRepositories = useCallback(async (search?: string) => {
    if (formData.git_provider_id === 'none') return
    const request = ++repositoryRequest.current
    setLoadingRepos(true)
    try {
      const res = await gitProvidersApi.listRepositories(parseInt(formData.git_provider_id), search, 1, 50)
      if (request === repositoryRequest.current) setRepositories(res.data.repositories)
    } catch {
      if (request === repositoryRequest.current) {
        toast.error('Could not load repositories. Try again or enter a URL manually.')
        setRepositories([])
      }
    } finally {
      if (request === repositoryRequest.current) setLoadingRepos(false)
    }
  }, [formData.git_provider_id])

  useEffect(() => {
    setSelectedRepo(null)
    setShowManualInput(false)
    setRepositories([])
    setBranches([])
    setRepoSearch('')
    setLoadingBranches(false)
    setLoadingRepos(false)
    branchRequest.current++
    if (selectedProvider?.has_access_token) void loadRepositories()
    const repositoryCounter = repositoryRequest
    const branchCounter = branchRequest
    return () => { repositoryCounter.current++; branchCounter.current++ }
  }, [loadRepositories, selectedProvider?.has_access_token])

  const handleRepoSearch = () => {
    loadRepositories(repoSearch)
  }

  const handleSelectRepo = async (repo: Repository) => {
    const request = ++branchRequest.current
    setSelectedRepo(repo)
    setFormData(prev => ({
      ...prev,
      name: prev.name || repo.full_name.split('/').pop() || '',
      repository_url: selectedProvider?.has_private_key ? repo.ssh_url : repo.https_url,
      branch: repo.default_branch,
    }))

    // Load branches for selected repo
    if (selectedProvider?.has_access_token) {
      setLoadingBranches(true)
      setBranches([])
      try {
        const res = await gitProvidersApi.listBranches(selectedProvider.id, repo.full_name)
        if (request === branchRequest.current) setBranches(res.data.branches)
      } catch {
        // Fallback to just the default branch
        if (request === branchRequest.current) setBranches([{ name: repo.default_branch, default: true }])
      } finally {
        if (request === branchRequest.current) setLoadingBranches(false)
      }
    }
  }

  useEffect(() => {
    heading.current?.focus({ preventScroll: true })
    heading.current?.scrollIntoView({ block: 'start' })
  }, [step])

  const deployFolder = pathEdited ? formData.deploy_path : formData.name.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')

  const validStep = () => {
    if (step === 1) return Boolean(formData.repository_url.trim() && formData.branch.trim() && !loadingBranches)
    if (step === 2) return Boolean(formData.name.trim() && deployFolder && !loadingScript)
    if (step === 3) return configuration.databaseId === 'none' || Boolean(selectedDatabase && /^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/.test(configuration.databaseName) && /^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/.test(configuration.databaseUsername) && configuration.databasePassword.length >= 8)
    return true
  }

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    if (submitting.current || !serverId || !validStep()) return
    if (step < 4) {
      setError('')
      setStep(step + 1)
      return
    }
    submitting.current = true
    setLoading(true)
    setSetupStarted(true)
    setError('')
    try {
      const application = await setupApplication({
        server_id: parseInt(serverId),
        git_provider_id: formData.git_provider_id !== 'none' ? parseInt(formData.git_provider_id) : null,
        name: formData.name.trim(), type: formData.type,
        node_version: formData.node_version === 'default' ? null : formData.node_version,
        php_version: formData.type === 'laravel' ? formData.php_version || null : null,
        ...(formData.type === 'nodejs' ? { port: Number(formData.port) } : {}),
        repository_url: formData.repository_url.trim(), branch: formData.branch.trim(),
        deploy_path: `${deployBase}/${deployFolder}`,
        domain: configuration.domain.trim(), deployment_strategy: 'atomic',
        ...(formData.deploy_script.trim() ? { deploy_script: formData.deploy_script } : {}),
      }, configuration, selectedDatabase, progress.current, setStatus)
      await refreshApps()
      setStatus('Starting first deployment…')
      if (progress.current.deploymentRequested && !progress.current.deploymentId) {
        const existing = await applicationsApi.getDeployments(application.id)
        progress.current.deploymentId = existing.data.data[0]?.id
      }
      if (!progress.current.deploymentId) {
        progress.current.deploymentRequested = true
        const response = await applicationsApi.deploy(application.id)
        progress.current.deploymentId = response.data.deployment_id
      }
      navigate(`/apps/${application.id}/deployments/${progress.current.deploymentId}`)
    } catch (err) {
      setError(err instanceof Error && !('response' in err) ? err.message : getErrorMessage(err, 'Setup could not finish. Check the connection and try again.'))
      if (!progress.current.application) setSetupStarted(false)
      else await refreshApps()
    } finally {
      submitting.current = false
      setLoading(false)
    }
  }

  const loadDefaultScript = async () => {
    setLoadingScript(true)
    try {
      const response = await applicationsApi.getDefaultScript(formData.type)
      setFormData(prev => ({ ...prev, deploy_script: response.data.deploy_script }))
    } catch {
      toast.error('Could not load the deployment script')
    } finally {
      setLoadingScript(false)
    }
  }

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

  const deployBase = server?.default_deploy_base ?? '/var/www/shipyard'

  const steps = [
    { number: 1, name: 'Repository' },
    { number: 2, name: 'Application' },
    { number: 3, name: 'Configuration' },
    { number: 4, name: 'Deploy' },
  ]

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <div>
        <h1 ref={heading} tabIndex={-1} className="text-2xl font-bold scroll-mt-24 outline-none">Add New Application</h1>
        <p className="text-muted-foreground mt-1">
          Creating application on <span className="font-medium text-foreground">{server.name}</span>
        </p>
      </div>

      <nav aria-label="Progress">
        <ol className="grid grid-cols-2 gap-3 sm:flex sm:items-center">
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
            {error && <Alert variant="destructive" className="mb-6" role="alert"><AlertDescription>{error}</AlertDescription></Alert>}
            <fieldset disabled={loading || setupStarted} className="min-w-0">
            {step === 2 && (
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
                    disabled={loadingScript}
                    onValueChange={(value) => { setFormData({ ...formData, type: value as 'laravel' | 'nodejs' | 'static', node_version: 'default', deploy_script: '' }); if (value === 'static') setConfiguration(prev => ({ ...prev, databaseId: 'none' })) }}
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
                      {deployBase}/
                    </span>
                    <Input
                      value={deployFolder}
                      onChange={(e) => { setPathEdited(true); setFormData({ ...formData, deploy_path: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-') }) }}
                      placeholder="my-app"
                      className="font-mono text-sm rounded-l-none"
                    />
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Folder name for your application.
                  </p>
                </div>

                {formData.type === 'laravel' && <div className="space-y-2">
                  <Label htmlFor="php-version">PHP version</Label>
                  <Input id="php-version" placeholder="Server default" pattern="[0-9]+\.[0-9]+" value={formData.php_version} onChange={e => setFormData({ ...formData, php_version: e.target.value })} />
                  <p className="text-xs text-muted-foreground">Use an installed PHP version, such as 8.3.</p>
                </div>}
                {formData.type === 'nodejs' && <div className="space-y-2">
                  <Label htmlFor="app-port">Application port</Label>
                  <Input id="app-port" type="number" min={1} max={65535} required value={formData.port} onChange={e => setFormData({ ...formData, port: e.target.value })} />
                  <p className="text-xs text-muted-foreground">Choose an unused port on this server.</p>
                </div>}
                <details className="space-y-3">
                  <summary className="cursor-pointer text-sm font-medium">Deployment script and build settings</summary>
                  <p className="text-xs text-muted-foreground">Leave empty to use the default atomic deployment script. Load it to customize install and build commands.</p>
                  <Button type="button" variant="outline" disabled={loadingScript} onClick={loadDefaultScript}>{loadingScript ? 'Loading…' : 'Load default script'}</Button>
                  <Label htmlFor="deploy-script" className="block">Deployment script</Label>
                  <Textarea id="deploy-script" rows={12} className="font-mono text-xs" value={formData.deploy_script} onChange={e => setFormData({ ...formData, deploy_script: e.target.value })} />
                </details>

                <div className="flex justify-between">
                  <Button type="button" variant="outline" onClick={() => setStep(1)}>
                    Back
                  </Button>
                  <Button type="submit" disabled={!validStep()}>
                    Next
                  </Button>
                </div>
              </div>
            )}

            {step === 1 && (
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
                                  branchRequest.current++
                                  setLoadingBranches(false)
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
                                  role="button" tabIndex={0}
                                  onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); void handleSelectRepo(repo) } }}
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

                <div className="flex justify-between">
                  <Button type="button" variant="outline" onClick={() => navigate(`/servers/${serverId}`)}>
                    Cancel
                  </Button>
                  <Button type="submit" disabled={!validStep()}>
                    Next
                  </Button>
                </div>
              </div>
            )}

            {step === 3 && (
              <ConfigurationStep value={configuration} onChange={setConfiguration} databases={databases}
                databaseError={databaseError} loadingDatabases={loadingDatabases} type={formData.type} />
            )}
            </fieldset>
            {step === 3 && <div className="flex justify-between mt-6">
              <Button type="button" variant="outline" onClick={() => setStep(2)}>Back</Button>
              <Button type="submit" disabled={!validStep()}>Review deployment</Button>
            </div>}
            {step === 4 && <div className="space-y-6">
              <div><h2 className="text-lg font-semibold">Ready for your first deployment</h2>
                <p className="text-sm text-muted-foreground">Review the setup. ShipYard will configure the application, then open its live deployment log.</p></div>
              <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-sm [overflow-wrap:anywhere]">
                <dt className="text-muted-foreground">Application</dt><dd>{formData.name} · {formData.type}</dd>
                <dt className="text-muted-foreground">Server</dt><dd>{server.name}</dd>
                <dt className="text-muted-foreground">Repository</dt><dd>{formData.repository_url} · {formData.branch}</dd>
                <dt className="text-muted-foreground">Runtime</dt><dd>{formData.type === 'laravel' ? `PHP ${formData.php_version || 'server default'}` : `Node.js ${formData.node_version}`}{formData.type === 'nodejs' ? ` · port ${formData.port}` : ''}</dd>
                <dt className="text-muted-foreground">Deploy path</dt><dd>{deployBase}/{deployFolder}</dd>
                <dt className="text-muted-foreground">Domain</dt><dd>{configuration.domain || 'Add a domain later'}</dd>
                <dt className="text-muted-foreground">Environment</dt><dd>{configuration.productionDefaults ? 'Production defaults + your overrides' : configuration.environment.trim() ? 'Your environment file' : 'No environment file supplied'}</dd>
                <dt className="text-muted-foreground">Database</dt><dd>{selectedDatabase ? `${configuration.databaseName} on ${selectedDatabase.name} · user ${configuration.databaseUsername}` : 'No database will be created'}</dd>
                <dt className="text-muted-foreground">Deploy script</dt><dd>{formData.deploy_script.trim() ? 'Custom script' : 'Default atomic deployment script'}</dd>
              </dl>
              <p className="text-sm text-muted-foreground">You can configure HTTPS and automatic deployments from the application after its first deployment.</p>
              {status && <p role="status" className="flex items-center gap-2 text-sm">{loading && <LoadingSpinner size="sm" />}{status}</p>}
              {error && progress.current.application && <p className="text-sm text-muted-foreground">Application created. Completed setup steps are kept for this session. Retry to continue, or open the application to resolve the error. If a request lost its response, check the created resources before retrying.</p>}
              <div className="flex flex-wrap justify-between gap-3">
                {!setupStarted && <Button type="button" variant="outline" onClick={() => setStep(3)}>Back</Button>}
                {progress.current.application && !loading && <Button type="button" variant="outline" onClick={() => navigate(`/apps/${progress.current.application!.id}`)}>Open application</Button>}
                <Button type="submit" disabled={loading}>{loading ? 'Setting up…' : setupStarted ? 'Retry remaining steps' : 'Create and deploy'}</Button>
              </div>
            </div>}
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
