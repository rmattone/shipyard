# Audit Log

Date: 2026-07-28
Status: Approved

## Summary

An append-only record of who changed what in ShipYard. HTTP writes are captured automatically by middleware; webhook deploys, terminal session ends, and artisan commands are recorded explicitly. Values are redacted by allowlist so the log never becomes a second copy of the secrets ShipYard encrypts.

## Motivation

ShipYard is multi-tenant, holds encrypted SSH keys and environment secrets for every managed server, and (since the web terminal) grants browser shell access to production machines. Nothing currently records who did any of it. Cross-organization isolation is the critical boundary in the threat model, and there is no way to answer "who changed this" or "who shelled into that server".

## Requirements

- Capture every HTTP write without relying on developers to remember, including endpoints added later.
- Record webhook-triggered deploys, terminal session ends, and artisan commands.
- Owner-only read access.
- Append-only: no update or delete API. Entries older than one year are pruned by a scheduled command.
- Metadata plus a redacted summary of what was submitted. Never store secret values.

## Architecture

Four pieces, with `AuditLogger` as the single insert path.

### AuditLogger service

The only code that writes `audit_logs` rows. Takes an action, optional target, and context; resolves actor and organization from the current request or console context.

A failed audit write logs a warning and lets the operation proceed. Blocking the operation when auditing fails is defensible for compliance systems but would mean a transient database problem stops deploys. Non-blocking is the deliberate choice here.

### RecordAuditLog middleware

Registered on the API group. Split across the request lifecycle:

- `handle()` snapshots what is about to be lost: resolved route parameters, the target's label before a delete removes it, and the request payload.
- `terminate()` writes the row with the final status code. Running after the response is sent means auditing adds no latency to the request.

Only non-GET requests are recorded. Failed requests are recorded too, with their status code: a 403 attempt is security-relevant.

### Action naming

Derived from route URI and HTTP method rather than a hand-maintained map: `PUT /servers/{server}` becomes `servers.update`, `POST /applications/{application}/deploy` becomes `applications.deploy`, `DELETE /servers/{server}/firewall/rules` becomes `servers.firewall.rules.destroy`. New endpoints are audited with sensible names automatically. Callers may pass an explicit action where the derived one is unhelpful.

### Target resolution

From the route's bound parameters, last bound model wins, so `/servers/{server}/databases/{database}` targets the database. Stored as plain `target_type` / `target_id` strings rather than a polymorphic relation: no class coupling, and entries survive model renames. `target_label` snapshots the name so the entry stays readable after the target is deleted.

### What "changes" means

The middleware records the submitted request payload, redacted. It is not a model diff: the middleware sees the request, not before/after database state. True field-level diffs for specific models would be an additive change later.

## Data model

`audit_logs`, insert-only, `created_at` only (no `updated_at`).

| Column | Purpose |
|---|---|
| `organization_id` (nullable, indexed) | tenant scope; null for console and system actions that run unscoped |
| `actor_type` | `user`, `webhook`, `console`, `system` |
| `user_id` (nullable) | actor when a user; nulled if the user is deleted |
| `actor_label` | snapshot such as an email, "github webhook", or a command name, so entries stay meaningful after deletion |
| `action` | `servers.update`, `terminal.session.end`, `auth.login.failed` |
| `target_type`, `target_id`, `target_label` | plain strings, not a polymorphic relation |
| `method`, `path`, `route_name`, `status_code` | HTTP context, nullable for non-HTTP events |
| `ip`, `user_agent` | nullable |
| `changes` (json) | redacted submitted payload |
| `context` (json) | event-specific extras: terminal `ended_reason` and duration, webhook commit and branch, command exit code |

Indexes: `(organization_id, created_at)` for the timeline query, `(actor_type, user_id)` for per-actor filtering.

## Redaction

Allowlist-based, default-deny. Field names are always kept; values appear verbatim only when the key is on the safe list (`name`, `host`, `port`, `username`, `type`, `frequency`, `branch`, `domain`, `php_version`, `node_version`, `enabled`, `is_enabled`, `status`, `cols`, `rows`, and similar non-sensitive configuration fields). Every other value becomes `[redacted]`.

Default-allow with a denylist was rejected: any secret field added in future would leak silently until someone noticed. Allowlisting fails closed.

This covers `private_key`, `admin_password`, `secret_key`, `access_key`, `bot_token`, `webhook_url`, and, importantly, environment variable values and whole `.env` file contents, which would otherwise be copied out of their encrypted columns into a plaintext table.

## Exclusions from generic capture

1. `POST /terminal-sessions/{id}/input` and `POST /terminal-sessions/{id}/resize`. Keystrokes contain typed passwords and arrive at roughly 20 requests per second while typing, which would both leak secrets and swamp the table. Shell access remains fully audited through session open (HTTP, captured) and session end (recorded explicitly).
2. `POST /auth/login`. The payload is credentials. `AuthController` records `auth.login.succeeded` and `auth.login.failed` explicitly instead, carrying email and IP only.

SSE stream routes are GET and therefore already out of scope.

## Non-HTTP events

- **Webhook deploys**: the webhook handlers record `deployments.trigger` with `actor_type=webhook`, `actor_label` set to the provider, and commit, branch, and ref in context.
- **Terminal session end**: `TerminalStreamController`'s teardown records `terminal.session.end` with `ended_reason` and duration.
- **Artisan commands**: a listener on `Illuminate\Console\Events\CommandFinished` records `actor_type=console` with the exit code, restricted to commands defined in `App\Console\Commands`. Framework and scheduler noise (`schedule:run`, `queue:work`, `migrate`) stays out, and future ShipYard commands are picked up automatically.

Queue job outcomes are deliberately not audited: deployments, rollbacks, and installations already have their own rows with status and logs, and the HTTP request that triggered them is captured.

## Read access and retention

`GET /api/audit-logs`, owner-only (`org.role:owner`, matching the `/system/*` precedent), organization-scoped, paginated, with filters for action, actor, target, and date range. Admins can shell into servers and change firewall rules, so admins reviewing their own trail would weaken the oversight value.

There is no update or delete route. The only removal path is `audit:prune`, which deletes entries older than one year, scheduled daily in `routes/console.php`.

Frontend: a page under Settings alongside Members and Notification Channels.

## Testing

- Middleware records writes with the correct derived action, target, and status code; GET requests produce nothing.
- Terminal input and resize requests are never recorded.
- The login payload is never stored; explicit success and failure events are.
- Redaction: allowlisted keys appear verbatim, everything else is `[redacted]`, including environment variable values and private keys.
- Organization scoping: reads never cross organizations; admins receive 403.
- No update or delete routes exist for audit logs.
- The console listener records app-defined commands only.
- `audit:prune` deletes past the boundary and keeps entries inside it.

## Out of scope for v1

- Field-level before/after model diffs.
- Export (CSV, SIEM forwarding, syslog).
- Tamper-evidence such as hash chaining or append-only storage outside the database.
- Alerting on suspicious patterns.
- Auditing read access to secrets (viewing an env var is a GET and is not recorded).
