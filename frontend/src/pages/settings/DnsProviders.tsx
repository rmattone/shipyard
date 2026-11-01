import { useState, useEffect } from 'react'
import { toast } from 'sonner'
import { GlobeAltIcon, PlusIcon } from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import { dnsProvidersApi, type DnsProvider } from '@/services/api'

const emptyForm = { name: '', api_token: '' }

export function DnsProviders() {
  const [providers, setProviders] = useState<DnsProvider[]>([])
  const [loading, setLoading] = useState(true)
  const [showDialog, setShowDialog] = useState(false)
  const [editing, setEditing] = useState<DnsProvider | null>(null)
  const [providerToDelete, setProviderToDelete] = useState<DnsProvider | null>(null)
  const [testing, setTesting] = useState<number | null>(null)
  const [saving, setSaving] = useState(false)
  const [formData, setFormData] = useState(emptyForm)

  useEffect(() => {
    dnsProvidersApi.list()
      .then(response => setProviders(response.data))
      .catch((error: unknown) => {
        const err = error as { response?: { data?: { message?: string } } }
        toast.error(err.response?.data?.message || 'Failed to load DNS providers')
      })
      .finally(() => setLoading(false))
  }, [])

  const openAddDialog = () => {
    setEditing(null)
    setFormData(emptyForm)
    setShowDialog(true)
  }

  const openEditDialog = (provider: DnsProvider) => {
    setEditing(provider)
    setFormData({ name: provider.name, api_token: '' })
    setShowDialog(true)
  }

  const handleSave = async () => {
    if (!formData.name.trim()) {
      toast.error('Please give the provider a name')
      return
    }
    if (!editing && !formData.api_token.trim()) {
      toast.error('Enter the Cloudflare API token')
      return
    }

    setSaving(true)
    try {
      if (editing) {
        const response = await dnsProvidersApi.update(editing.id, {
          name: formData.name,
          api_token: formData.api_token || undefined,
        })
        setProviders(providers.map(p => p.id === editing.id ? response.data : p))
        toast.success('DNS provider updated')
      } else {
        const response = await dnsProvidersApi.create({
          name: formData.name,
          type: 'cloudflare',
          api_token: formData.api_token,
        })
        setProviders([...providers, response.data])
        const zones = response.data.zone_count
        toast.success(`Cloudflare connected (${zones} ${zones === 1 ? 'zone' : 'zones'} accessible)`)
      }
      setShowDialog(false)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      const firstError = err.response?.data?.errors
        ? Object.values(err.response.data.errors)[0]?.[0]
        : undefined
      toast.error(firstError || err.response?.data?.message || 'Failed to save DNS provider')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!providerToDelete) return

    try {
      await dnsProvidersApi.delete(providerToDelete.id)
      setProviders(providers.filter(p => p.id !== providerToDelete.id))
      toast.success('DNS provider removed')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to remove DNS provider')
    } finally {
      setProviderToDelete(null)
    }
  }

  const handleTest = async (provider: DnsProvider) => {
    setTesting(provider.id)
    try {
      const response = await dnsProvidersApi.test(provider.id)
      toast.success(response.data.message)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Token check failed')
    } finally {
      setTesting(null)
    }
  }

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
              <CardTitle>DNS</CardTitle>
              <CardDescription>
                Connect Cloudflare so adding a domain also creates its DNS record.
              </CardDescription>
            </div>
            <Button onClick={openAddDialog}>
              <PlusIcon className="h-4 w-4 mr-2" />
              Add Provider
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {providers.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-8 text-center">
              <GlobeAltIcon className="h-10 w-10 text-muted-foreground mb-3" />
              <p className="text-muted-foreground">
                No DNS provider yet. Without one, DNS records are created by hand at your registrar.
              </p>
            </div>
          ) : (
            <div className="space-y-3">
              {providers.map((provider) => (
                <div key={provider.id} className="flex items-center justify-between p-4 border rounded-lg gap-4">
                  <div className="flex items-center gap-3 min-w-0">
                    <div className="h-10 w-10 shrink-0 rounded-lg bg-orange-500/10 flex items-center justify-center">
                      <GlobeAltIcon className="h-5 w-5 text-orange-500" />
                    </div>
                    <div className="min-w-0">
                      <div className="flex items-center gap-2">
                        <p className="font-medium truncate">{provider.name}</p>
                        <Badge variant="outline">Cloudflare</Badge>
                      </div>
                      <p className="text-sm text-muted-foreground">
                        {provider.domains_count ?? 0} {provider.domains_count === 1 ? 'domain' : 'domains'} managed
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 shrink-0">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleTest(provider)}
                      disabled={testing === provider.id}
                    >
                      {testing === provider.id ? <LoadingSpinner size="sm" /> : 'Test'}
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => openEditDialog(provider)}>
                      Edit
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setProviderToDelete(provider)}
                      disabled={(provider.domains_count ?? 0) > 0}
                      title={(provider.domains_count ?? 0) > 0 ? 'Stop managing DNS for its domains first' : undefined}
                    >
                      Delete
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit DNS Provider' : 'Connect Cloudflare'}</DialogTitle>
            <DialogDescription>
              {editing
                ? 'The stored token is kept unless you enter a new one.'
                : 'The token is checked against Cloudflare before it is saved, and stored encrypted.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="dns-name">Name *</Label>
              <Input
                id="dns-name"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g. Cloudflare main account"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="dns-token">API Token {editing ? '' : '*'}</Label>
              <Input
                id="dns-token"
                type="password"
                value={formData.api_token}
                onChange={(e) => setFormData({ ...formData, api_token: e.target.value })}
                placeholder={editing ? 'Leave blank to keep current' : 'Cloudflare API token'}
              />
              <p className="text-xs text-muted-foreground">
                Create it under My Profile › API Tokens with the "Edit zone DNS" template, limited to the zones ShipYard should manage.
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              {editing ? 'Save Changes' : 'Connect'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={!!providerToDelete} onOpenChange={() => setProviderToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove DNS Provider</AlertDialogTitle>
            <AlertDialogDescription>
              "{providerToDelete?.name}" will be disconnected. Existing DNS records in Cloudflare are not changed.
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
