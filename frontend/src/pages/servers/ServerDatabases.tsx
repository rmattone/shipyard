import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import {
  CircleStackIcon,
  PlusIcon,
  ServerIcon,
  CheckCircleIcon,
  XCircleIcon,
  EyeIcon,
  EyeSlashIcon,
} from '@heroicons/react/24/outline'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import { LoadingSpinner, StatusBadge } from '@/components/custom'
import { databasesApi, serversApi, type Database, type Server, type DetectedDatabase } from '@/services/api'

export default function ServerDatabases() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const serverId = parseInt(id!)

  const [server, setServer] = useState<Server | null>(null)
  const [databases, setDatabases] = useState<Database[]>([])
  const [detectedDatabases, setDetectedDatabases] = useState<Record<string, DetectedDatabase>>({})
  const [loading, setLoading] = useState(true)
  const [detecting, setDetecting] = useState(false)

  // Dialog states
  const [showAddDialog, setShowAddDialog] = useState(false)
  const [showEditDialog, setShowEditDialog] = useState(false)
  const [editingDatabase, setEditingDatabase] = useState<Database | null>(null)
  const [databaseToDelete, setDatabaseToDelete] = useState<Database | null>(null)
  const [testingConnection, setTestingConnection] = useState<number | null>(null)
  const [showPassword, setShowPassword] = useState(false)

  // Form state
  const [formData, setFormData] = useState({
    name: '',
    type: 'mysql' as 'mysql' | 'postgresql',
    host: 'localhost',
    port: 3306,
    admin_user: '',
    admin_password: '',
    charset: '',
    collation: '',
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    loadData()
  }, [serverId])

  const loadData = async () => {
    try {
      const [serverRes, dbRes] = await Promise.all([
        serversApi.get(serverId),
        databasesApi.list(serverId),
      ])
      setServer(serverRes.data)
      setDatabases(dbRes.data)
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to load data')
    } finally {
      setLoading(false)
    }
  }

  const handleDetect = async () => {
    setDetecting(true)
    try {
      const response = await databasesApi.detect(serverId)
      setDetectedDatabases(response.data)
      if (Object.keys(response.data).length === 0) {
        toast.info('No database servers detected on this server')
      } else {
        toast.success('Database servers detected')
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to detect databases')
    } finally {
      setDetecting(false)
    }
  }

  const resetForm = () => {
    setFormData({
      name: '',
      type: 'mysql',
      host: 'localhost',
      port: 3306,
      admin_user: '',
      admin_password: '',
      charset: '',
      collation: '',
    })
    setShowPassword(false)
  }

  const openAddDialog = (type?: 'mysql' | 'postgresql') => {
    resetForm()
    if (type) {
      const detected = detectedDatabases[type]
      setFormData(prev => ({
        ...prev,
        type,
        port: detected?.default_port || (type === 'mysql' ? 3306 : 5432),
        admin_user: type === 'mysql' ? 'root' : 'postgres',
      }))
    }
    setShowAddDialog(true)
  }

  const openEditDialog = (db: Database) => {
    setEditingDatabase(db)
    setFormData({
      name: db.name,
      type: db.type,
      host: db.host,
      port: db.port,
      admin_user: db.admin_user,
      admin_password: '',
      charset: db.charset || '',
      collation: db.collation || '',
    })
    setShowEditDialog(true)
  }

  const handleSave = async () => {
    if (!formData.name || !formData.admin_user || (!editingDatabase && !formData.admin_password)) {
      toast.error('Please fill in all required fields')
      return
    }

    setSaving(true)
    try {
      if (editingDatabase) {
        const updateData: Record<string, unknown> = {
          name: formData.name,
          host: formData.host,
          port: formData.port,
          admin_user: formData.admin_user,
          charset: formData.charset || null,
          collation: formData.collation || null,
        }
        if (formData.admin_password) {
          updateData.admin_password = formData.admin_password
        }
        const response = await databasesApi.update(serverId, editingDatabase.id, updateData)
        setDatabases(databases.map(d => d.id === editingDatabase.id ? response.data : d))
        toast.success('Database connection updated')
        setShowEditDialog(false)
      } else {
        const response = await databasesApi.create(serverId, formData)
        setDatabases([...databases, response.data])
        toast.success('Database connection added')
        setShowAddDialog(false)
      }
      resetForm()
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to save database connection')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!databaseToDelete) return

    try {
      await databasesApi.delete(serverId, databaseToDelete.id)
      setDatabases(databases.filter(d => d.id !== databaseToDelete.id))
      toast.success('Database connection removed')
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Failed to remove database connection')
    } finally {
      setDatabaseToDelete(null)
    }
  }

  const handleTestConnection = async (db: Database) => {
    setTestingConnection(db.id)
    try {
      const response = await databasesApi.testConnection(serverId, db.id)
      if (response.data.success) {
        toast.success(`Connected successfully! Version: ${response.data.version}`)
      } else {
        toast.error(response.data.message)
      }
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } }
      toast.error(err.response?.data?.message || 'Connection test failed')
    } finally {
      setTestingConnection(null)
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <LoadingSpinner size="lg" />
      </div>
    )
  }

  if (!server) {
    return (
      <div className="text-center py-12">
        <p className="text-muted-foreground">Server not found</p>
      </div>
    )
  }

  const renderForm = () => (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="name">Connection Name *</Label>
          <Input
            id="name"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            placeholder="e.g., Production MySQL"
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="type">Database Type *</Label>
          <Select
            value={formData.type}
            onValueChange={(value: 'mysql' | 'postgresql') => {
              setFormData({
                ...formData,
                type: value,
                port: value === 'mysql' ? 3306 : 5432,
                admin_user: value === 'mysql' ? 'root' : 'postgres',
              })
            }}
            disabled={!!editingDatabase}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="mysql">MySQL</SelectItem>
              <SelectItem value="postgresql">PostgreSQL</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="host">Host</Label>
          <Input
            id="host"
            value={formData.host}
            onChange={(e) => setFormData({ ...formData, host: e.target.value })}
            placeholder="localhost"
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="port">Port</Label>
          <Input
            id="port"
            type="number"
            value={formData.port}
            onChange={(e) => setFormData({ ...formData, port: parseInt(e.target.value) || 3306 })}
          />
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="admin_user">Admin Username *</Label>
          <Input
            id="admin_user"
            value={formData.admin_user}
            onChange={(e) => setFormData({ ...formData, admin_user: e.target.value })}
            placeholder={formData.type === 'mysql' ? 'root' : 'postgres'}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="admin_password">
            Admin Password {editingDatabase ? '(leave empty to keep)' : '*'}
          </Label>
          <div className="relative">
            <Input
              id="admin_password"
              type={showPassword ? 'text' : 'password'}
              value={formData.admin_password}
              onChange={(e) => setFormData({ ...formData, admin_password: e.target.value })}
              placeholder="••••••••"
            />
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="absolute right-0 top-0 h-full px-3"
              onClick={() => setShowPassword(!showPassword)}
            >
              {showPassword ? (
                <EyeSlashIcon className="h-4 w-4" />
              ) : (
                <EyeIcon className="h-4 w-4" />
              )}
            </Button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="space-y-2">
          <Label htmlFor="charset">Default Charset (optional)</Label>
          <Input
            id="charset"
            value={formData.charset}
            onChange={(e) => setFormData({ ...formData, charset: e.target.value })}
            placeholder={formData.type === 'mysql' ? 'utf8mb4' : 'UTF8'}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="collation">Default Collation (optional)</Label>
          <Input
            id="collation"
            value={formData.collation}
            onChange={(e) => setFormData({ ...formData, collation: e.target.value })}
            placeholder={formData.type === 'mysql' ? 'utf8mb4_unicode_ci' : 'en_US.UTF-8'}
          />
        </div>
      </div>
    </div>
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Databases</h1>
          <p className="text-muted-foreground">
            Manage database connections on {server.name}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleDetect} disabled={detecting}>
            {detecting ? (
              <>
                <LoadingSpinner size="sm" className="mr-2" />
                Detecting...
              </>
            ) : (
              <>
                <ServerIcon className="h-4 w-4 mr-2" />
                Detect Databases
              </>
            )}
          </Button>
          <Button onClick={() => openAddDialog()}>
            <PlusIcon className="h-4 w-4 mr-2" />
            Add Connection
          </Button>
        </div>
      </div>

      {/* Detected databases */}
      {Object.keys(detectedDatabases).length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-lg">Detected Database Servers</CardTitle>
            <CardDescription>
              Database servers installed on this server
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="grid gap-4 md:grid-cols-2">
              {detectedDatabases.mysql && (
                <div className="flex items-center justify-between p-4 border rounded-lg">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-lg bg-blue-500/10 flex items-center justify-center">
                      <CircleStackIcon className="h-5 w-5 text-blue-500" />
                    </div>
                    <div>
                      <p className="font-medium">MySQL</p>
                      <p className="text-sm text-muted-foreground">
                        {detectedDatabases.mysql.version}
                      </p>
                    </div>
                  </div>
                  <Button size="sm" onClick={() => openAddDialog('mysql')}>
                    <PlusIcon className="h-4 w-4 mr-1" />
                    Add
                  </Button>
                </div>
              )}
              {detectedDatabases.postgresql && (
                <div className="flex items-center justify-between p-4 border rounded-lg">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-lg bg-indigo-500/10 flex items-center justify-center">
                      <CircleStackIcon className="h-5 w-5 text-indigo-500" />
                    </div>
                    <div>
                      <p className="font-medium">PostgreSQL</p>
                      <p className="text-sm text-muted-foreground">
                        {detectedDatabases.postgresql.version}
                      </p>
                    </div>
                  </div>
                  <Button size="sm" onClick={() => openAddDialog('postgresql')}>
                    <PlusIcon className="h-4 w-4 mr-1" />
                    Add
                  </Button>
                </div>
              )}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Database connections list */}
      {databases.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <CircleStackIcon className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-medium mb-2">No database connections</h3>
            <p className="text-muted-foreground text-center mb-4">
              Add a database connection to manage your MySQL or PostgreSQL databases.
            </p>
            <Button onClick={() => openAddDialog()}>
              <PlusIcon className="h-4 w-4 mr-2" />
              Add Connection
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {databases.map((db) => (
            <Card key={db.id}>
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-4">
                    <div className={`h-12 w-12 rounded-lg flex items-center justify-center ${
                      db.type === 'mysql' ? 'bg-blue-500/10' : 'bg-indigo-500/10'
                    }`}>
                      <CircleStackIcon className={`h-6 w-6 ${
                        db.type === 'mysql' ? 'text-blue-500' : 'text-indigo-500'
                      }`} />
                    </div>
                    <div>
                      <div className="flex items-center gap-2">
                        <h3 className="font-medium">{db.name}</h3>
                        <Badge variant="outline" className="capitalize">
                          {db.type}
                        </Badge>
                        <StatusBadge status={db.status === 'active' ? 'success' : 'inactive'} />
                      </div>
                      <p className="text-sm text-muted-foreground">
                        {db.admin_user}@{db.host}:{db.port}
                      </p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handleTestConnection(db)}
                      disabled={testingConnection === db.id}
                    >
                      {testingConnection === db.id ? (
                        <LoadingSpinner size="sm" />
                      ) : (
                        <>
                          <CheckCircleIcon className="h-4 w-4 mr-1" />
                          Test
                        </>
                      )}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => navigate(`/servers/${serverId}/databases/${db.id}`)}
                    >
                      Manage
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => openEditDialog(db)}
                    >
                      Edit
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setDatabaseToDelete(db)}
                    >
                      <XCircleIcon className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Add Dialog */}
      <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Add Database Connection</DialogTitle>
            <DialogDescription>
              Add a connection to a MySQL or PostgreSQL database server.
            </DialogDescription>
          </DialogHeader>
          {renderForm()}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowAddDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Add Connection
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={showEditDialog} onOpenChange={setShowEditDialog}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Edit Database Connection</DialogTitle>
            <DialogDescription>
              Update the connection settings for {editingDatabase?.name}.
            </DialogDescription>
          </DialogHeader>
          {renderForm()}
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowEditDialog(false)}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? <LoadingSpinner size="sm" className="mr-2" /> : null}
              Save Changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!databaseToDelete} onOpenChange={() => setDatabaseToDelete(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Remove Database Connection</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to remove the connection to "{databaseToDelete?.name}"?
              This will not delete any databases on the server.
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

    </div>
  )
}
