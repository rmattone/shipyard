import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { serverUsersApi, getErrorMessage, Server, ServerUser } from '@/services/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
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
import { LoadingSpinner } from '@/components/custom'
import { Plus } from 'lucide-react'

interface UsersSectionProps {
  server: Server
  onServerChange?: (server: Server) => void
}

export default function UsersSection({ server, onServerChange }: UsersSectionProps) {
  const [users, setUsers] = useState<ServerUser[]>([])
  const [loading, setLoading] = useState(true)

  const defaultUseAsDeployUser = (server.applications_count || 0) === 0

  const [showCreateDialog, setShowCreateDialog] = useState(false)
  const [createForm, setCreateForm] = useState({ username: 'shipyard', sudo: true, use_as_deploy_user: defaultUseAsDeployUser })
  const [creating, setCreating] = useState(false)

  const [userToSwitch, setUserToSwitch] = useState<ServerUser | null>(null)
  const [fixOwnership, setFixOwnership] = useState(true)
  const [switching, setSwitching] = useState(false)

  useEffect(() => {
    loadUsers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [server.id])

  const loadUsers = async () => {
    setLoading(true)
    try {
      const response = await serverUsersApi.list(server.id)
      setUsers(response.data.users)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to load server users'))
    } finally {
      setLoading(false)
    }
  }

  const openCreateDialog = () => {
    setCreateForm({ username: 'shipyard', sudo: true, use_as_deploy_user: defaultUseAsDeployUser })
    setShowCreateDialog(true)
  }

  const handleCreate = async () => {
    if (!createForm.username.trim()) {
      toast.error('Please enter a username')
      return
    }
    setCreating(true)
    try {
      const response = await serverUsersApi.create(server.id, createForm)
      toast.success('User creation started')
      setShowCreateDialog(false)
      if (response.data.server) {
        onServerChange?.({ ...server, ...response.data.server })
      }
      await loadUsers()
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to create user'))
    } finally {
      setCreating(false)
    }
  }

  const handleSetDeployUser = async (user: ServerUser) => {
    try {
      const response = await serverUsersApi.setDeployUser(server.id, { username: user.name })
      toast.success(`'${user.name}' is now the deploy user`)
      onServerChange?.({ ...server, ...response.data })
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to set deploy user'))
    }
  }

  const openSwitchDialog = (user: ServerUser) => {
    setFixOwnership(true)
    setUserToSwitch(user)
  }

  const handleSwitch = async () => {
    if (!userToSwitch) return
    setSwitching(true)
    try {
      const response = await serverUsersApi.switchUser(server.id, {
        username: userToSwitch.name,
        fix_ownership: fixOwnership,
      })
      toast.success(`Now connecting as '${userToSwitch.name}'`)
      onServerChange?.({ ...server, ...response.data })
      setUserToSwitch(null)
      await loadUsers()
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to switch connection user'))
    } finally {
      setSwitching(false)
    }
  }

  const hasApplications = (server.applications_count || 0) > 0

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <div>
          <CardTitle>Server users</CardTitle>
          <CardDescription>
            Manage the Linux users ShipYard knows about on this server.
          </CardDescription>
        </div>
        <Button onClick={openCreateDialog}>
          <Plus className="h-4 w-4 mr-2" />
          Create deploy user
        </Button>
      </CardHeader>
      <CardContent>
        {loading ? (
          <div className="flex items-center justify-center py-8">
            <LoadingSpinner size="lg" />
          </div>
        ) : users.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground">
            <p>No users found on this server.</p>
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>User</TableHead>
                <TableHead>UID</TableHead>
                <TableHead>Sudo</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {users.map((user) => (
                <TableRow key={user.name}>
                  <TableCell className="font-medium">
                    <div className="flex items-center gap-2">
                      {user.name}
                      {user.is_connection_user && (
                        <Badge variant="secondary">connection user</Badge>
                      )}
                      {server.deploy_user === user.name && (
                        <Badge variant="outline">deploy user</Badge>
                      )}
                    </div>
                  </TableCell>
                  <TableCell>{user.uid}</TableCell>
                  <TableCell>
                    {user.has_sudo && <Badge variant="outline">sudo</Badge>}
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2">
                      {server.deploy_user !== user.name && user.name !== 'root' && (
                        <Button variant="outline" size="sm" onClick={() => handleSetDeployUser(user)}>
                          Set as deploy user
                        </Button>
                      )}
                      {!user.is_connection_user && (
                        <Button variant="outline" size="sm" onClick={() => openSwitchDialog(user)}>
                          Use for connection
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>

      {/* Create user dialog */}
      <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create deploy user</DialogTitle>
            <DialogDescription>
              ShipYard installs its own SSH key for the new user automatically. A sudo
              user is required if you want to make it the connection user later.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="new-username">Username</Label>
              <Input
                id="new-username"
                value={createForm.username}
                onChange={(e) => setCreateForm({ ...createForm, username: e.target.value })}
                placeholder="deploy"
              />
            </div>
            <div className="flex items-center justify-between py-2">
              <div>
                <p className="font-medium text-sm">Grant passwordless sudo</p>
                <p className="text-sm text-muted-foreground">
                  Required to use this user as the connection user.
                </p>
              </div>
              <Switch
                checked={createForm.sudo}
                onCheckedChange={(checked) => setCreateForm({ ...createForm, sudo: checked })}
              />
            </div>
            <div className="flex items-center justify-between py-2">
              <div>
                <p className="font-medium text-sm">Use as deploy user</p>
                <p className="text-sm text-muted-foreground">
                  New applications will live in /home/{createForm.username || 'shipyard'} and run as this user.
                </p>
                {hasApplications && (
                  <p className="text-sm text-muted-foreground">
                    This server already has applications outside the new home directory; ShipYard
                    refuses to switch layouts while they exist.
                  </p>
                )}
              </div>
              <Switch
                checked={createForm.use_as_deploy_user}
                onCheckedChange={(checked) => setCreateForm({ ...createForm, use_as_deploy_user: checked })}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreateDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={creating}>
              {creating && <LoadingSpinner size="sm" className="mr-2" />}
              Create User
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Switch connection user confirmation */}
      <AlertDialog open={!!userToSwitch} onOpenChange={(open) => { if (!open) setUserToSwitch(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Use '{userToSwitch?.name}' for connection</AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div className="space-y-3 text-sm">
                <p>
                  ShipYard will verify that it can log in and use passwordless sudo as{' '}
                  '{userToSwitch?.name}' before switching. Nothing changes if that
                  verification fails.
                </p>
                {hasApplications && (
                  <div className="rounded-md border border-yellow-300 bg-yellow-50 p-3">
                    <p className="text-yellow-800">
                      This server has applications whose files are owned by the current
                      connection user. Fix ownership so the new user can manage them.
                    </p>
                    <div className="flex items-center justify-between mt-2">
                      <span className="text-yellow-900 font-medium">Fix file ownership</span>
                      <Switch checked={fixOwnership} onCheckedChange={setFixOwnership} />
                    </div>
                  </div>
                )}
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={switching}>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleSwitch} disabled={switching}>
              {switching && <LoadingSpinner size="sm" className="mr-2" />}
              Switch Connection User
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  )
}
