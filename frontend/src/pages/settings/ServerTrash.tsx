import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { serversApi, getErrorMessage, Server } from '../../services/api'
import { useAuth } from '@/hooks/useAuth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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

/**
 * Renders a timestamp as a coarse relative phrase. Trash entries only need
 * day-level precision, so this avoids pulling in a date library.
 */
const relativeTime = (iso: string, suffix: 'ago' | 'from now'): string => {
  const deltaMs = Math.abs(Date.now() - new Date(iso).getTime())
  const minutes = Math.round(deltaMs / 60_000)

  if (minutes < 1) return suffix === 'ago' ? 'just now' : 'any moment'
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ${suffix}`

  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ${suffix}`

  const days = Math.round(hours / 24)
  return `${days} day${days === 1 ? '' : 's'} ${suffix}`
}

export function ServerTrash() {
  const { hasRole } = useAuth()
  const [servers, setServers] = useState<Server[]>([])
  const [loading, setLoading] = useState(true)
  const [restoringId, setRestoringId] = useState<number | null>(null)
  const [purgeTarget, setPurgeTarget] = useState<Server | null>(null)
  const [purgeConfirmation, setPurgeConfirmation] = useState('')
  const [purging, setPurging] = useState(false)

  const canManage = hasRole('owner', 'admin')

  const loadTrash = async () => {
    try {
      const response = await serversApi.trashed()
      setServers(response.data)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to load the trash'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadTrash()
  }, [])

  const handleRestore = async (server: Server) => {
    setRestoringId(server.id)
    try {
      await serversApi.restore(server.id)
      setServers((current) => current.filter((s) => s.id !== server.id))
      toast.success(`${server.name} restored`)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to restore server'))
    } finally {
      setRestoringId(null)
    }
  }

  // Clear the typed confirmation whenever the dialog closes, so reopening it
  // never starts out already confirmed.
  const handlePurgeDialogChange = (open: boolean) => {
    if (!open) {
      setPurgeTarget(null)
      setPurgeConfirmation('')
    }
  }

  const handlePurge = async () => {
    if (!purgeTarget || purgeConfirmation !== purgeTarget.name) return
    setPurging(true)
    try {
      await serversApi.forceDelete(purgeTarget.id)
      setServers((current) => current.filter((s) => s.id !== purgeTarget.id))
      toast.success(`${purgeTarget.name} permanently deleted`)
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to delete server'))
    } finally {
      setPurging(false)
      handlePurgeDialogChange(false)
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
          <CardTitle>Trash</CardTitle>
          <CardDescription>
            Deleted servers stay here for 30 days. Restoring one brings back its
            databases, daemons, scheduled tasks, and SSH keys.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {servers.length === 0 ? (
            <p className="text-sm text-muted-foreground py-6 text-center">
              The trash is empty.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Server</TableHead>
                  <TableHead>Host</TableHead>
                  <TableHead>Deleted</TableHead>
                  <TableHead>Purges</TableHead>
                  <TableHead className="w-[220px]"></TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {servers.map((server) => (
                  <TableRow key={server.id}>
                    <TableCell className="font-medium">{server.name}</TableCell>
                    <TableCell className="text-muted-foreground">{server.host}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {server.deleted_at ? relativeTime(server.deleted_at, 'ago') : '—'}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {server.purges_at ? relativeTime(server.purges_at, 'from now') : '—'}
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => handleRestore(server)}
                          disabled={!canManage || restoringId === server.id}
                        >
                          {restoringId === server.id ? 'Restoring...' : 'Restore'}
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          onClick={() => setPurgeTarget(server)}
                          disabled={!canManage}
                        >
                          Delete Permanently
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Permanent deletion is the one action here with no recovery path, so
          it carries the same type-the-name gate as the initial delete. */}
      <AlertDialog open={!!purgeTarget} onOpenChange={handlePurgeDialogChange}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Permanently</AlertDialogTitle>
            <AlertDialogDescription>
              This permanently deletes "{purgeTarget?.name}" along with its
              databases, daemons, scheduled tasks, tags, and stored SSH key.
              This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <div className="space-y-2">
            <Label htmlFor="purge-confirmation">
              Type <span className="font-mono font-semibold">{purgeTarget?.name}</span> to confirm
            </Label>
            <Input
              id="purge-confirmation"
              value={purgeConfirmation}
              onChange={(e) => setPurgeConfirmation(e.target.value)}
              autoComplete="off"
            />
          </div>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handlePurge}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              disabled={purging || purgeConfirmation !== purgeTarget?.name}
            >
              {purging ? 'Deleting...' : 'Delete Permanently'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
