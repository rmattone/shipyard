import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Terminal } from '@xterm/xterm'
import { FitAddon } from '@xterm/addon-fit'
import '@xterm/xterm/css/xterm.css'
import { serversApi, terminalApi, Server, getErrorMessage } from '../../services/api'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { LoadingSpinner } from '@/components/custom'
import { CommandLineIcon } from '@heroicons/react/24/outline'

type Phase = 'idle' | 'connecting' | 'active' | 'ended'

const ENDED_REASONS: Record<string, string> = {
  closed_by_user: 'Session closed.',
  client_disconnected: 'Disconnected.',
  shell_exited: 'Shell exited.',
  idle_timeout: 'Closed after 15 minutes of inactivity.',
  max_duration: 'Closed after reaching the 2 hour session limit.',
  connection_error: 'The connection to the server was lost.',
  ssh_failed: 'Could not open an SSH session on this server.',
}

// The PTY lives inside the streaming request, so a dropped stream means a
// dead shell. Reconnecting always mints a brand new session rather than
// silently retrying the EventSource.
export default function ServerTerminal() {
  const { id } = useParams<{ id: string }>()
  const [server, setServer] = useState<Server | null>(null)
  const [loading, setLoading] = useState(true)
  const [phase, setPhase] = useState<Phase>('idle')
  const [endedMessage, setEndedMessage] = useState<string | null>(null)

  const hostRef = useRef<HTMLDivElement | null>(null)
  const termRef = useRef<Terminal | null>(null)
  const fitRef = useRef<FitAddon | null>(null)
  const sourceRef = useRef<EventSource | null>(null)
  const sessionRef = useRef<number | null>(null)
  const pendingRef = useRef('')
  const inFlightRef = useRef(false)

  // Keystrokes are queued and sent one request at a time: no added delay
  // when idle, automatic batching while a request is in flight, and the
  // server never receives input out of order.
  const flushInput = useCallback(async () => {
    if (inFlightRef.current || !pendingRef.current || sessionRef.current === null) return

    const chunk = pendingRef.current
    pendingRef.current = ''
    inFlightRef.current = true

    try {
      await terminalApi.input(sessionRef.current, encodeUtf8Base64(chunk))
    } catch {
      // The stream's 'end' event reports the real reason; dropping the
      // chunk is better than a toast per keystroke.
    } finally {
      inFlightRef.current = false
      void flushInput()
    }
  }, [])

  const teardown = useCallback((closeRemote: boolean) => {
    sourceRef.current?.close()
    sourceRef.current = null

    if (closeRemote && sessionRef.current !== null) {
      void terminalApi.close(sessionRef.current).catch(() => {})
    }
    sessionRef.current = null
    pendingRef.current = ''
  }, [])

  const connect = useCallback(async () => {
    const term = termRef.current
    if (!id || !term) return

    const token = localStorage.getItem('token')
    if (!token) {
      setPhase('ended')
      setEndedMessage('You are not signed in.')
      return
    }

    setPhase('connecting')
    setEndedMessage(null)

    try {
      fitRef.current?.fit()
      const { data } = await terminalApi.open(Number(id), term.cols, term.rows)
      sessionRef.current = data.id

      const source = new EventSource(terminalApi.streamUrl(data.id, token))
      sourceRef.current = source

      source.addEventListener('ready', () => {
        setPhase('active')
        term.focus()
      })

      source.addEventListener('o', (event) => {
        const payload = JSON.parse((event as MessageEvent).data)
        term.write(decodeBase64Bytes(payload.d))
      })

      source.addEventListener('ping', () => {
        // Keep-alive only.
      })

      source.addEventListener('end', (event) => {
        const payload = JSON.parse((event as MessageEvent).data)
        setPhase('ended')
        setEndedMessage(ENDED_REASONS[payload.reason] ?? 'Session ended.')
        teardown(false)
      })

      source.addEventListener('error', (event) => {
        // Explicit refusal from the server (auth, role, already attached).
        const raw = (event as MessageEvent).data
        if (raw) {
          try {
            setEndedMessage(JSON.parse(raw).message ?? 'Session refused.')
          } catch {
            setEndedMessage('Session refused.')
          }
        }
      })

      source.onerror = () => {
        // EventSource would retry on its own, but the shell is already
        // gone: stop and let the user start a fresh session.
        setPhase((current) => (current === 'ended' ? current : 'ended'))
        setEndedMessage((current) => current ?? 'Connection to the terminal stream was lost.')
        teardown(false)
      }
    } catch (error) {
      setPhase('ended')
      setEndedMessage(getErrorMessage(error, 'Could not start a terminal session.'))
    }
  }, [id, teardown])

  // Load the server first: local servers have no SSH endpoint to attach to.
  useEffect(() => {
    if (!id) return
    let cancelled = false

    serversApi
      .get(Number(id))
      .then(({ data }) => {
        if (!cancelled) setServer(data)
      })
      .catch((error) => {
        if (!cancelled) toast.error(getErrorMessage(error, 'Failed to load server'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [id])

  // Mount xterm once the server is known to support a terminal.
  useEffect(() => {
    if (!hostRef.current || !server || server.is_local || termRef.current) return

    const term = new Terminal({
      cursorBlink: true,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
      fontSize: 13,
      scrollback: 5000,
      theme: { background: '#111827', foreground: '#f3f4f6' },
    })
    const fit = new FitAddon()
    term.loadAddon(fit)
    term.open(hostRef.current)
    fit.fit()

    term.onData((data) => {
      pendingRef.current += data
      void flushInput()
    })

    termRef.current = term
    fitRef.current = fit

    void connect()

    return () => {
      teardown(true)
      term.dispose()
      termRef.current = null
      fitRef.current = null
    }
  }, [server, connect, flushInput, teardown])

  // Keep the remote PTY the same size as the visible grid.
  useEffect(() => {
    if (!hostRef.current) return

    let timer: number | undefined
    const observer = new ResizeObserver(() => {
      window.clearTimeout(timer)
      timer = window.setTimeout(() => {
        const term = termRef.current
        if (!term) return
        fitRef.current?.fit()
        if (sessionRef.current !== null && phase === 'active') {
          void terminalApi.resize(sessionRef.current, term.cols, term.rows).catch(() => {})
        }
      }, 200)
    })

    observer.observe(hostRef.current)

    return () => {
      window.clearTimeout(timer)
      observer.disconnect()
    }
  }, [phase])

  if (loading) return <LoadingSpinner />

  if (server?.is_local) {
    return (
      <Card className="p-8 text-center">
        <CommandLineIcon className="mx-auto h-10 w-10 text-gray-400" />
        <h2 className="mt-3 text-lg font-medium">Terminal unavailable</h2>
        <p className="mt-1 text-sm text-gray-500">
          This is the local server, which ShipYard manages without SSH.
        </p>
      </Card>
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-medium">Terminal</h1>
          <p className="text-sm text-gray-500">
            {server ? `${server.username}@${server.host}` : ''}
          </p>
        </div>
        {phase === 'active' && (
          <Button
            variant="outline"
            onClick={() => {
              if (sessionRef.current !== null) {
                void terminalApi.close(sessionRef.current).catch(() => {})
              }
            }}
          >
            Close session
          </Button>
        )}
      </div>

      <Card className="relative overflow-hidden bg-[#111827] p-2">
        <div ref={hostRef} className="h-[600px] w-full" />

        {phase !== 'active' && (
          <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-gray-900/85 text-center">
            {phase === 'connecting' ? (
              <p className="text-sm text-gray-200">Connecting…</p>
            ) : (
              <>
                <p className="text-sm text-gray-200">{endedMessage ?? 'No active session.'}</p>
                <Button onClick={() => void connect()}>
                  {phase === 'ended' ? 'Reconnect' : 'Connect'}
                </Button>
              </>
            )}
          </div>
        )}
      </Card>
    </div>
  )
}

function encodeUtf8Base64(value: string): string {
  const bytes = new TextEncoder().encode(value)
  let binary = ''
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })
  return btoa(binary)
}

function decodeBase64Bytes(value: string): Uint8Array {
  const binary = atob(value)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    bytes[i] = binary.charCodeAt(i)
  }
  return bytes
}
