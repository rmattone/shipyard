import { useCallback, useEffect, useState } from 'react'
import { toast } from 'sonner'
import { formatDistanceToNow } from 'date-fns'
import {
  serverSshKeysApi,
  sshdApi,
  Server,
  ServerSshKey,
  AuthorizedKeyEntry,
  SshdSettings,
} from '@/services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Alert, AlertDescription } from '@/components/ui/alert'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
import { LoadingSpinner, StatusBadge } from '@/components/custom'
import { Plus, Trash2 } from 'lucide-react'

interface SshSettingsSectionProps {
  server: Server
}

const errorMessage = (error: unknown, fallback: string) => {
  const err = error as { response?: { data?: { message?: string } } }
  return err.response?.data?.message || fallback
}

export default function SshSettingsSection({ server }: SshSettingsSectionProps) {
  // Tracked SSH keys
  const [sshKeys, setSshKeys] = useState<ServerSshKey[]>([])
  const [loadingKeys, setLoadingKeys] = useState(true)
  const [showAddKeyDialog, setShowAddKeyDialog] = useState(false)
  const [keyForm, setKeyForm] = useState({ name: '', username: server.username, public_key: '' })
  const [addingKey, setAddingKey] = useState(false)
  const [keyToDelete, setKeyToDelete] = useState<ServerSshKey | null>(null)
  const [deletingKey, setDeletingKey] = useState(false)

  // Other authorized keys not managed by ShipYard
  const [authorizedKeys, setAuthorizedKeys] = useState<AuthorizedKeyEntry[]>([])
  const [loadingAuthorized, setLoadingAuthorized] = useState(true)

  // sshd hardening
  const [sshd, setSshd] = useState<SshdSettings | null>(null)
  const [loadingSshd, setLoadingSshd] = useState(true)
  const [sshdError, setSshdError] = useState<string | null>(null)
  const [pendingSshd, setPendingSshd] = useState<{
    password_authentication: 'yes' | 'no'
    permit_root_login: 'yes' | 'no' | 'prohibit-password'
  } | null>(null)
  const [savingSshd, setSavingSshd] = useState(false)

  const refreshKeys = useCallback(async () => {
    const response = await serverSshKeysApi.list(server.id)
    setSshKeys(response.data)
    return response.data
  }, [server.id])

  useEffect(() => {
    refreshKeys()
      .catch((error: unknown) => toast.error(errorMessage(error, 'Failed to load SSH keys')))
      .finally(() => setLoadingKeys(false))
  }, [refreshKeys])

  // While any key is installing or being removed, poll until it settles.
  const hasPendingKeys = sshKeys.some(k => k.status === 'installing' || k.status === 'removing')

  useEffect(() => {
    if (!hasPendingKeys) return
    const interval = setInterval(() => {
      refreshKeys().catch(() => {
        // Transient polling errors are ignored; the next tick retries.
      })
    }, 3000)
    return () => clearInterval(interval)
  }, [hasPendingKeys, refreshKeys])

  useEffect(() => {
    setLoadingAuthorized(true)
    serverSshKeysApi.authorized(server.id, server.username)
      .then(response => setAuthorizedKeys(response.data.keys))
      .catch(() => {
        // Silently degrade: this is a supplementary, best-effort list.
      })
      .finally(() => setLoadingAuthorized(false))
  }, [server.id, server.username])

  useEffect(() => {
    setLoadingSshd(true)
    setSshdError(null)
    sshdApi.get(server.id)
      .then(response => setSshd(response.data))
      .catch((error: unknown) => setSshdError(errorMessage(error, 'Could not read sshd settings')))
      .finally(() => setLoadingSshd(false))
  }, [server.id])

  const openAddKeyDialog = () => {
    setKeyForm({ name: '', username: server.username, public_key: '' })
    setShowAddKeyDialog(true)
  }

  const handleAddKey = async () => {
    if (!keyForm.name.trim() || !keyForm.username.trim() || !keyForm.public_key.trim()) {
      toast.error('Please fill in all fields')
      return
    }
    setAddingKey(true)
    try {
      await serverSshKeysApi.create(server.id, keyForm)
      toast.success('SSH key installation started')
      setShowAddKeyDialog(false)
      await refreshKeys()
    } catch (error: unknown) {
      toast.error(errorMessage(error, 'Failed to add SSH key'))
    } finally {
      setAddingKey(false)
    }
  }

  const handleDeleteKey = async () => {
    if (!keyToDelete) return
    setDeletingKey(true)
    try {
      await serverSshKeysApi.delete(server.id, keyToDelete.id)
      toast.success('SSH key removal started')
      await refreshKeys()
    } catch (error: unknown) {
      toast.error(errorMessage(error, 'Failed to remove SSH key'))
    } finally {
      setDeletingKey(false)
      setKeyToDelete(null)
    }
  }

  const requestSshdChange = (next: {
    password_authentication: 'yes' | 'no'
    permit_root_login: 'yes' | 'no' | 'prohibit-password'
  }) => setPendingSshd(next)

  const confirmSshdChange = async () => {
    if (!pendingSshd) return
    setSavingSshd(true)
    try {
      const response = await sshdApi.update(server.id, pendingSshd)
      setSshd(response.data)
      toast.success('SSH hardening settings updated')
    } catch (error: unknown) {
      toast.error(errorMessage(error, 'Failed to update sshd settings'))
    } finally {
      setSavingSshd(false)
      setPendingSshd(null)
    }
  }

  const isRoot = server.username === 'root'
  const untrackedKeys = authorizedKeys.filter(k => !k.tracked)
  const canEditSshd = !!sshd && sshd.supports_include !== false && !sshdError

  return (
    <TooltipProvider>
      <div className="space-y-6">
        {/* SSH keys */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <div>
              <CardTitle>SSH keys</CardTitle>
              <CardDescription>
                Public keys ShipYard has installed on this server.
              </CardDescription>
            </div>
            <Button onClick={openAddKeyDialog}>
              <Plus className="h-4 w-4 mr-2" />
              Add key
            </Button>
          </CardHeader>
          <CardContent>
            {loadingKeys ? (
              <div className="flex items-center justify-center py-8">
                <LoadingSpinner size="lg" />
              </div>
            ) : sshKeys.length === 0 ? (
              <div className="text-center py-8 text-muted-foreground">
                <p>No SSH keys tracked yet.</p>
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Username</TableHead>
                    <TableHead>Fingerprint</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Added</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sshKeys.map((key) => (
                    <TableRow key={key.id}>
                      <TableCell className="font-medium">{key.name}</TableCell>
                      <TableCell className="font-mono text-xs">{key.username}</TableCell>
                      <TableCell className="font-mono text-xs">
                        ...{key.fingerprint.slice(-12)}
                      </TableCell>
                      <TableCell>
                        <StatusBadge status={key.status} />
                        {key.status === 'failed' && key.error && (
                          <p className="text-xs text-destructive mt-1 max-w-xs truncate" title={key.error}>
                            {key.error}
                          </p>
                        )}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {formatDistanceToNow(new Date(key.created_at), { addSuffix: true })}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setKeyToDelete(key)}
                          disabled={key.status === 'removing'}
                        >
                          <Trash2 className="h-4 w-4 text-destructive" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}

            {!loadingAuthorized && untrackedKeys.length > 0 && (
              <div className="mt-6 pt-4 border-t">
                <p className="text-sm font-medium mb-2">Other keys on this server</p>
                <p className="text-xs text-muted-foreground mb-3">
                  These keys were added outside ShipYard and are not managed here.
                </p>
                <div className="space-y-1">
                  {untrackedKeys.map((key, idx) => (
                    <div key={idx} className="text-xs font-mono text-muted-foreground truncate">
                      {key.type} {key.comment}
                      {key.fingerprint && <span> ...{key.fingerprint.slice(-12)}</span>}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* sshd hardening */}
        <Card>
          <CardHeader>
            <CardTitle>SSH hardening</CardTitle>
            <CardDescription>
              Configure sshd authentication behavior on this server.
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            {sshdError && (
              <Alert variant="destructive">
                <AlertDescription>{sshdError}</AlertDescription>
              </Alert>
            )}

            {loadingSshd ? (
              <div className="flex items-center justify-center py-8">
                <LoadingSpinner size="lg" />
              </div>
            ) : sshd && (
              <>
                {sshd.supports_include === false && (
                  <Alert>
                    <AlertDescription>
                      This server's sshd does not support drop-in configuration
                      (Include directive). SSH hardening is unavailable here.
                    </AlertDescription>
                  </Alert>
                )}

                <div className="flex items-start justify-between py-4 border-b">
                  <div>
                    <p className="font-medium">Password authentication</p>
                    <p className="text-sm text-muted-foreground">
                      Allow logging in with a password instead of an SSH key.
                    </p>
                  </div>
                  <Switch
                    checked={sshd.password_authentication === 'yes'}
                    disabled={!canEditSshd || savingSshd}
                    onCheckedChange={(checked) => requestSshdChange({
                      password_authentication: checked ? 'yes' : 'no',
                      permit_root_login: sshd.permit_root_login,
                    })}
                  />
                </div>

                <div className="flex items-start justify-between py-4">
                  <div>
                    <p className="font-medium">Permit root login</p>
                    <p className="text-sm text-muted-foreground">
                      Controls whether the root user may log in over SSH.
                    </p>
                  </div>
                  <Select
                    value={sshd.permit_root_login}
                    disabled={!canEditSshd || savingSshd}
                    onValueChange={(value) => requestSshdChange({
                      password_authentication: sshd.password_authentication,
                      permit_root_login: value as 'yes' | 'no' | 'prohibit-password',
                    })}
                  >
                    <SelectTrigger className="w-56">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="yes">yes</SelectItem>
                      <SelectItem value="prohibit-password">prohibit-password</SelectItem>
                      {isRoot ? (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <span tabIndex={0} className="block">
                              <SelectItem value="no" disabled>no</SelectItem>
                            </span>
                          </TooltipTrigger>
                          <TooltipContent side="right">
                            Switch ShipYard to a non-root user first
                          </TooltipContent>
                        </Tooltip>
                      ) : (
                        <SelectItem value="no">no</SelectItem>
                      )}
                    </SelectContent>
                  </Select>
                </div>
              </>
            )}
          </CardContent>
        </Card>

        {/* Add key dialog */}
        <Dialog open={showAddKeyDialog} onOpenChange={setShowAddKeyDialog}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Add SSH key</DialogTitle>
              <DialogDescription>
                Installs a public key into the target user's authorized_keys on this server.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-4 py-4">
              <div className="space-y-2">
                <Label htmlFor="key-name">Name</Label>
                <Input
                  id="key-name"
                  value={keyForm.name}
                  onChange={(e) => setKeyForm({ ...keyForm, name: e.target.value })}
                  placeholder="My laptop"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="key-username">Username</Label>
                <Input
                  id="key-username"
                  value={keyForm.username}
                  onChange={(e) => setKeyForm({ ...keyForm, username: e.target.value })}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="key-public">Public key</Label>
                <Textarea
                  id="key-public"
                  value={keyForm.public_key}
                  onChange={(e) => setKeyForm({ ...keyForm, public_key: e.target.value })}
                  placeholder="ssh-ed25519 AAAA..."
                  className="font-mono text-xs"
                  rows={4}
                />
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setShowAddKeyDialog(false)}>
                Cancel
              </Button>
              <Button onClick={handleAddKey} disabled={addingKey}>
                {addingKey && <LoadingSpinner size="sm" className="mr-2" />}
                Add Key
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete key confirmation */}
        <AlertDialog open={!!keyToDelete} onOpenChange={(open) => { if (!open) setKeyToDelete(null) }}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Remove SSH key</AlertDialogTitle>
              <AlertDialogDescription>
                Remove "{keyToDelete?.name}" from {keyToDelete?.username}'s authorized_keys
                on this server?
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={deletingKey}>Cancel</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDeleteKey}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                disabled={deletingKey}
              >
                {deletingKey && <LoadingSpinner size="sm" className="mr-2" />}
                Remove
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        {/* sshd change confirmation */}
        <AlertDialog open={!!pendingSshd} onOpenChange={(open) => { if (!open) setPendingSshd(null) }}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Change SSH authentication settings</AlertDialogTitle>
              <AlertDialogDescription>
                Changing SSH authentication can lock you out if misconfigured. ShipYard
                re-verifies its connection before applying and validates the config, but
                confirm you understand the risk before continuing.
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel disabled={savingSshd}>Cancel</AlertDialogCancel>
              <AlertDialogAction onClick={confirmSshdChange} disabled={savingSshd}>
                {savingSshd && <LoadingSpinner size="sm" className="mr-2" />}
                Apply Changes
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </TooltipProvider>
  )
}
