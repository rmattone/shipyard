import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { databaseRestoresApi, getErrorMessage } from '@/services/api'

interface RestoreDatabaseDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  serverId: number
  databaseId: number
  targetDatabase: string
  onQueued: (runId: number) => void
}

// Mirrors DatabaseRestoreController::store's validation: 'dump' => ['file', 'max:1228800']
// (kilobytes), i.e. 1200 MB.
const MAX_DUMP_MB = 1200

/**
 * Restores an uploaded .sql/.sql.gz dump onto an EXISTING remote database,
 * always as an overwrite: this dialog is reached from the "Restore" action on
 * a database that is already there (Task 13 wires it in per-row), so it does
 * not offer a "restore to a new name" mode. That is deliberately destructive,
 * which is why it demands a typed confirmation matching the target name, the
 * same pattern used for server deletion in ServerSettings.tsx.
 *
 * The backend can answer with several distinct statuses, all shown to the
 * user rather than swallowed into a generic "failed":
 *   202 queued          -> onQueued(runId) hands off to RestoreLogPanel
 *   422 validation       -> confirm_name mismatch, or DumpInspector rejected
 *                           the file (message can be multi-sentence, e.g.
 *                           naming pg_restore/--format=plain); shown verbatim
 *   409 already running  -> another restore is in flight for this database
 *   507 disk             -> not enough space on the ShipYard host
 *   403 forbidden        -> caller is not org admin/owner
 *   404 not found        -> server/database pairing is wrong
 * Every one of those already carries a human-written `message` from the API
 * (see DatabaseRestoreController and EnsureOrganizationRole), so this dialog
 * leans on getErrorMessage() rather than re-deriving its own copy per status.
 */
export function RestoreDatabaseDialog({
  open,
  onOpenChange,
  serverId,
  databaseId,
  targetDatabase,
  onQueued,
}: RestoreDatabaseDialogProps) {
  const [file, setFile] = useState<File | null>(null)
  const [confirmName, setConfirmName] = useState('')
  const [progress, setProgress] = useState(0)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirmed = confirmName.length > 0 && confirmName === targetDatabase
  const canSubmit = Boolean(file) && confirmed && !uploading

  const reset = () => {
    setFile(null)
    setConfirmName('')
    setProgress(0)
    setUploading(false)
    setError(null)
  }

  const submit = async () => {
    if (!file) return

    setUploading(true)
    setError(null)
    setProgress(0)

    try {
      const response = await databaseRestoresApi.upload(
        serverId,
        databaseId,
        {
          dump: file,
          targetDatabase,
          overwrite: true,
          confirmName,
        },
        setProgress
      )

      onQueued(response.data.data.id)
      reset()
      onOpenChange(false)
    } catch (err) {
      // The upload leg (browser -> ShipYard) is done by the time any of
      // these responses come back, so leave the file and typed confirmation
      // in place: on a 422 the user likely just needs a different file, and
      // on a 409/507 they may want to retry the same one shortly.
      setError(getErrorMessage(err, 'The restore could not be started.'))
      setUploading(false)
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        // Radix funnels every dismissal path (Escape, an outside click, and
        // the built-in close button rendered inside DialogContent) through
        // this single callback before it ever reaches the caller's own
        // onOpenChange, so ignoring it here while uploading blocks all three
        // at once. Without this, any of them closes the dialog mid-upload
        // with no further feedback, while the upload (up to 1.2 GB) keeps
        // running unseen.
        if (uploading) return
        if (!next) reset()
        onOpenChange(next)
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Restore {targetDatabase}</DialogTitle>
          <DialogDescription>
            This drops and recreates <strong>{targetDatabase}</strong>, then loads the dump you
            upload into it. A safety dump of its current contents is taken first and its path is
            shown once the restore finishes. Accepts plain or gzipped SQL up to {MAX_DUMP_MB} MB.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="dump">Dump file</Label>
            <Input
              id="dump"
              type="file"
              accept=".sql,.gz,.sql.gz"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              disabled={uploading}
            />
            {file && (
              <p className="text-xs text-muted-foreground">
                {file.name} ({(file.size / 1024 / 1024).toFixed(1)} MB)
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="confirm">
              Type <span className="font-mono">{targetDatabase}</span> to confirm
            </Label>
            <Input
              id="confirm"
              value={confirmName}
              onChange={(event) => setConfirmName(event.target.value)}
              disabled={uploading}
              autoComplete="off"
              autoCorrect="off"
              autoCapitalize="off"
              spellCheck={false}
            />
          </div>

          {uploading && (
            <div className="space-y-1">
              <div
                className="h-2 w-full overflow-hidden rounded bg-muted"
                role="progressbar"
                aria-valuenow={progress}
                aria-valuemin={0}
                aria-valuemax={100}
              >
                <div
                  className="h-full bg-primary transition-all"
                  style={{ width: `${progress}%` }}
                />
              </div>
              <p className="text-xs text-muted-foreground">
                {progress < 100 ? `Uploading ${progress}%` : 'Upload complete, starting restore...'}
              </p>
            </div>
          )}

          {error && (
            <p className="whitespace-pre-wrap text-sm text-red-600" role="alert">
              {error}
            </p>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={uploading}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={submit} disabled={!canSubmit}>
            {uploading ? 'Uploading...' : 'Restore'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
