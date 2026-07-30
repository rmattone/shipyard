import { useEffect, useRef, useState } from 'react'
import { BackupRun, databaseRestoresApi } from '@/services/api'

interface RestoreLogPanelProps {
  runId: number
  onComplete?: (status: 'success' | 'failed') => void
}

/**
 * Follows a restore run over SSE (GET /backup-runs/{id}/stream), matching the
 * contract BackupRunStreamController implements and DeploymentDetail.tsx
 * already exercises for deployments:
 *
 * - "connected" carries the FULL log so far -> replace the buffer.
 * - "log" carries only NEW bytes -> append.
 * - "heartbeat" is a no-op keep-alive every 15s.
 * - "complete" ends the run; "timeout" ends the CONNECTION, not the run.
 * - "error" is a named SSE event the server sends for a hard failure (run
 *   not found, or the auth/role check behind the token failed) and is
 *   distinct from EventSource's own onerror, which fires on a dropped
 *   connection rather than something the server told us on purpose.
 *
 * The server caps every stream at 1800s so a single php-fpm worker isn't
 * pinned for a restore's full (up to 3000s) run. A "timeout" event therefore
 * means "reconnect", not "give up": the fresh connection's "connected" event
 * replays the whole log via BackupRun::log, which is persisted on every
 * appendLog() call, so nothing is lost by the reconnect.
 */
export function RestoreLogPanel({ runId, onComplete }: RestoreLogPanelProps) {
  const [log, setLog] = useState('')
  const [status, setStatus] = useState<BackupRun['status']>('pending')
  const [failedStep, setFailedStep] = useState<string | null>(null)
  const [safetyDumpPath, setSafetyDumpPath] = useState<string | null>(null)
  const [streamError, setStreamError] = useState<string | null>(null)

  const eventSourceRef = useRef<EventSource | null>(null)
  const logRef = useRef<HTMLPreElement | null>(null)

  // Read through a ref rather than depending on `onComplete` directly: the
  // caller (DatabaseDetail, Task 13) is expected to pass an inline arrow
  // function, which gets a new identity every render. Depending on it
  // directly would tear down and reopen the EventSource on every parent
  // re-render while a restore is in flight, repeatedly re-consuming a
  // php-fpm worker for no reason. Only `runId` should ever restart the
  // connection.
  const onCompleteRef = useRef(onComplete)
  useEffect(() => {
    onCompleteRef.current = onComplete
  }, [onComplete])

  // Pulls the fields the stream itself never sends: BackupRunStreamController's
  // "connected"/"complete" payloads carry only { run_id, status, log }, not
  // safety_dump_path or failed_step. Those matter most on failure (the safety
  // dump is the user's recovery path), so fetch the full row once the run is
  // known to be done.
  const loadRunDetails = async () => {
    try {
      const response = await databaseRestoresApi.get(runId)
      const run = response.data.data
      setFailedStep(run.failed_step)
      setSafetyDumpPath(run.safety_dump_path)
      return run
    } catch {
      // Non-fatal: the log text still contains the safety dump path (see
      // BackupRun::appendUnknownStateNote), it just won't be pulled out into
      // its own callout.
      return null
    }
  }

  useEffect(() => {
    let cancelled = false

    // Reset per-run state so switching runId (e.g. re-opening the panel for
    // a different history row) doesn't show stale data from a previous run
    // while the new connection is still spinning up.
    setLog('')
    setStatus('pending')
    setFailedStep(null)
    setSafetyDumpPath(null)
    setStreamError(null)

    const connect = () => {
      const token = localStorage.getItem('token')

      if (!token) {
        setStreamError('Not authenticated. Reload the page and try again.')
        return
      }

      const es = new EventSource(`/api/backup-runs/${runId}/stream?token=${encodeURIComponent(token)}`)
      eventSourceRef.current = es

      es.addEventListener('connected', (event) => {
        if (cancelled) return
        const data = JSON.parse((event as MessageEvent).data)
        setLog(data.log ?? '')
        setStatus(data.status)
      })

      es.addEventListener('log', (event) => {
        if (cancelled) return
        const data = JSON.parse((event as MessageEvent).data)
        setLog((current) => current + data.chunk)
      })

      es.addEventListener('complete', async (event) => {
        if (cancelled) return
        const data = JSON.parse((event as MessageEvent).data)
        setStatus(data.status)
        es.close()
        eventSourceRef.current = null

        if (data.status === 'failed') {
          await loadRunDetails()
        }

        if (!cancelled) {
          onCompleteRef.current?.(data.status)
        }
      })

      // Named server event: the stream hit something it cannot recover from
      // (run not found, or the token failed the auth/role check). This is
      // permanent, unlike a "timeout", so it must not trigger a reconnect
      // loop against the same doomed run.
      es.addEventListener('error', (event) => {
        if (cancelled) return
        const messageEvent = event as MessageEvent
        let message = 'The log stream reported an error.'
        if (messageEvent.data) {
          try {
            message = JSON.parse(messageEvent.data).message ?? message
          } catch {
            // Leave the generic message.
          }
        }
        setStreamError(message)
        es.close()
        eventSourceRef.current = null
      })

      es.addEventListener('timeout', () => {
        if (cancelled) return
        es.close()
        eventSourceRef.current = null
        connect()
      })

      // Transport-level failure (dropped connection, bad status code, etc.),
      // as opposed to the named "error" event above. Falls back to a single
      // poll of the run row so the panel still resolves to a real status
      // instead of being stuck on whatever it last displayed.
      es.onerror = () => {
        if (cancelled) return
        es.close()
        eventSourceRef.current = null

        databaseRestoresApi
          .get(runId)
          .then((response) => {
            if (cancelled) return
            const run = response.data.data
            setLog(run.log ?? '')
            setStatus(run.status)
            setFailedStep(run.failed_step)
            setSafetyDumpPath(run.safety_dump_path)
            if (run.status === 'success' || run.status === 'failed') {
              onCompleteRef.current?.(run.status)
            }
          })
          .catch(() => {
            if (!cancelled) {
              setStreamError('Lost connection to the log stream and could not fetch the run status.')
            }
          })
      }
    }

    connect()

    return () => {
      cancelled = true
      eventSourceRef.current?.close()
      eventSourceRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- onComplete is read via onCompleteRef, deliberately excluded (see comment above)
  }, [runId])

  useEffect(() => {
    if (logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight
    }
  }, [log])

  const statusClassName =
    status === 'failed'
      ? 'text-red-600'
      : status === 'success'
        ? 'text-green-600'
        : 'text-muted-foreground'

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 text-sm">
        <span className="font-medium">Restore</span>
        <span className={statusClassName}>{status}</span>
        {failedStep && <span className="text-xs text-muted-foreground">(failed at {failedStep})</span>}
      </div>

      {streamError && (
        <div className="rounded-md border border-yellow-600/40 bg-yellow-600/10 p-3 text-sm text-yellow-700 dark:text-yellow-400">
          {streamError}
        </div>
      )}

      {status === 'failed' && safetyDumpPath && (
        <div className="rounded-md border border-red-600/40 bg-red-600/10 p-3 text-sm">
          <p className="font-medium text-red-700 dark:text-red-400">
            The restore failed, but a safety dump was taken first.
          </p>
          <p className="mt-1 text-muted-foreground">
            Recover from it on the server at:{' '}
            <span className="font-mono text-foreground">{safetyDumpPath}</span>
          </p>
        </div>
      )}

      <pre
        ref={logRef}
        className="max-h-80 overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap"
      >
        {log || 'Waiting for output...'}
      </pre>
    </div>
  )
}
