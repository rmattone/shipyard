import { useEffect, useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { serversApi, tagsApi, Server, Tag, TagColor } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { LoadingSpinner } from '@/components/custom'
import { TagBadge } from '@/components/custom/TagBadge'
import { ColorPicker } from '@/components/custom/ColorPicker'
import { Badge } from '@/components/ui/badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { Pencil, Trash2, Plus } from 'lucide-react'

type SettingsSection = 'general' | 'ssh' | 'tags' | 'danger'

export default function ServerSettings() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [server, setServer] = useState<Server | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [activeSection, setActiveSection] = useState<SettingsSection>('general')
  const [formData, setFormData] = useState({
    name: '',
    host: '',
    port: 22,
    username: '',
  })

  // Tags state
  const [tags, setTags] = useState<Tag[]>([])
  const [loadingTags, setLoadingTags] = useState(false)
  const [showTagDialog, setShowTagDialog] = useState(false)
  const [editingTag, setEditingTag] = useState<Tag | null>(null)
  const [tagFormData, setTagFormData] = useState({ name: '', color: 'gray' as TagColor })
  const [savingTag, setSavingTag] = useState(false)
  const [tagToDelete, setTagToDelete] = useState<Tag | null>(null)
  const [deletingTag, setDeletingTag] = useState(false)

  useEffect(() => {
    if (!id) return
    loadServer()
  }, [id])

  const loadServer = async () => {
    try {
      const response = await serversApi.get(parseInt(id!))
      setServer(response.data)
      setFormData({
        name: response.data.name,
        host: response.data.host,
        port: response.data.port,
        username: response.data.username,
      })
    } catch {
      toast.error('Failed to load server')
      navigate('/')
    } finally {
      setLoading(false)
    }
  }

  const handleSave = async () => {
    if (!id) return
    setSaving(true)
    try {
      const response = await serversApi.update(parseInt(id), formData)
      setServer(response.data)
      toast.success('Server updated')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to update server')
    } finally {
      setSaving(false)
    }
  }

  const handleTestConnection = async () => {
    if (!id) return
    setTesting(true)
    try {
      const response = await serversApi.testConnection(parseInt(id))
      if (response.data.success) {
        toast.success('Connection successful!')
      } else {
        toast.error(response.data.message || 'Connection failed')
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Connection test failed')
    } finally {
      setTesting(false)
    }
  }

  const handleDelete = async () => {
    if (!id) return
    setDeleting(true)
    try {
      await serversApi.delete(parseInt(id))
      toast.success('Server deleted')
      navigate('/')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete server')
    } finally {
      setDeleting(false)
      setShowDeleteDialog(false)
    }
  }

  const copyToClipboard = (text: string, label: string) => {
    navigator.clipboard.writeText(text)
    toast.success(`${label} copied!`)
  }

  // Load tags when switching to tags section
  useEffect(() => {
    if (activeSection === 'tags' && id) {
      loadTags()
    }
  }, [activeSection, id])

  const loadTags = async () => {
    if (!id) return
    setLoadingTags(true)
    try {
      const response = await tagsApi.list(parseInt(id))
      setTags(response.data)
    } catch {
      toast.error('Failed to load tags')
    } finally {
      setLoadingTags(false)
    }
  }

  const openCreateTagDialog = () => {
    setEditingTag(null)
    setTagFormData({ name: '', color: 'gray' })
    setShowTagDialog(true)
  }

  const openEditTagDialog = (tag: Tag) => {
    setEditingTag(tag)
    setTagFormData({ name: tag.name, color: tag.color as TagColor })
    setShowTagDialog(true)
  }

  const handleSaveTag = async () => {
    if (!id || !tagFormData.name.trim()) return
    setSavingTag(true)
    try {
      if (editingTag) {
        const response = await tagsApi.update(parseInt(id), editingTag.id, tagFormData)
        setTags(tags.map(t => t.id === editingTag.id ? response.data : t))
        toast.success('Tag updated')
      } else {
        const response = await tagsApi.create(parseInt(id), tagFormData)
        setTags([...tags, response.data])
        toast.success('Tag created')
      }
      setShowTagDialog(false)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save tag')
    } finally {
      setSavingTag(false)
    }
  }

  const handleDeleteTag = async () => {
    if (!id || !tagToDelete) return
    setDeletingTag(true)
    try {
      await tagsApi.delete(parseInt(id), tagToDelete.id)
      setTags(tags.filter(t => t.id !== tagToDelete.id))
      toast.success('Tag deleted')
      setTagToDelete(null)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete tag')
    } finally {
      setDeletingTag(false)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) return null

  const sidebarItems = [
    { id: 'general' as const, label: 'General' },
    ...(!server.is_local ? [{ id: 'ssh' as const, label: 'SSH' }] : []),
    { id: 'tags' as const, label: 'Tags' },
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
              <CardTitle className="flex items-center gap-2">
                Settings
                {server.is_local && (
                  <Badge variant="secondary">Local Server</Badge>
                )}
              </CardTitle>
              <CardDescription>
                Manage and configure your server's basic settings.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {server.is_local && (
                <Alert className="bg-green-50 border-green-200">
                  <AlertDescription>
                    <p className="text-sm text-green-800">
                      This is a local server. Commands run directly without SSH.
                    </p>
                  </AlertDescription>
                </Alert>
              )}

              {/* Name */}
              <div className="flex items-start justify-between py-4 border-b">
                <div>
                  <p className="font-medium">Name</p>
                  <p className="text-sm text-muted-foreground">
                    The name used to identify your server.
                  </p>
                </div>
                <Input
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  className="w-64"
                />
              </div>

              {!server.is_local && (
                <>
                  {/* IP Address */}
                  <div className="flex items-start justify-between py-4 border-b">
                    <div>
                      <p className="font-medium">IP address</p>
                      <p className="text-sm text-muted-foreground">
                        The public IP address used to connect to your server via SSH.
                      </p>
                    </div>
                    <Input
                      value={formData.host}
                      onChange={(e) => setFormData({ ...formData, host: e.target.value })}
                      className="w-64"
                    />
                  </div>

                  {/* SSH Port */}
                  <div className="flex items-start justify-between py-4 border-b">
                    <div>
                      <p className="font-medium">SSH port</p>
                      <p className="text-sm text-muted-foreground">
                        The port used to connect to your server via SSH.
                      </p>
                    </div>
                    <Input
                      type="number"
                      value={formData.port}
                      onChange={(e) => setFormData({ ...formData, port: parseInt(e.target.value) || 22 })}
                      className="w-64"
                    />
                  </div>

                  {/* SSH Username */}
                  <div className="flex items-start justify-between py-4 border-b">
                    <div>
                      <p className="font-medium">SSH username</p>
                      <p className="text-sm text-muted-foreground">
                        The username used to authenticate via SSH.
                      </p>
                    </div>
                    <Input
                      value={formData.username}
                      onChange={(e) => setFormData({ ...formData, username: e.target.value })}
                      className="w-64"
                    />
                  </div>
                </>
              )}

              {/* Status */}
              <div className="flex items-start justify-between py-4">
                <div>
                  <p className="font-medium">{server.is_local ? 'Local execution' : 'Connection status'}</p>
                  <p className="text-sm text-muted-foreground">
                    {server.is_local ? 'Test local command execution.' : 'Test the SSH connection to your server.'}
                  </p>
                </div>
                <Button variant="outline" onClick={handleTestConnection} disabled={testing}>
                  {testing && <LoadingSpinner size="sm" className="mr-2" />}
                  Test {server.is_local ? 'Execution' : 'Connection'}
                </Button>
              </div>

              <div className="flex justify-end pt-4">
                <Button onClick={handleSave} disabled={saving}>
                  {saving && <LoadingSpinner size="sm" className="mr-2" />}
                  Save Changes
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'ssh' && (
          <Card>
            <CardHeader>
              <CardTitle>SSH Keys</CardTitle>
              <CardDescription>
                Public keys for authenticating with Git providers and other services.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Server Public Key */}
              <div className="space-y-3">
                <div>
                  <p className="font-medium">Server Public Key</p>
                  <p className="text-sm text-muted-foreground">
                    Add this key to your Git provider to allow the server to clone repositories.
                  </p>
                </div>
                <div className="relative">
                  <Textarea
                    readOnly
                    value={`ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample... server-manager@${server.host}`}
                    className="font-mono text-xs h-24 resize-none"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    className="absolute top-2 right-2"
                    onClick={() => copyToClipboard(`ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample... server-manager@${server.host}`, 'Public key')}
                  >
                    Copy
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {activeSection === 'tags' && (
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle>Tags</CardTitle>
                <CardDescription>
                  Create and manage tags to organize your applications.
                </CardDescription>
              </div>
              <Button onClick={openCreateTagDialog}>
                <Plus className="h-4 w-4 mr-2" />
                New Tag
              </Button>
            </CardHeader>
            <CardContent>
              {loadingTags ? (
                <div className="flex items-center justify-center py-8">
                  <LoadingSpinner size="lg" />
                </div>
              ) : tags.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  <p>No tags yet. Create your first tag to organize applications.</p>
                </div>
              ) : (
                <div className="space-y-2">
                  {tags.map((tag) => (
                    <div
                      key={tag.id}
                      className="flex items-center justify-between py-3 px-4 rounded-lg border"
                    >
                      <div className="flex items-center gap-3">
                        <TagBadge tag={tag} />
                        <span className="text-sm text-muted-foreground">
                          {tag.applications_count || 0} {tag.applications_count === 1 ? 'app' : 'apps'}
                        </span>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => openEditTagDialog(tag)}
                        >
                          <Pencil className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setTagToDelete(tag)}
                        >
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
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
                  <p className="font-medium">Delete server</p>
                  <p className="text-sm text-muted-foreground">
                    Permanently delete this server and all its applications.
                  </p>
                  {(server.applications_count || 0) > 0 && (
                    <p className="text-sm text-destructive mt-1">
                      You must delete all applications first.
                    </p>
                  )}
                </div>
                <Button
                  variant="destructive"
                  onClick={() => setShowDeleteDialog(true)}
                  disabled={(server.applications_count || 0) > 0}
                >
                  Delete Server
                </Button>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Delete Server Confirmation Dialog */}
      <AlertDialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Server</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{server.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deleting}
            >
              {deleting ? 'Deleting...' : 'Delete Server'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Tag Create/Edit Dialog */}
      <Dialog open={showTagDialog} onOpenChange={setShowTagDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{editingTag ? 'Edit Tag' : 'Create Tag'}</DialogTitle>
            <DialogDescription>
              {editingTag
                ? 'Update the tag name and color.'
                : 'Create a new tag to organize your applications.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="tag-name">Name</Label>
              <Input
                id="tag-name"
                value={tagFormData.name}
                onChange={(e) => setTagFormData({ ...tagFormData, name: e.target.value })}
                placeholder="e.g., Production, API, Frontend"
                maxLength={50}
              />
            </div>
            <div className="space-y-2">
              <Label>Color</Label>
              <ColorPicker
                value={tagFormData.color}
                onChange={(color) => setTagFormData({ ...tagFormData, color })}
              />
            </div>
            <div className="pt-2">
              <Label>Preview</Label>
              <div className="mt-2">
                <TagBadge
                  tag={{ id: 0, server_id: 0, name: tagFormData.name || 'Tag Name', color: tagFormData.color, created_at: '', updated_at: '' }}
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowTagDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSaveTag} disabled={savingTag || !tagFormData.name.trim()}>
              {savingTag && <LoadingSpinner size="sm" className="mr-2" />}
              {editingTag ? 'Save Changes' : 'Create Tag'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Tag Confirmation Dialog */}
      <AlertDialog open={!!tagToDelete} onOpenChange={() => setTagToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Tag</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{tagToDelete?.name}"? This will remove the tag from all applications.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteTag}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={deletingTag}
            >
              {deletingTag ? 'Deleting...' : 'Delete Tag'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
