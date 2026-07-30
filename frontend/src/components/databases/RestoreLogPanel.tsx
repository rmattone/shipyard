import { useEffect, useRef, useState } from 'react'
import { BackupRun, databaseRestoresApi } from '@/services/api'

interface RestoreLogPanelProps {
  runId: number
  onComplete?: (status: 'success' | 'failed') => void
}

// How long to wait before opening a fresh connection after the browser's own
// EventSource retry machinery gives up (readyState CLOSED). Matches the
// ballpark of EventSource's own default reconnection delay (~3s per the
// HTML spec's suggested default), so a manual reconnect doesn't behave
// noticeably differently from the automatic one that preceded it.
const RECONNECT_DELAY_MS = 3000

/**
 * Follows a restore run over SSE (GET /backup-runs/{id}/stream), matching the
 * contract BackupRunStreamController implements and DeploymentDetail.tsx
 * already exercises for deployments:
 *
 * - "connected" carries the FULL log so far -> replace the buffer.
 * - "log" carries only NEW bytes -> append.
 * - "heartbeat" is a no-op keep-alive every 15s.
 * - "complete" ends the run; "timeout" ends the CONNECTION, not the run.
 * - "stream_error" is a named SSE event the server sends for a hard,
 *   per-run failure (run not found, or the auth/role check behind the
 *   token failed).
 *
 * That last one is deliberately NOT named "error": a browser EventSource
 * fires its own "error" DOM event (observed via the onerror handler) for
 * transport failures, and a server-sent event whose `event:` field is
 * literally "error" dispatches as that exact same DOM event type, not a
 * separate channel. Naming a permanent per-run failure "error" would also
 * trip the transport-error handling below for it, and a plain network blip
 * would spuriously look like one of these named failures. So the two are
 * kept apart on the wire: "stream_error" for "this run cannot be watched",
 * onerror for "this connection dropped."
 *
 * The server caps every stream at 1800s so a single php-fpm worker isn't
 * pinned for a restore's full (up to 3000s) run. A "timeout" event therefore
 * means "reconnect", not "give up": the fresh connection's "connected" event
 * replays the whole log via BackupRun::log, which is persisted on every
 * appendLog() call, so nothing is lost by the reconnect.
 *
 * A transport drop (onerror) is handled the same way, not treated as
 * terminal: EventSource retries a dropped connection on its own, so while
 * readyState is CONNECTING nothing needs to happen here beyond a light
 * "reconnecting" note. Only when the browser's own retry gives up entirely
 * (readyState CLOSED, e.g. a retry attempt got a non-200 response) does this
 * component take over: it polls the run once to show the real status, and if
 * the restore is still going, opens a brand new connection itself after
 * RECONNECT_DELAY_MS. That is what lets a user recover from a blip without
 * reloading the page; only a run that has actually finished, or a
 * "stream_error", ever stops the retry loop.
 */
