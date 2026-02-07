import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { applicationsApi, gitProvidersApi, envApi, serversApi, tagsApi, Application, GitProvider, Tag } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
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
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { LoadingSpinner } from '@/components/custom'
import { TagMultiSelect } from '@/components/custom/TagMultiSelect'
import { EyeIcon, EyeSlashIcon } from '@heroicons/react/24/outline'
import { cn } from '@/lib/utils'

type SettingsSection = 'general' | 'deployments' | 'environment' | 'danger'

export default function AppSettings() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [app, setApp] = useState<Application | null>(null)
  const [gitProviders, setGitProviders] = useState<GitProvider[]>([])
  const [nodeVersions, setNodeVersions] = useState<string[]>([])
  const [loadingNodeVersions, setLoadingNodeVersions] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const [activeSection, setActiveSection] = useState<SettingsSection>('general')

  // Form states
  const [deployScript, setDeployScript] = useState('')
  const [formData, setFormData] = useState({
    name: '',
    type: 'laravel' as 'laravel' | 'nodejs' | 'static',
    node_version: 'default',
    deploy_path: '',
    repository_url: '',
    branch: '',
    git_provider_id: '',
  })

  // Environment variables (full .env content)
  const [envContent, setEnvContent] = useState('')
  const [originalEnvContent, setOriginalEnvContent] = useState('')
  const [envRevealed, setEnvRevealed] = useState(false)
  const [savingEnv, setSavingEnv] = useState(false)

  // Tags state
  const [serverTags, setServerTags] = useState<Tag[]>([])
  const [selectedTagIds, setSelectedTagIds] = useState<number[]>([])
  const [savingTags, setSavingTags] = useState(false)

  const appId = parseInt(id || '0')

  useEffect(() => {
    if (!appId) return
    Promise.all([
      applicationsApi.get(appId),
      applicationsApi.getDeployScript(appId),
      gitProvidersApi.list(),
      envApi.getContent(appId).catch(() => ({ data: { content: '' } })),
    ])
      .then(([appRes, scriptRes, providersRes, envRes]) => {
        const appData = appRes.data
        setApp(appData)
        setDeployScript(scriptRes.data.deploy_script)
        setGitProviders(providersRes.data)
        setEnvContent(envRes.data.content || '')
        setOriginalEnvContent(envRes.data.content || '')
        setFormData({
          name: appData.name,
          type: appData.type,
          node_version: appData.node_version || 'default',
          deploy_path: appData.deploy_path,
          repository_url: appData.repository_url,
          branch: appData.branch,
          git_provider_id: appData.git_provider_id?.toString() || '',
        })

        // Set initial selected tags
        if (appData.tags) {
          setSelectedTagIds(appData.tags.map(t => t.id))
        }

        // Load Node.js versions and tags from the server
        if (appData.server_id) {
          setLoadingNodeVersions(true)
          serversApi.getNodeVersions(appData.server_id)
            .then(res => setNodeVersions(res.data.versions))
            .catch(() => setNodeVersions([]))
            .finally(() => setLoadingNodeVersions(false))

          // Load server tags
          tagsApi.list(appData.server_id)
            .then(res => setServerTags(res.data))
            .catch(() => setServerTags([]))
        }
      })
      .catch(() => {
        toast.error('Failed to load application')
        navigate('/')
      })
      .finally(() => setLoading(false))
  }, [appId, navigate])

  const handleSaveGeneral = async () => {
    if (!app) return
    setSaving(true)
    try {
      const response = await applicationsApi.update(app.id, {
        name: formData.name,
        type: formData.type,
        node_version: formData.node_version === 'default' ? null : formData.node_version,
        deploy_path: formData.deploy_path,
        repository_url: formData.repository_url,
        branch: formData.branch,
        git_provider_id: formData.git_provider_id ? parseInt(formData.git_provider_id) : null,
      })
      setApp(response.data)
      toast.success('Settings saved')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save settings')
    } finally {
      setSaving(false)
    }
  }

  const handleSaveScript = async () => {
    if (!app) return
    setSaving(true)
    try {
      await applicationsApi.updateDeployScript(app.id, deployScript)
      toast.success('Deploy script saved')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save script')
    } finally {
      setSaving(false)
    }
  }

  const handleResetScript = async () => {
    if (!app) return
    try {
      const res = await applicationsApi.getDefaultScript(app.type)
      setDeployScript(res.data.deploy_script)
      toast.success('Reset to default script')
    } catch {
      toast.error('Failed to get default script')
    }
  }

  // Environment save handler
  const handleSaveEnv = async () => {
    setSavingEnv(true)
    try {
      await envApi.updateContent(appId, envContent)
      setOriginalEnvContent(envContent)
      toast.success('Environment variables saved')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save environment variables')
    } finally {
      setSavingEnv(false)
    }
  }

  const hasEnvChanges = envContent !== originalEnvContent

  const handleSaveTags = async (newTagIds: number[]) => {
    if (!app) return
    setSavingTags(true)
    try {
      await applicationsApi.syncTags(app.id, newTagIds)
      setSelectedTagIds(newTagIds)
      toast.success('Tags updated')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to update tags')
    } finally {
      setSavingTags(false)
    }
  }

  const handleDeleteApp = async () => {
    if (!app) return
    setDeleting(true)
    try {
      await applicationsApi.delete(app.id)
      toast.success('Application deleted')
      navigate(`/servers/${app.server_id}`)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete application')
    } finally {
      setDeleting(false)
      setShowDeleteDialog(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!app) return null

  const sidebarItems = [
    { id: 'general' as const, label: 'General' },
    { id: 'deployments' as const, label: 'Deployments' },
    { id: 'environment' as const, label: 'Environment' },
    { id: 'danger' as const, label: 'Danger Zone' },
  ]

  return (
    <div className="flex gap-8">
      {/* Sidebar */}
      <div className="w-48 flex-shrink-0">
        <h1 className="text-2xl font-bold mb-6">Settings</h1>
        <nav className="space-y-1">
          {sidebarItems.map((item) => (
            <button
              key={item.id}
              onClick={() => setActiveSection(item.id)}
              className={cn(
                'w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors',
                activeSection === item.id
                  ? 'bg-muted text-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
              )}
            >
              {item.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Content */}
      <div className="flex-1 max-w-2xl">
        {activeSection === 'general' && (
          <Card>
            <CardHeader>
              <CardTitle>General Settings</CardTitle>
              <CardDescription>
                Manage your application's basic configuration.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Name */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Application name</p>
                  <p className="text-sm text-muted-foreground">
                    The name used to identify your application.
                  </p>
                </div>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-64"
                />
              </div>

              {/* Framework */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Framework</p>
                  <p className="text-sm text-muted-foreground">
                    The type of application framework.
                  </p>
                </div>
                <Select
                  value={formData.type}
                  onValueChange={(value) => setFormData({ ...formData, type: value as 'laravel' | 'nodejs' | 'static', node_version: 'default' })}
                >
                  <SelectTrigger className="w-64">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="laravel">Laravel (PHP)</SelectItem>
                    <SelectItem value="nodejs">Node.js</SelectItem>
                    <SelectItem value="static">Vue/React</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Node.js Version */}
              {(formData.type === 'nodejs' || formData.type === 'static') && (
                <div className="flex items-start justify-between py-4 border-b">
                  <div>
                    <p className="font-medium">Node.js Version</p>
                    <p className="text-sm text-muted-foreground">
                      {nodeVersions.length === 0 && !loadingNodeVersions
                        ? 'No Node.js versions found via nvm on the server.'
                        : 'Select a specific Node.js version or use server default.'}
                    </p>
                  </div>
                  <Select
                    value={formData.node_version}
                    onValueChange={(value) => setFormData({ ...formData, node_version: value })}
                    disabled={loadingNodeVersions}
                  >
                    <SelectTrigger className="w-64">
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
                </div>
              )}

              {/* Root Directory */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Root directory</p>
                  <p className="text-sm text-muted-foreground">
                    The path where your application is deployed.
                  </p>
                </div>
                <Input
                  value={formData.deploy_path}
                  onChange={(e) => setFormData({ ...formData, deploy_path: e.target.value })}
                  className="w-64 font-mono text-sm"
                />
              </div>

              {/* Git Provider */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Git provider</p>
                  <p className="text-sm text-muted-foreground">
                    The provider for your Git repository.
                  </p>
                </div>
                <Select
                  value={formData.git_provider_id || 'none'}
                  onValueChange={(value) => setFormData({ ...formData, git_provider_id: value === 'none' ? '' : value })}
                >
                  <SelectTrigger className="w-64">
                    <SelectValue placeholder="None (SSH keys)" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">None (SSH keys)</SelectItem>
                    {gitProviders.map((p) => (
                      <SelectItem key={p.id} value={p.id.toString()}>
                        {p.name} ({p.type})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Repository URL */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Repository URL</p>
                  <p className="text-sm text-muted-foreground">
                    The Git repository to clone.
                  </p>
                </div>
                <Input
                  value={formData.repository_url}
                  onChange={(e) => setFormData({ ...formData, repository_url: e.target.value })}
                  className="w-64 font-mono text-sm"
                />
              </div>

              {/* Branch */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Branch</p>
                  <p className="text-sm text-muted-foreground">
                    The branch to deploy from.
                  </p>
                </div>
                <Input
                  value={formData.branch}
                  onChange={(e) => setFormData({ ...formData, branch: e.target.value })}
                  className="w-64"
                />
              </div>

              {/* Tags */}
              <div className="flex items-start justify-between py-4">
                <div>
                  <p className="font-medium">Tags</p>
                  <p className="text-sm text-muted-foreground">
                    Organize your application with tags.
                  </p>
                </div>
                <div className="w-64">
                  <TagMultiSelect
                    tags={serverTags}
                    selectedIds={selectedTagIds}
                    onChange={handleSaveTags}
                    disabled={savingTags}
                    placeholder="Select tags..."
                  />
                </div>
              </div>

              <div className="flex justify-end pt-4">
                <Button onClick={handleSaveGeneral} disabled={saving}>
                  {saving && <LoadingSpinner size="sm" className="mr-2" />}
                  Save Changes
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'deployments' && (
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Deploy Script</CardTitle>
                  <CardDescription>
                    This script runs each time you deploy your application.
                  </CardDescription>
                </div>
                <Button variant="outline" size="sm" onClick={handleResetScript}>
                  Reset to Default
                </Button>
              </div>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <p className="text-sm text-muted-foreground">
                  Available variables: <code className="bg-muted px-1 py-0.5 rounded text-xs">$DEPLOY_PATH</code>{' '}
                  <code className="bg-muted px-1 py-0.5 rounded text-xs">$BRANCH</code>{' '}
                  <code className="bg-muted px-1 py-0.5 rounded text-xs">$APP_NAME</code>{' '}
                  <code className="bg-muted px-1 py-0.5 rounded text-xs">$DOMAIN</code>{' '}
                  <code className="bg-muted px-1 py-0.5 rounded text-xs">$NODE_VERSION</code>
                </p>
              </div>
              <Textarea
                value={deployScript}
                onChange={(e) => setDeployScript(e.target.value)}
                rows={18}
                className="font-mono text-sm"
                spellCheck={false}
              />
              <div className="flex justify-end">
                <Button onClick={handleSaveScript} disabled={saving}>
                  {saving && <LoadingSpinner size="sm" className="mr-2" />}
                  Save Script
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'environment' && (
          <Card>
            <CardHeader>
              <CardTitle>Environment</CardTitle>
              <CardDescription>
                Below you may edit the <code className="bg-muted px-1.5 py-0.5 rounded text-sm font-mono">.env</code> file for your application, which is a standard default environment file that typically loaded by applications.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label>Environment variables</Label>
                <p className="text-sm text-muted-foreground">
                  Your application's environment variables.
                </p>
              </div>

              <div className="relative">
                <Textarea
                  value={envContent}
                  onChange={(e) => setEnvContent(e.target.value)}
                  placeholder={`APP_NAME=MyApp\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://example.com\n\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=myapp\nDB_USERNAME=root\nDB_PASSWORD=`}
                  className={`font-mono text-sm min-h-[300px] resize-y ${!envRevealed ? 'blur-sm select-none' : ''}`}
                  readOnly={!envRevealed}
                />

                {!envRevealed && (
                  <div className="absolute inset-0 flex flex-col items-center justify-center bg-background/50 rounded-md">
                    <p className="text-sm text-muted-foreground mb-4">
                      Environment variables should not be shared publicly.
                    </p>
                    <Button onClick={() => setEnvRevealed(true)}>
                      <EyeIcon className="h-4 w-4 mr-2" />
                      Reveal
                    </Button>
                  </div>
                )}
              </div>

              {envRevealed && (
                <div className="flex items-center justify-between">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setEnvRevealed(false)}
                  >
                    <EyeSlashIcon className="h-4 w-4 mr-2" />
                    Hide
                  </Button>

                  <div className="flex items-center gap-4">
                    {hasEnvChanges && (
                      <span className="text-sm text-muted-foreground">Unsaved changes</span>
                    )}
                    <Button onClick={handleSaveEnv} disabled={savingEnv || !hasEnvChanges}>
                      {savingEnv && <LoadingSpinner size="sm" className="mr-2" />}
                      Save
                    </Button>
                  </div>
                </div>
              )}

              <div className="p-4 rounded-lg border border-yellow-200 bg-yellow-50 dark:bg-yellow-950/20 dark:border-yellow-900">
                <p className="text-sm text-yellow-800 dark:text-yellow-200">
                  <strong>Security Notice:</strong> Environment variables are encrypted at rest and only decrypted during deployment.
                </p>
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'danger' && (
          <Card className="border-destructive">
            <CardHeader>
              <CardTitle className="text-destructive">Danger Zone</CardTitle>
              <CardDescription>
                Irreversible and destructive actions.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex items-start justify-between py-4">
                <div>
                  <p className="font-medium">Delete application</p>
                  <p className="text-sm text-muted-foreground">
                    Permanently delete this application and all its data including deployments and environment variables.
                  </p>
                </div>
                <Button variant="destructive" onClick={() => setShowDeleteDialog(true)}>
                  Delete Application
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Delete Dialog */}
      <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Application</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{app.name}"? This will remove all deployments,
              environment variables, and configuration. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteApp}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleting}
            >
              {deleting ? 'Deleting...' : 'Delete Application'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
