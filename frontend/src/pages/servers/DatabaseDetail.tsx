import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import {
  CircleStackIcon,
  PlusIcon,
  TrashIcon,
  UserIcon,
  KeyIcon,
  ArrowLeftIcon,
  CheckCircleIcon,
  ClipboardDocumentIcon,
  ArrowPathIcon,
  EyeIcon,
  EyeSlashIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { LoadingSpinner } from '@/components/custom'
import {
  databasesApi,
  databaseUsersApi,
  type Database,
  type DatabaseUser,
} from '@/services/api'

const MYSQL_PRIVILEGES = ['ALL', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'INDEX', 'ALTER']
const POSTGRESQL_PRIVILEGES = ['ALL', 'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'CONNECT', 'TEMPORARY']

export default function DatabaseDetail() {
  const { id, databaseId } = useParams<{ id: string; databaseId: string }>()
  const navigate = useNavigate()
  const serverId = parseInt(id!)
  const dbId = parseInt(databaseId!)

  const [database, setDatabase] = useState<Database | null>(null)
  const [remoteDatabases, setRemoteDatabases] = useState<string[]>([])
  const [users, setUsers] = useState<DatabaseUser[]>([])
  const [remoteUsers, setRemoteUsers] = useState<{ username: string; host: string }[]>([])
  const [loading, setLoading] = useState(true)
  const [loadingDatabases, setLoadingDatabases] = useState(false)
  const [loadingUsers, setLoadingUsers] = useState(false)

  // Database dialogs
  const [showCreateDbDialog, setShowCreateDbDialog] = useState(false)
  const [dbToDelete, setDbToDelete] = useState<string | null>(null)
  const [dbFormData, setDbFormData] = useState({ name: '', charset: '', collation: '' })
  const [creatingDb, setCreatingDb] = useState(false)
  const [deletingDb, setDeletingDb] = useState(false)

  // User dialogs
  const [showCreateUserDialog, setShowCreateUserDialog] = useState(false)
  const [userToDelete, setUserToDelete] = useState<DatabaseUser | null>(null)
  const [userFormData, setUserFormData] = useState({ username: '', password: '', host: '%' })
  const [creatingUser, setCreatingUser] = useState(false)
  const [deletingUser, setDeletingUser] = useState(false)
  const [showUserPassword, setShowUserPassword] = useState(false)
  const [createdCredentials, setCreatedCredentials] = useState<{ username: string; password: string; host: string } | null>(null)

  // Privileges dialog
  const [showPrivilegesDialog, setShowPrivilegesDialog] = useState(false)
  const [selectedUser, setSelectedUser] = useState<DatabaseUser | null>(null)
  const [privilegesFormData, setPrivilegesFormData] = useState({
    database: '',
    privileges: [] as string[],
  })
  const [grantingPrivileges, setGrantingPrivileges] = useState(false)

  useEffect(() => {
    loadData()
  }, [serverId, dbId])

  const loadData = async () => {
    try {
      const dbRes = await databasesApi.get(serverId, dbId)
      setDatabase(dbRes.data)
      await Promise.all([loadRemoteDatabases(), loadUsers()])
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load database')
    } finally {
      setLoading(false)
    }
  }

  const loadRemoteDatabases = async () => {
    setLoadingDatabases(true)
    try {
      const response = await databasesApi.listRemoteDatabases(serverId, dbId)
      setRemoteDatabases(response.data.databases)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load databases')
    } finally {
      setLoadingDatabases(false)
    }
  }

  const loadUsers = async () => {
    setLoadingUsers(true)
    try {
      const [usersRes, remoteUsersRes] = await Promise.all([
        databaseUsersApi.list(serverId, dbId),
        databaseUsersApi.listRemote(serverId, dbId),
      ])
      setUsers(usersRes.data)
      setRemoteUsers(remoteUsersRes.data.users)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load users')
    } finally {
      setLoadingUsers(false)
    }
  }

  const handleCreateDatabase = async () => {
    if (!dbFormData.name) {
      toast.error('Database name is required')
      return
    }

    setCreatingDb(true)
    try {
      await databasesApi.createRemoteDatabase(serverId, dbId, dbFormData)
      toast.success(`Database "${dbFormData.name}" created`)
      setShowCreateDbDialog(false)
      setDbFormData({ name: '', charset: '', collation: '' })
      await loadRemoteDatabases()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create database')
    } finally {
      setCreatingDb(false)
    }
  }

  const handleDropDatabase = async () => {
    if (!dbToDelete) return

    setDeletingDb(true)
    try {
      await databasesApi.dropRemoteDatabase(serverId, dbId, dbToDelete)
      toast.success(`Database "${dbToDelete}" dropped`)
      setDbToDelete(null)
      await loadRemoteDatabases()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to drop database')
    } finally {
      setDeletingDb(false)
    }
  }

  const generatePassword = () => {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
    const array = new Uint8Array(32)
    crypto.getRandomValues(array)
    const password = Array.from(array, (b) => chars[b % chars.length]).join('')
    setUserFormData({ ...userFormData, password })
    setShowUserPassword(true)
  }

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text)
    toast.success('Copied to clipboard')
  }

  const handleCreateUser = async () => {
    if (!userFormData.username || !userFormData.password) {
      toast.error('Username and password are required')
      return
    }

    setCreatingUser(true)
    try {
      const response = await databaseUsersApi.create(serverId, dbId, userFormData)
      setUsers([...users, response.data])
      toast.success(`User "${userFormData.username}" created`)
      setShowCreateUserDialog(false)
      // Show credentials dialog so user can copy them
      setCreatedCredentials({
        username: userFormData.username,
        password: userFormData.password,
        host: userFormData.host,
      })
      setUserFormData({ username: '', password: '', host: database?.type === 'mysql' ? '%' : '*' })
      setShowUserPassword(false)
      await loadUsers()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to create user')
    } finally {
      setCreatingUser(false)
    }
  }

  const handleDeleteUser = async () => {
    if (!userToDelete) return

    setDeletingUser(true)
    try {
      await databaseUsersApi.delete(serverId, dbId, userToDelete.id)
      setUsers(users.filter(u => u.id !== userToDelete.id))
      toast.success(`User "${userToDelete.username}" deleted`)
      setUserToDelete(null)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to delete user')
    } finally {
      setDeletingUser(false)
    }
  }

  const openPrivilegesDialog = (user: DatabaseUser) => {
    setSelectedUser(user)
    setPrivilegesFormData({ database: '', privileges: [] })
    setShowPrivilegesDialog(true)
  }

  const handleGrantPrivileges = async () => {
    if (!selectedUser || !privilegesFormData.database || privilegesFormData.privileges.length === 0) {
      toast.error('Please select a database and privileges')
      return
    }

    setGrantingPrivileges(true)
    try {
      const response = await databaseUsersApi.grantPrivileges(
        serverId,
        dbId,
        selectedUser.id,
        privilegesFormData
      )
      setUsers(users.map(u => u.id === selectedUser.id ? response.data.user : u))
      toast.success('Privileges granted successfully')
      setShowPrivilegesDialog(false)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to grant privileges')
    } finally {
      setGrantingPrivileges(false)
    }
  }

  const togglePrivilege = (privilege: string) => {
    setPrivilegesFormData(prev => ({
      ...prev,
      privileges: prev.privileges.includes(privilege)
        ? prev.privileges.filter(p => p !== privilege)
        : [...prev.privileges, privilege],
    }))
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!database) {
    return (
      <div className="text-center py-12">
        <p className="text-muted-foreground">Database connection not found</p>
      </div>
    )
  }

  const privileges = database.type === 'mysql' ? MYSQL_PRIVILEGES : POSTGRESQL_PRIVILEGES

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" onClick={() => navigate(`/servers/${serverId}/databases`)}>
          <ArrowLeftIcon className="h-4 w-4 mr-2" />
          Back
        </Button>
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-bold">{database.name}</h1>
            <Badge variant="outline" className="capitalize">{database.type}</Badge>
          </div>
          <p className="text-muted-foreground">
            {database.admin_user}@{database.host}:{database.port}
          </p>
        </div>
      </div>

      <Tabs defaultValue="databases">
        <TabsList>
          <TabsTrigger value="databases">
            <CircleStackIcon className="h-4 w-4 mr-2" />
            Databases
          </TabsTrigger>
          <TabsTrigger value="users">
            <UserIcon className="h-4 w-4 mr-2" />
            Users
          </TabsTrigger>
        </TabsList>

        <TabsContent value="databases" className="mt-6">
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Databases</CardTitle>
                  <CardDescription>
                    Manage databases on this {database.type} server
                  </CardDescription>
                </div>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" onClick={loadRemoteDatabases} disabled={loadingDatabases}>
                    {loadingDatabases ? <LoadingSpinner size="sm" /> : 'Refresh'}
                  </Button>
                  <Button size="sm" onClick={() => setShowCreateDbDialog(true)}>
                    <PlusIcon className="h-4 w-4 mr-2" />
                    Create Database
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {loadingDatabases ? (
                <div className="flex justify-center py-8">
                  <LoadingSpinner size="lg" />
                </div>
              ) : remoteDatabases.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  No databases found
                </div>
              ) : (
                <div className="space-y-2">
                  {remoteDatabases.map((dbName) => (
                    <div
                      key={dbName}
                      className="flex items-center justify-between p-3 border rounded-lg"
                    >
                      <div className="flex items-center gap-3">
                        <CircleStackIcon className="h-5 w-5 text-muted-foreground" />
                        <span className="font-medium">{dbName}</span>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDbToDelete(dbName)}
                        className="text-destructive hover:text-destructive"
                      >
                        <TrashIcon className="h-4 w-4" />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="users" className="mt-6 space-y-6">
          {/* Tracked Users */}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>Managed Users</CardTitle>
                  <CardDescription>
                    Users created and managed through this application
                  </CardDescription>
                </div>
                <Button size="sm" onClick={() => setShowCreateUserDialog(true)}>
                  <PlusIcon className="h-4 w-4 mr-2" />
                  Create User
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {loadingUsers ? (
                <div className="flex justify-center py-8">
                  <LoadingSpinner size="lg" />
                </div>
              ) : users.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  No managed users. Create one to get started.
                </div>
              ) : (
                <div className="space-y-3">
                  {users.map((user) => (
                    <div
                      key={user.id}
                      className="flex items-center justify-between p-4 border rounded-lg"
                    >
                      <div className="flex items-center gap-3">
                        <div className="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center">
                          <UserIcon className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                          <p className="font-medium">{user.username}@{user.host}</p>
                          {user.privileges && Object.keys(user.privileges).length > 0 && (
                            <div className="flex gap-1 mt-1 flex-wrap">
                              {Object.entries(user.privileges).map(([db, privs]) => (
                                <Badge key={db} variant="secondary" className="text-xs">
                                  {db}: {Array.isArray(privs) ? privs.join(', ') : privs}
                                </Badge>
                              ))}
                            </div>
                          )}
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openPrivilegesDialog(user)}
                        >
                          <KeyIcon className="h-4 w-4 mr-1" />
                          Privileges
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setUserToDelete(user)}
                          className="text-destructive hover:text-destructive"
                        >
                          <TrashIcon className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Remote Users (read-only list) */}
          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle>All Server Users</CardTitle>
                  <CardDescription>
                    All users on the database server (read-only)
                  </CardDescription>
                </div>
                <Button variant="outline" size="sm" onClick={loadUsers} disabled={loadingUsers}>
                  {loadingUsers ? <LoadingSpinner size="sm" /> : 'Refresh'}
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              {loadingUsers ? (
                <div className="flex justify-center py-8">
                  <LoadingSpinner size="lg" />
                </div>
              ) : remoteUsers.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  No users found
                </div>
              ) : (
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                  {remoteUsers.map((user, idx) => (
                    <div
                      key={idx}
                      className="flex items-center gap-2 p-2 border rounded text-sm"
                    >
                      <UserIcon className="h-4 w-4 text-muted-foreground" />
                      <span>{user.username}@{user.host}</span>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Create Database Dialog */}
      <Dialog open={showCreateDbDialog} onOpenChange={setShowCreateDbDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create Database</DialogTitle>
            <DialogDescription>
              Create a new database on the {database.type} server.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="dbName">Database Name *</Label>
              <Input
                id="dbName"
                value={dbFormData.name}
                onChange={(e) => setDbFormData({ ...dbFormData, name: e.target.value })}
                placeholder="my_database"
              />
            </div>
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="dbCharset">Charset (optional)</Label>
                <Input
                  id="dbCharset"
                  value={dbFormData.charset}
                  onChange={(e) => setDbFormData({ ...dbFormData, charset: e.target.value })}
                  placeholder={database.type === 'mysql' ? 'utf8mb4' : 'UTF8'}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="dbCollation">Collation (optional)</Label>
                <Input
                  id="dbCollation"
                  value={dbFormData.collation}
                  onChange={(e) => setDbFormData({ ...dbFormData, collation: e.target.value })}
                  placeholder={database.type === 'mysql' ? 'utf8mb4_unicode_ci' : 'en_US.UTF-8'}
                />
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreateDbDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreateDatabase} disabled={creatingDb}>
              {creatingDb ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Drop Database Confirmation */}
      <AlertDialog open={!!dbToDelete} onOpenChange={() => setDbToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Drop Database</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to drop the database "{dbToDelete}"?
              This action cannot be undone and all data will be lost.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDropDatabase}
              disabled={deletingDb}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deletingDb ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Drop Database
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Create User Dialog */}
      <Dialog open={showCreateUserDialog} onOpenChange={setShowCreateUserDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create User</DialogTitle>
            <DialogDescription>
              Create a new database user.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="username">Username *</Label>
              <Input
                id="username"
                value={userFormData.username}
                onChange={(e) => setUserFormData({ ...userFormData, username: e.target.value })}
                placeholder="app_user"
              />
            </div>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="password">Password *</Label>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-auto py-0 px-1 text-xs text-muted-foreground"
                  onClick={generatePassword}
                >
                  <ArrowPathIcon className="h-3 w-3 mr-1" />
                  Generate
                </Button>
              </div>
              <div className="relative">
                <Input
                  id="password"
                  type={showUserPassword ? 'text' : 'password'}
                  value={userFormData.password}
                  onChange={(e) => setUserFormData({ ...userFormData, password: e.target.value })}
                  placeholder="••••••••"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 h-full px-3"
                  onClick={() => setShowUserPassword(!showUserPassword)}
                >
                  {showUserPassword ? (
                    <EyeSlashIcon className="h-4 w-4" />
                  ) : (
                    <EyeIcon className="h-4 w-4" />
                  )}
                </Button>
              </div>
            </div>
            <div className="space-y-2">
              <Label htmlFor="host">Host</Label>
              <Input
                id="host"
                value={userFormData.host}
                onChange={(e) => setUserFormData({ ...userFormData, host: e.target.value })}
                placeholder={database.type === 'mysql' ? '%' : '*'}
              />
              <p className="text-xs text-muted-foreground">
                {database.type === 'mysql'
                  ? 'Use % for any host, or specify an IP/hostname'
                  : 'Host is not used for PostgreSQL users'}
              </p>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCreateUserDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreateUser} disabled={creatingUser}>
              {creatingUser ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Create User
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete User Confirmation */}
      <AlertDialog open={!!userToDelete} onOpenChange={() => setUserToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete User</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete the user "{userToDelete?.username}"?
              This will revoke all their privileges.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDeleteUser}
              disabled={deletingUser}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deletingUser ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Delete User
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Grant Privileges Dialog */}
      <Dialog open={showPrivilegesDialog} onOpenChange={setShowPrivilegesDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Grant Privileges</DialogTitle>
            <DialogDescription>
              Grant database privileges to {selectedUser?.username}.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Database</Label>
              <Select
                value={privilegesFormData.database}
                onValueChange={(value) => setPrivilegesFormData({ ...privilegesFormData, database: value })}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Select a database" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="*">All Databases (*)</SelectItem>
                  {remoteDatabases.map((db) => (
                    <SelectItem key={db} value={db}>{db}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Privileges</Label>
              <div className="grid grid-cols-3 gap-2">
                {privileges.map((priv) => (
                  <div key={priv} className="flex items-center space-x-2">
                    <Checkbox
                      id={`priv-${priv}`}
                      checked={privilegesFormData.privileges.includes(priv)}
                      onCheckedChange={() => togglePrivilege(priv)}
                    />
                    <label
                      htmlFor={`priv-${priv}`}
                      className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                    >
                      {priv}
                    </label>
                  </div>
                ))}
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowPrivilegesDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleGrantPrivileges} disabled={grantingPrivileges}>
              {grantingPrivileges ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              <CheckCircleIcon className="h-4 w-4 mr-2" />
              Grant Privileges
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Created Credentials Dialog */}
      <Dialog open={!!createdCredentials} onOpenChange={() => setCreatedCredentials(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>User Created</DialogTitle>
            <DialogDescription>
              Save these credentials now. The password cannot be retrieved later.
            </DialogDescription>
          </DialogHeader>
          {createdCredentials && (
            <div className="space-y-3">
              <div className="space-y-1">
                <Label className="text-muted-foreground text-xs">Username</Label>
                <div className="flex items-center gap-2">
                  <code className="flex-1 bg-muted px-3 py-2 rounded text-sm font-mono">
                    {createdCredentials.username}
                  </code>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => copyToClipboard(createdCredentials.username)}
                  >
                    <ClipboardDocumentIcon className="h-4 w-4" />
                  </Button>
                </div>
              </div>
              <div className="space-y-1">
                <Label className="text-muted-foreground text-xs">Password</Label>
                <div className="flex items-center gap-2">
                  <code className="flex-1 bg-muted px-3 py-2 rounded text-sm font-mono break-all">
                    {createdCredentials.password}
                  </code>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => copyToClipboard(createdCredentials.password)}
                  >
                    <ClipboardDocumentIcon className="h-4 w-4" />
                  </Button>
                </div>
              </div>
              <div className="space-y-1">
                <Label className="text-muted-foreground text-xs">Host</Label>
                <div className="flex items-center gap-2">
                  <code className="flex-1 bg-muted px-3 py-2 rounded text-sm font-mono">
                    {createdCredentials.host}
                  </code>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => copyToClipboard(createdCredentials.host)}
                  >
                    <ClipboardDocumentIcon className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </div>
          )}
          <DialogFooter>
            <Button onClick={() => setCreatedCredentials(null)}>
              Done
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