export function RestoreLogPanel({ runId, onComplete }: RestoreLogPanelProps) {
  const [log, setLog] = useState('')
  const [status, setStatus] = useState<BackupRun['status']>('pending')
  const [failedStep, setFailedStep] = useState<string | null>(null)
  const [safetyDumpPath, setSafetyDumpPath] = useState<string | null>(null)
  const [streamError, setStreamError] = useState<string | null>(null)
  const [reconnecting, setReconnecting] = useState(false)

  const eventSourceRef = useRef<EventSource | null>(null)
  const logRef = useRef<HTMLPreElement | null>(null)

  // Read through a ref rather than depending on `onComplete` directly: the
  // caller (DatabaseDetail, Task 13) passes an inline arrow function, which
  // gets a new identity every render. Depending on it directly would tear
  // down and reopen the EventSource on every parent re-render while a
  // restore is in flight, repeatedly re-consuming a php-fpm worker for no
  // reason. Only `runId` should ever restart the connection.
  const onCompleteRef = useRef(onComplete)
  useEffect(() => {
    onCompleteRef.current = onComplete
  }, [onComplete])

  useEffect(() => {
    let cancelled = false
    let retryTimeout: ReturnType<typeof setTimeout> | null = null

    // Reset per-run state so switching runId (e.g. re-opening the panel for
    // a different history row) doesn't show stale data from a previous run
    // while the new connection is still spinning up.
    setLog('')
    setStatus('pending')
    setFailedStep(null)
    setSafetyDumpPath(null)
    setStreamError(null)
    setReconnecting(false)

    // Pulls the fields the stream itself never sends: BackupRunStreamController's
    // "connected"/"complete" payloads carry only { run_id, status, log }, not
    // safety_dump_path or failed_step. Those matter most on failure (the
    // safety dump is the user's recovery path). Defined inside the effect,
    // sharing its `cancelled` closure, so a stale response for a run the user
    // has since navigated away from can never land in state after the fact.
    const loadRunDetails = async (): Promise<BackupRun | null> => {
      try {
        const response = await databaseRestoresApi.get(runId)
        if (cancelled) return null
        const run = response.data.data
        setFailedStep(run.failed_step)
        setSafetyDumpPath(run.safety_dump_path)
        return run
      } catch {
        // Non-fatal: the log text still contains the safety dump path (see
        // BackupRun::appendUnknownStateNote), it just won't be pulled out
        // into its own callout.
        return null
      }
    }

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
        // A "connected" event only ever arrives over a live connection, so
        // its receipt is itself the proof that any prior drop is over.
        setReconnecting(false)
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

      // Permanent, per-run failure the server told us about on purpose (see
      // the docblock above for why this is not named "error"). Unlike a
      // timeout or a transport drop, there is nothing to retry: the run
      // itself is unreachable or unwatchable, not merely the connection.
      es.addEventListener('stream_error', (event) => {
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
        setReconnecting(false)
        es.close()
        eventSourceRef.current = null
      })

      es.addEventListener('timeout', () => {
        if (cancelled) return
        es.close()
        eventSourceRef.current = null
        connect()
      })

      // Transport-level failure: a dropped connection or a non-200 response,
      // as opposed to the named "stream_error" event above.
      es.onerror = () => {
        if (cancelled) return

        if (es.readyState === EventSource.CONNECTING) {
          // The browser is already retrying this same connection on its own.
          // Nothing to do beyond a light, non-alarming note; "connected"
          // clears it the moment the retry succeeds.
          setReconnecting(true)
          return
        }

        // readyState is CLOSED: the browser's own retry gave up (this
        // happens when a retry attempt itself gets a non-200 response, not
        // just for an ordinary dropped socket) and will not try again. That
        // is terminal for THIS EventSource, not necessarily for the user:
        // poll once for the real status, and if the restore is still
        // running, open a brand new connection after a short delay so
        // recovery doesn't require reloading the page.
        eventSourceRef.current = null
        setReconnecting(true)

        const scheduleReconnect = () => {
          retryTimeout = setTimeout(() => {
            if (!cancelled) connect()
          }, RECONNECT_DELAY_MS)
        }

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
              setReconnecting(false)
              onCompleteRef.current?.(run.status)
              return
            }

            // Still pending/running: the restore itself is alive, only the
            // connection died, so keep trying rather than stranding the
            // panel on a stale view.
            scheduleReconnect()
          })
          .catch(() => {
            if (cancelled) return
            // Couldn't even confirm the run's status (the network may still
            // be down). Retry on the same cadence rather than declaring this
            // permanent: the alternative is a dead panel until the user
            // reloads the page, over what may be a few seconds of flakiness.
            scheduleReconnect()
          })
      }
    }

    connect()

    return () => {
      cancelled = true
      eventSourceRef.current?.close()
      eventSourceRef.current = null
      if (retryTimeout !== null) {
        clearTimeout(retryTimeout)
      }
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
        {reconnecting && <span className="text-xs text-muted-foreground">Reconnecting...</span>}
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
