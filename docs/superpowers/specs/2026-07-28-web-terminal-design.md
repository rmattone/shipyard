# Web Terminal for Servers

Date: 2026-07-28
Status: Implemented (backend and frontend; live PTY verification pending)

## Summary

A browser SSH terminal per managed server. Output streams to the client over SSE, keystrokes travel back as POST requests bridged through Redis, and the streaming request itself owns an interactive PTY over phpseclib. No new containers and no websockets.

## Requirements

- Interactive shell on any non-local managed server, using the credentials ShipYard already stores.
- Admin and owner only. A shell bypasses every other permission in the product, so members (who can deploy) are excluded.
- Connects as the server's SSH user (`servers.username`) with its stored key. No new credential handling.
- Local servers are refused: ShipYard manages them without SSH.
- Session metadata persisted as minimal audit: who, which server, when it started and ended, and why it ended.

## Architecture

```
xterm.js --POST /input (base64)--> TerminalController --LPUSH--> Redis list
                                                                  | terminal:{id}:input
xterm.js <--SSE 'o' events------- TerminalStreamController <--RPOP-+
                                        | owns the PTY (50ms read poll)
                                        +--SSH--> target server login shell
```

The streaming php-fpm worker holds the SSH connection for the session's lifetime. Its 50 ms SSH read timeout doubles as the loop's clock: each tick drains queued input, polls for output, and emits heartbeats.

### Session lifecycle

1. `POST /api/servers/{server}/terminal-sessions` creates a `pending` row carrying the client's `cols`/`rows`, after reaping stale rows and checking the concurrency cap.
2. `GET /api/terminal-sessions/{id}/stream?token=` attaches. A single conditional UPDATE (`status = pending AND created_at > now() - 60s`) flips the row to `active` and acts as the mutex: a second stream, an expired pending row, or an ended session all fail the WHERE and receive 409.
3. The loop runs until one of: close message, client abort, shell exit, idle timeout (15 min), or the hard cap (2 h).
4. `finally` always emits an `end` event, disconnects SSH, drops the Redis key, and marks the row ended with a reason.

`ended_reason` values: `closed_by_user`, `client_disconnected`, `shell_exited`, `idle_timeout`, `max_duration`, `connection_error`, `ssh_failed`, `never_attached`, `stale`.

Reconnecting always mints a new session, because the PTY dies with the stream. The client shows a "Session ended, Reconnect" overlay rather than letting EventSource retry silently.

### PTY details (phpseclib 3.0.55)

`setWindowSize()` must precede `openShell()`, which sends the pty-req and then requests a shell (sshd runs it as a login shell). `read('', READ_SIMPLE)` with `setTimeout(0.05)` is a non-blocking poll; `isTimeout() === false` after a read means the channel closed, which is the shell-exit signal.

phpseclib has no live resize API, so `App\Support\Ssh\InteractiveSSH2` subclasses `SSH2` and sends the RFC 4254 section 6.7 `window-change` channel request directly. The needed internals (`server_channels`, `send_binary_packet`) are protected, so no reflection is required.

`TerminalService` is deliberately separate from `SSHService`: that service is exec-only, reuses live sessions across `connect()` calls, and disconnects in its destructor, all of which conflict with a long-lived shell channel. Only the key-loading idiom is shared.

### Redis protocol

JSON envelopes on `terminal:{sessionId}:input`, keystrokes base64 encoded so control bytes (Ctrl-C, arrow escapes) and UTF-8 survive intact:

- input: `{"t":"i","d":"<base64>"}`
- resize: `{"t":"r","c":120,"r":32}`
- close: `{"t":"c"}`

`LPUSH` with a 120 s TTL refreshed on every push; the loop drains with non-blocking `RPOP`. `BRPOP` was rejected because phpredis takes whole-second timeouts, which would add latency to the output path. Keystrokes can contain typed passwords, so the key is deleted on teardown, carries a short TTL, lives only on the internal network, and is never logged.

