import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import {
  BellIcon,
  PlusIcon,
  PaperAirplaneIcon,
  ChatBubbleLeftRightIcon,
  EnvelopeIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { LoadingSpinner } from '@/components/custom'
import {
  notificationChannelsApi,
  type NotificationChannel,
  type NotificationEvent,
} from '@/services/api'

const TYPE_META: Record<NotificationChannel['type'], { label: string; icon: typeof BellIcon }> = {
  discord: { label: 'Discord', icon: ChatBubbleLeftRightIcon },
  telegram: { label: 'Telegram', icon: PaperAirplaneIcon },
  email: { label: 'Email (Resend)', icon: EnvelopeIcon },
}

const EVENT_META: { value: NotificationEvent; label: string }[] = [
  { value: 'deployment_succeeded', label: 'Deployment succeeded' },
  { value: 'deployment_failed', label: 'Deployment failed' },
]

const emptyForm = {
  name: '',
  type: 'discord' as NotificationChannel['type'],
  events: ['deployment_failed'] as NotificationEvent[],
  is_enabled: true,
  webhook_url: '',
  bot_token: '',
  chat_id: '',
  api_key: '',
  from_email: '',
  to_email: '',
}

export function NotificationChannels() {
  const [channels, setChannels] = useState<NotificationChannel[]>([])
  const [loading, setLoading] = useState(true)

  const [showDialog, setShowDialog] = useState(false)
  const [editing, setEditing] = useState<NotificationChannel | null>(null)
  const [channelToDelete, setChannelToDelete] = useState<NotificationChannel | null>(null)
  const [testing, setTesting] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)
  const [formData, setFormData] = useState(emptyForm)

  useEffect(() => {
    notificationChannelsApi.list()
      .then(response => setChannels(response.data))
      .catch((error: unknown) => {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load notification channels')
      })
      .finally(() => setLoading(false))
  }, [])

  const openAddDialog = () => {
    setEditing(null)
    setFormData(emptyForm)
    setShowDialog(true)
  }

  const openEditDialog = (channel: NotificationChannel) => {
    setEditing(channel)
    setFormData({
      ...emptyForm,
      name: channel.name,
      type: channel.type,
      events: channel.events,
      is_enabled: channel.is_enabled,
      chat_id: channel.config_display.chat_id ?? '',
      from_email: channel.config_display.from_email ?? '',
      to_email: channel.config_display.to_email ?? '',
    })
    setShowDialog(true)
  }

  const buildConfig = (): Record<string, string> => {
    const config: Record<string, string> = {}
    if (formData.type === 'discord') {
      if (formData.webhook_url) config.webhook_url = formData.webhook_url
    } else if (formData.type === 'telegram') {
      if (formData.bot_token) config.bot_token = formData.bot_token
      config.chat_id = formData.chat_id
    } else {
      if (formData.api_key) config.api_key = formData.api_key
      config.from_email = formData.from_email
      config.to_email = formData.to_email
    }
    return config
  }

  const handleSave = async () => {
    if (!formData.name.trim()) {
      toast.error('Please give the channel a name')
      return
    }
    if (formData.events.length === 0) {
      toast.error('Select at least one event')
      return
    }

    setSaving(true)
    try {
      if (editing) {
        const response = await notificationChannelsApi.update(editing.id, {
          name: formData.name,
          events: formData.events,
          is_enabled: formData.is_enabled,
          config: buildConfig(),
        })
        setChannels(channels.map(c => c.id === editing.id ? response.data : c))
        toast.success('Channel updated')
      } else {
        const response = await notificationChannelsApi.create({
          name: formData.name,
          type: formData.type,
          events: formData.events,
          is_enabled: formData.is_enabled,
          config: buildConfig(),
        })
        setChannels([...channels, response.data])
        toast.success('Channel added')
      }
      setShowDialog(false)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors)[0]?.[0]
        : undefined
      toast.error(firstError || err.response?.data?.message || 'Failed to save channel')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!channelToDelete) return

    try {
      await notificationChannelsApi.delete(channelToDelete.id)
      setChannels(channels.filter(c => c.id !== channelToDelete.id))
      toast.success('Channel removed')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to remove channel')
    } finally {
      setChannelToDelete(null)
    }
  }

  const handleTest = async (channel: NotificationChannel) => {
    setTesting(channel.id)
    try {
      const response = await notificationChannelsApi.test(channel.id)
      toast.success(response.data.message)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Test notification failed')
    } finally {
      setTesting(null)
    }
  }

  const toggleEvent = (event: NotificationEvent, checked: boolean) => {
    setFormData({
      ...formData,
      events: checked
        ? [...formData.events, event]
        : formData.events.filter(e => e !== event),
    })
  }

  const secretPlaceholder = editing ? 'Leave blank to keep current' : undefined

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  return (
    <>
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Notifications</CardTitle>
              <CardDescription>
                Get notified on Discord, Telegram or email when deployments finish.
              </CardDescription>
            </div>
            <Button onClick={openAddDialog}>
              <PlusIcon className="h-4 w-4 mr-2" />
              Add Channel
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {channels.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <BellIcon className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-muted-foreground">
                No channels yet. Add one to hear about failed deploys without watching the dashboard.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {channels.map((channel) => {
                const meta = TYPE_META[channel.type]
                const Icon = meta.icon
                return (
                  <div key={channel.id} className="flex items-center justify-between p-4 border rounded-lg gap-4">
                    <div className="flex items-center gap-3 min-w-0">
                      <div className="h-10 w-10 shrink-0 rounded-lg bg-violet-500/10 flex items-center justify-center">
                        <Icon className="h-5 w-5 text-violet-500" />
                      </div>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <p className="font-medium truncate">{channel.name}</p>
                          <Badge variant="outline">{meta.label}</Badge>
                          {!channel.is_enabled && (
                            <Badge variant="secondary" className="bg-gray-100 text-gray-500">disabled</Badge>
                          )}
                        </div>
                        <p className="text-sm text-muted-foreground truncate">
                          {channel.events.map(e => EVENT_META.find(m => m.value === e)?.label).join(', ')}
                          {channel.type === 'email' && channel.config_display.to_email && (
                            <span className="ml-2">→ {channel.config_display.to_email}</span>
                          )}
                        </p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleTest(channel)}
                        disabled={testing === channel.id}
                      >
                        {testing === channel.id ? <LoadingSpinner size="sm" /> : 'Test'}
                      </Button>
                      <Button variant="outline" size="sm" onClick={() => openEditDialog(channel)}>
                        Edit
                      </Button>
                      <Button variant="outline" size="sm" onClick={() => setChannelToDelete(channel)}>
                        Delete
                      </Button>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Add / Edit Dialog */}
      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit Channel' : 'Add Notification Channel'}</DialogTitle>
            <DialogDescription>
              {editing
                ? 'Update the channel. Secrets are kept unless you enter a new value.'
                : 'Deployment results are delivered to this channel.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="channel-name">Name *</Label>
                <Input
                  id="channel-name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  placeholder="e.g. Ops Discord"
                />
              </div>
              <div className="space-y-2">
                <Label>Type</Label>
                <Select
                  value={formData.type}
                  onValueChange={(type: NotificationChannel['type']) => setFormData({ ...formData, type })}
                  disabled={!!editing}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {(Object.keys(TYPE_META) as NotificationChannel['type'][]).map(type => (
                      <SelectItem key={type} value={type}>{TYPE_META[type].label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>

            {formData.type === 'discord' && (
              <div className="space-y-2">
                <Label htmlFor="webhook_url">Webhook URL *</Label>
                <Input
                  id="webhook_url"
                  type="password"
                  value={formData.webhook_url}
                  onChange={(e) => setFormData({ ...formData, webhook_url: e.target.value })}
                  placeholder={secretPlaceholder ?? 'https://discord.com/api/webhooks/...'}
                />
              </div>
            )}

            {formData.type === 'telegram' && (
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="bot_token">Bot Token *</Label>
                  <Input
                    id="bot_token"
                    type="password"
                    value={formData.bot_token}
                    onChange={(e) => setFormData({ ...formData, bot_token: e.target.value })}
                    placeholder={secretPlaceholder ?? '123456789:AA...'}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="chat_id">Chat ID *</Label>
                  <Input
                    id="chat_id"
                    value={formData.chat_id}
                    onChange={(e) => setFormData({ ...formData, chat_id: e.target.value })}
                    placeholder="-100123456 or @channel"
                  />
                  <p className="text-xs text-muted-foreground">Numeric ID or @channelname</p>
                </div>
              </div>
            )}

            {formData.type === 'email' && (
              <>
                <div className="space-y-2">
                  <Label htmlFor="api_key">Resend API Key *</Label>
                  <Input
                    id="api_key"
                    type="password"
                    value={formData.api_key}
                    onChange={(e) => setFormData({ ...formData, api_key: e.target.value })}
                    placeholder={secretPlaceholder ?? 're_...'}
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="from_email">From *</Label>
                    <Input
                      id="from_email"
                      value={formData.from_email}
                      onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                      placeholder="shipyard@yourdomain.com"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="to_email">To *</Label>
                    <Input
                      id="to_email"
                      value={formData.to_email}
                      onChange={(e) => setFormData({ ...formData, to_email: e.target.value })}
                      placeholder="you@example.com"
                    />
                  </div>
                </div>
              </>
            )}

            <div className="space-y-2">
              <Label>Events *</Label>
              <div className="space-y-2">
                {EVENT_META.map(event => (
                  <div key={event.value} className="flex items-center gap-2">
                    <Checkbox
                      id={event.value}
                      checked={formData.events.includes(event.value)}
                      onCheckedChange={(checked) => toggleEvent(event.value, checked === true)}
                    />
                    <Label htmlFor={event.value} className="font-normal">{event.label}</Label>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex items-center gap-2">
              <Switch
                id="is_enabled"
                checked={formData.is_enabled}
                onCheckedChange={(is_enabled) => setFormData({ ...formData, is_enabled })}
              />
              <Label htmlFor="is_enabled" className="font-normal">Enabled</Label>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              {editing ? 'Save Changes' : 'Add Channel'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!channelToDelete} onOpenChange={() => setChannelToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Notification Channel</AlertDialogTitle>
            <AlertDialogDescription>
              "{channelToDelete?.name}" will no longer receive deployment notifications.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleDelete} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
              Remove
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
