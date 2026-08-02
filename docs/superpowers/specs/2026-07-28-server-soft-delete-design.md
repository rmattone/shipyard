# Server soft delete and trash

## Problem

Deleting a server is one click inside a confirmation dialog whose only defence is a
red button. A misclick permanently destroys the server record, its encrypted SSH
key, and every child row that cascades from it (databases, daemons, scheduled
tasks, tags, authorized SSH keys, database installations, terminal sessions).
There is no recovery path.

Two things are missing: friction proportional to the consequence, and
recoverability when the friction fails.

## Solution

Servers soft-delete into a trash that is visible and restorable for 30 days, and
the delete dialog requires typing the server name. Deleting, restoring, and
permanently purging a server all become admin-only operations.

## Data layer

A migration adds a nullable `deleted_at` timestamp to `servers`. The `Server`
model gains Laravel's `SoftDeletes` trait and a `TRASH_RETENTION_DAYS = 30`
constant.

`SoftDeletes` and `BelongsToOrganization` both register global scopes and are
evaluated independently at query time. In a request context a query yields
non-trashed servers in the bound organization. In the scheduler, where no
organization is bound, `OrganizationScope` is a no-op and the purge command sees
every organization's trash, which is what it needs.

The `servers` table carries no unique constraints, so a restored server cannot
collide with one created while it sat in the trash.

The eight foreign keys pointing at `servers` cascade only on a real delete. A
soft delete therefore leaves every child row in place and a restore returns a
fully wired server. A permanent purge fires the cascades and finally drops the
encrypted private key.

## Endpoints

`ServerController::destroy` keeps its existing guard: a server that still has
applications returns 422 and is not trashed. This is deliberate. It guarantees a
trashed server always has zero applications, which removes the hardest question a
restore could raise (what becomes of applications whose server disappeared)
before it can be asked. `$server->delete()` now soft-deletes.

Three endpoints are added:

| Method | Path | Action |
|--------|------|--------|
| GET | `/servers/trashed` | List trashed servers in the current organization |
| POST | `/servers/{server}/restore` | Restore a trashed server |
| DELETE | `/servers/{server}/force` | Permanently delete a trashed server |

`/servers/trashed` is declared before `apiResource('servers')` so it is not
matched by the `show` route. `restore` and `force` carry `->withTrashed()` on the
binding, without which route model binding returns 404 for exactly the rows these
endpoints exist to act on.

Each trashed server is serialized with a computed `purges_at` timestamp so the
frontend never has to know the retention period.

## Authorization

`destroy`, `restore`, and `forceDestroy` move into an `org.role:admin` group,
matching how web terminal access is gated. `index`, `show`, `store`, and `update`
keep their current authorization.

Because `withTrashed()` bindings bypass the soft-delete scope, the organization
scope is the only thing standing between a member of one organization and another
organization's trashed server. That boundary gets explicit test coverage.

## Purge command

`servers:purge-trashed` force-deletes servers whose `deleted_at` is older than
`Server::TRASH_RETENTION_DAYS`. It iterates models rather than issuing a mass
delete so that cascades and model events fire per row. It is registered in
`routes/console.php` at `dailyAt('04:00')`, clear of the 03:30 certificate
renewal.

## Frontend

The delete dialog in `ServerSettings.tsx` gains a text input. The action button
stays disabled until the input exactly matches the server name. The input resets
when the dialog closes so a reopened dialog never starts pre-confirmed. The copy
changes from "cannot be undone" to describe what actually happens: the server
moves to Trash and is permanently removed after 30 days.

A `Trash` item joins the Settings sidebar, listing trashed servers with name,
host, when they were deleted, when they purge, and Restore / Delete Permanently
actions. Delete Permanently carries its own type-the-name confirmation, since
that operation genuinely is irreversible. The section is visible only to admins
and owners.

## Testing

Feature tests cover:

- Deleting a server sets `deleted_at` instead of removing the row.
- A trashed server disappears from `index` and appears in `trashed`.
- Restoring returns the server to `index` with its databases and daemons intact.
- A server with applications still returns 422 and is not trashed.
- Force-deleting removes the row and cascades to children.
- The purge command deletes servers past retention and spares those inside it.
- A member of another organization receives 404 from `trashed`, `restore`, and
  `force`.
- A non-admin member receives 403 from `destroy`, `restore`, and `force`.

## Out of scope

Audit log entries for delete and restore. The audit log is currently spec-only
(`docs/superpowers/specs/2026-07-28-audit-log-design.md`); wiring these actions
into it belongs to that work.

Soft delete for any model other than `Server`.