### Concurrency and resources

Each stream pins one php-fpm worker for the session's life, so:

- Global cap of 3 live sessions (`TerminalService::MAX_CONCURRENT`), checked after reaping.
- Stale reaping: `pending` rows older than 60 s become `never_attached`; `active` rows whose `last_seen_at` (touched every 15 s by the loop) is older than 60 s become `stale`. A killed worker can therefore never wedge the cap shut.
- `docker/php/zz-shipyard.conf` raises the pool from the stock 5 children to 20.
- A dedicated nginx location for the stream path sets `fastcgi_read_timeout 7300` and `fastcgi_buffering off`, alongside the `X-Accel-Buffering: no` header.

### Authorization

The stream route sits outside `auth:sanctum` because EventSource cannot send headers, matching the existing deployment and installation streams. The controller therefore enforces, by hand: token validity, session ownership (`user_id`), membership in the server's organization, and the admin-or-owner role. The role is re-checked at attach time so a user demoted after opening a session cannot attach.

The write routes sit inside the normal group under `org.role:admin`. The input route replaces `throttle:api` (120/min, which a typing burst would exhaust) with a dedicated `terminal-input` limiter at 1200/min.

## Data model

`terminal_sessions`: `user_id`, `server_id`, `status` (pending/active/ended), `cols`, `rows`, `started_at`, `ended_at`, `last_seen_at`, `ended_reason`, timestamps, index on `(status, last_seen_at)`.

The model deliberately does not use `BelongsToOrganization`: it is user and server scoped, and the stream route resolves its binding without an organization context, so controllers verify ownership explicitly.

## Frontend

`frontend/src/pages/servers/ServerTerminal.tsx`, reached from the server tab bar at `/servers/:id/terminal`. xterm.js plus the fit addon; output decoded from base64 into a `Uint8Array` before `term.write`.

Input uses a serialized coalescing queue rather than a timer debounce: keystrokes accumulate in a buffer, exactly one POST is in flight at a time, and the buffer flushes again on completion. That gives no added latency when idle, automatic batching under load, and strict ordering.

A `ResizeObserver` refits the grid and posts the new dimensions (200 ms debounce). Local servers render an explanatory empty state instead of a terminal.

## Testing

Automated (25 tests):

- `tests/Unit/TerminalMessageTest.php`: codec round-trips for control bytes, escapes, multibyte UTF-8; malformed input rejected.
- `tests/Feature/TerminalSessionModelTest.php`: `markEnded`, local-server refusal, key naming, and selective stale reaping.
- `tests/Feature/TerminalSessionApiTest.php`: role gating, cross-organization 404, local-server 422, the concurrency cap and its recovery through reaping, Redis payload shapes for input/resize/close, and cross-user 404s.
- `tests/Feature/TerminalStreamTest.php`: missing token 401, other user's session 403, member role 403, double attach 409, expired pending 409, and the `ssh_failed` bookkeeping path.

Not machine-testable: the live PTY loop (read/write/window-change against a real sshd) and the nginx/FPM behavior under long-lived streams. Manual verification on a fresh EC2 target: `vim`, `top`, Ctrl-C, arrow-key history, window resize reflowing `stty size`, `exit` producing the `shell_exited` overlay, tab close ending the row as `client_disconnected` within about 15 s, a second tab receiving 409, and a fourth session receiving 429.

## Deliberate limitations

- One shell per session, no tabs or splits, no session sharing or reattach.
- No scrollback persistence and no transcript recording. Transcripts would capture secrets (`cat .env`), which needs its own decision; the audit-log feature is the natural home.
- No file upload or download through the terminal.
- Keystroke latency is roughly one 50 ms poll plus round-trip time. Acceptable for administrative work, noticeably behind a websocket terminal for sustained typing.
- Sessions do not survive a ShipYard restart or an FPM worker recycle; the reaper cleans up and the user reconnects.
