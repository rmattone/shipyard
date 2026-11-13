import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { databasesApi, databaseUsersApi, type DatabaseSchema } from '@/services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { LoadingSpinner } from '@/components/custom'

interface Props {
  serverId: number
  databaseId: number
  databaseName: string
  onClose: () => void
}

function errorMessage(error: unknown, fallback: string): string {
  return (error as { response?: { data?: { message?: string } } }).response?.data?.message || fallback
}

export function DatabaseSchemasDialog({ serverId, databaseId, databaseName, onClose }: Props) {
  const { hasRole } = useAuth()
  const canCreate = hasRole('owner', 'admin')
  const [schemas, setSchemas] = useState<DatabaseSchema[]>([])
  const [owners, setOwners] = useState<string[]>([])
  const [name, setName] = useState('')
  const [owner, setOwner] = useState('')
  const [loading, setLoading] = useState(true)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [revision, setRevision] = useState(0)

  const refresh = useCallback(() => setRevision((value) => value + 1), [])

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)
    Promise.all([
      databasesApi.listSchemas(serverId, databaseId, databaseName),
      canCreate ? databaseUsersApi.listRemote(serverId, databaseId) : Promise.resolve(null),
    ]).then(([schemaResponse, usersResponse]) => {
      if (!active) return
      setSchemas(schemaResponse.data.schemas)
      setOwners([...new Set(usersResponse?.data.users.map((user) => user.username) || [])].sort())
    }).catch((reason: unknown) => {
      if (active) setError(errorMessage(reason, 'Failed to load schemas'))
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [serverId, databaseId, databaseName, canCreate, revision])

  const create = async (event: FormEvent) => {
    event.preventDefault()
    if (!name.trim() || !owner || creating) return
    setCreating(true)
    try {
      await databasesApi.createSchema(serverId, databaseId, { database_name: databaseName, name: name.trim(), owner })
      toast.success(`Schema "${name.trim()}" created in "${databaseName}"`)
      setName('')
      refresh()
    } catch (reason: unknown) {
      toast.error(errorMessage(reason, 'Failed to create schema'))
    } finally {
      setCreating(false)
    }
  }

  return (
    <Dialog open onOpenChange={(open) => { if (!open && !creating) onClose() }}>
      <DialogContent className="sm:max-w-xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Schemas in {databaseName}</DialogTitle>
          <DialogDescription>
            Schemas organize tables inside this database. They are not separate databases.
          </DialogDescription>
        </DialogHeader>

        {loading ? <div className="flex justify-center py-6"><LoadingSpinner /></div> : error ? (
          <div role="alert" className="space-y-3">
            <p className="text-sm text-destructive">{error}</p>
            <Button variant="outline" onClick={refresh}>Try again</Button>
          </div>
        ) : (
          <>
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <h3 className="font-medium">Existing schemas</h3>
                <Button variant="outline" size="sm" onClick={refresh} disabled={creating}>Refresh</Button>
              </div>
              {schemas.length === 0 ? <p className="text-sm text-muted-foreground">No schemas found.</p> : (
                <div className="rounded-md border divide-y">
                  {schemas.map((schema) => (
                    <div key={schema.name} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                      <span className="font-medium break-all">{schema.name}</span>
                      <span className="text-muted-foreground break-all">Owner: {schema.owner}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>

            {canCreate && (
              <form onSubmit={create} className="space-y-4 border-t pt-4">
                <h3 className="font-medium">Create schema</h3>
                <div className="space-y-2">
                  <Label htmlFor="schema-name">Schema name</Label>
                  <Input id="schema-name" value={name} onChange={(event) => setName(event.target.value)}
                    placeholder="e.g. ticketing" required maxLength={63} pattern="[a-zA-Z_][a-zA-Z0-9_]*" disabled={creating} />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="schema-owner">Owner</Label>
                  <Select value={owner} onValueChange={setOwner} disabled={creating || owners.length === 0}>
                    <SelectTrigger id="schema-owner"><SelectValue placeholder="Select the application's database user" /></SelectTrigger>
                    <SelectContent>{owners.map((username) => <SelectItem key={username} value={username}>{username}</SelectItem>)}</SelectContent>
                  </Select>
                  <p className="text-sm text-muted-foreground">
                    {owners.length === 0 ? 'Create an application user in the Users tab first.' :
                      'Choose the user configured by your application. It will own this schema and can create its tables.'}
                  </p>
                </div>
                <div className="flex justify-end">
                  <Button type="submit" disabled={creating || !name.trim() || !owner}>
                    {creating ? 'Creating…' : 'Create schema'}
                  </Button>
                </div>
              </form>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
