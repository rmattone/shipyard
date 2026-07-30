# Database Backups with Restore

Date: 2026-07-28
Status: Approved
Amended: 2026-07-30 (see "Amendment: restore from an uploaded dump")

## Summary

Scheduled and manual backups of MySQL and PostgreSQL databases on managed servers, stored in S3-compatible destinations, with restore into the same database connection. Backups run self-contained on the target server via cron (Forge style), so they survive ShipYard downtime. ShipYard orchestrates install, manual runs, and restores over SSH, and learns about scheduled runs through a callback endpoint.

## Requirements

- Engines: MySQL and PostgreSQL (the two engines ShipYard installs and manages).
- Destinations: S3-compatible object storage only (AWS S3, Cloudflare R2, MinIO, Backblaze B2). No local disk, no SFTP.
- Scheduling: cron expression per backup config, plus a manual "back up now" action.
- Retention: keep the last N backups per config, pruned after each successful run.
- Restore: any stored backup restores into the same database connection, either overwriting the original database or into a new database name.
- Notifications: failures only (backup failed, restore failed, backup overdue), through the existing notification channels (Discord, Telegram, Resend email).

## Architecture

Backups execute on the target server without ShipYard involvement. Each backup config materializes as two artifacts on the server, managed the same way ScheduledTask and CrontabService manage cron entries today:

1. A rendered backup script at `~{deploy_user}/.shipyard/backups/backup-{config_id}.sh`, mode 700. It dumps the database, compresses, uploads to S3, prunes old objects, reports to ShipYard, and cleans up. Database admin credentials are passed via `--defaults-extra-file` for MySQL and `PGPASSWORD` inside the script for PostgreSQL, never on a process command line.
2. A cron line in the existing managed crontab block for the deploy user, invoking the script with output appended to a log file (same pattern as `ScheduledTask::getLogPath()`). Servers without a provisioned deploy user fall back to root's crontab, matching where legacy layout scheduled tasks already run.

### Uploader

rclone, installed once per server on first backup config, following the DatabaseInstallationService "detect, install if missing" pattern. It is a single static binary (about 50 MB), supports every S3-compatible endpoint, and handles both upload and prune. Per-destination credentials are written to `~/.shipyard/backups/rclone-{destination_id}.conf`, mode 600.

Trade-off accepted: S3 credentials live on the target server. A compromised server exposes the bucket. Users should scope the credentials to a dedicated bucket.

### Run reporting

The script curls `POST /api/backup-runs/{config}/report` with a per-config secret token (same trust model as the deploy webhook), reporting success or failure, which step failed, size, duration, and the S3 object key. The callback must never fail the backup (`curl ... || true`). If ShipYard is down during a run, the history row is missing but the backup exists.

The restore picker does not depend on run history. It lists S3 objects live over SSH via rclone, consistent with how remote databases are listed live rather than stored as rows.

### Overdue safety net

The scheduler container runs a periodic check: any enabled config whose last reported success is older than its cron interval plus a grace period of one full interval triggers a "backup overdue" notification. This covers dead servers, broken crontabs, and callbacks lost while ShipYard was down.

## Data model

Three tables.

### backup_destinations (tenant root, BelongsToOrganization)

- name
- endpoint, region, bucket, path prefix
- access key and secret, both `encrypted` casts and `$hidden`, like `Database::admin_password`

### backup_configs

- database_id (the connection row)
- backup_destination_id
- database_name (string; remote databases are not rows)
- cron frequency fields, same shape as scheduled_tasks
- keep_last (integer)
- secret_token (for the callback)
- enabled (boolean)
- status: installing, installed, failed, removing (reusing the ScheduledTask state machine)

### backup_runs

- backup_config_id
- trigger: cron or manual
- status, s3_key, size_bytes, duration, error excerpt, timestamps

S3 object layout: `{prefix}/{server}/{database_name}/{timestamp}.sql.gz`, so prune and restore listings are a single prefix listing.

## Flows

### Config install

Creating a config dispatches `ProcessBackupConfigInstall` (mirrors `ProcessScheduledTaskInstall`): SSH in, ensure rclone is present (install if missing), write the rclone conf and backup script, add the cron line to the managed block, mark installed. Editing re-renders both files. Deleting runs a removal job: drop the cron line, delete the script, delete the rclone conf if it is the destination's last config on that server. S3 objects are never touched by config deletion.

### Scheduled run (server-side only)

1. Cron fires the script.
2. Dump `database_name` piped through gzip to a temp file under `~/.shipyard/backups/tmp/`.
3. `rclone copyto` the file to `{prefix}/{server}/{db}/{timestamp}.sql.gz`.
4. Prune: list the prefix, delete all but the newest keep_last objects.
5. Curl the callback with the outcome payload (`|| true`).
6. Delete the temp file. Cleanup is trap-based so a failed step still removes it.

Every step appends to the run log. The script exits non-zero on dump or upload failure, and the callback payload names the failed step.

### Callback

Public route validated by the per-config secret_token. Creates the backup_run row. On a failure payload, dispatches the failure notification job.

### Manual run

A queued job SSHes in and executes the same script synchronously, then writes the run row itself from the exit code, with no callback dependency. Because it is the same script, a manual run also validates the cron setup.

### Restore

Always ShipYard-orchestrated. The UI lists S3 objects live via rclone over SSH. The user picks an object and a target: overwrite the original database, or type a new database name. A queued `ProcessDatabaseRestore` job creates the target database if new (existing `createDatabase`), then runs `rclone cat s3:... | gunzip | mysql` (or psql), streaming progress into a run-style log. Restoring over the original database requires typing the database name to confirm.

### Notifications

Failures only, three events: backup failed (from callback or manual run), restore failed, backup overdue. All flow through the existing NotificationChannel drivers. The DeploymentNotificationPayload shape gets generalized or joined by a backup payload as needed.

## Error handling

- rclone install fails: config marked failed with log, like failed task installs.
- Dump succeeds but upload fails: temp file removed, callback reports upload_failed. Nothing half-written lands in S3 (rclone copyto is atomic per object).
- Prune failure: the backup is still reported successful. The prune error is noted in the run log and notified separately. Data safety beats tidiness.
- Callback unreachable: the backup completes silently. The overdue check is the backstop.
- Restore over the original database is not transactional. The UI states plainly that a failed restore leaves the database partial and recommends restoring to a new name first.

## Security notes

- The callback route is unauthenticated by design; a per-config secret token in the request payload authenticates it. It needs the same organization scope care as the deploy webhook: resolve the config without the global scope, validate the token with a constant-time comparison, and never leak existence of other configs.
- Destination credentials are encrypted at rest in ShipYard and mode 600 on servers.
- Backup scripts are mode 700 and contain database admin credentials; they live under the deploy user's home (mode 711 home per the deploy user layout).

## Testing

Unit:

- Script rendering for both engines, including credential quoting and keep_last arithmetic.
- rclone conf rendering.
- Overdue detection math.

Feature (mocked SSHService, NginxTemplateTest style):

- Config CRUD and install job.
- Callback endpoint auth: wrong token, config from another organization, disabled config.
- Restore job command assembly.

Manual verification on a fresh EC2 instance: create a config with a `* * * * *` schedule, watch a run land in the bucket, then restore to a new database name.

## Out of scope for v1

- Cross-server restore.
- MongoDB, Redis, MariaDB-specific handling (MariaDB works through the MySQL driver).
- Success notifications and per-channel event toggles.
- Multipart upload tuning for very large dumps (rclone chunks large files by default, so the 5 GB single PUT limit does not apply here).
- Backup of application files or volumes.

---

# Amendment: restore from an uploaded dump

Date: 2026-07-30
Status: Approved

## Why

The restore flow above assumes the dump already lives in an S3 destination. The common case in practice is a dump sitting on a developer machine, produced by `pg_dump` or `mysqldump` somewhere else entirely (a production box ShipYard does not manage, a colleague, a CI artifact). Reaching it today means creating a destination, uploading by hand with another tool, and only then restoring. This amendment adds a second restore source, a direct file upload, and it deliberately reuses the restore machinery defined above instead of introducing a parallel one.

This amendment can ship before the rest of the feature. It needs the `backup_runs` table and model, `BackupRestoreService`, `ProcessDatabaseRestore`, and the SSE log. It does not need rclone, destinations, configs, cron, or the callback, because an uploaded dump never touches S3.

## Source model

Restore gains an explicit source, recorded on the run:

- `s3`: the flow already specified. The object is listed live through rclone and streamed with `rclone cat`.
- `upload`: a dump uploaded through the ShipYard UI, stored briefly on the ShipYard host, then pushed to the target server over SFTP.

Everything after the bytes land on the target server is shared: the same target selection, the same confirmation rule, the same log, the same run row, the same failure semantics.

## Data model changes

`backup_runs` carries restores for both sources, distinguished by the `kind` column (`backup` or `restore`) that the implementation plan already defines. Changes:

- `backup_config_id` becomes nullable. An uploaded restore has no config to anchor to.
- `s3_key` is nullable and stays empty for uploaded restores, which never touch object storage.
- `trigger` is `manual` for every uploaded restore, since there is no cron path to it.
- `database_id` is added (FK to `databases`), always set. For config-based runs it matches the config's connection. This is what an uploaded restore is scoped and authorized by, and what the organization scope traverses.
- `database_name` already exists and carries the restore target.
- `source` is added, values `s3` or `upload`, nullable for backup runs where it has no meaning.
- `original_filename`, `byte_size`, and `format` (values `sql` or `sql_gz`) are added, populated for uploaded restores.
- `upload_path` is added, hidden from API responses, holding the location on the ShipYard host so the job can find the file and cleanup can find orphans.
- `safety_dump_path` is added, holding the pre-restore dump location on the target server.

The model reaches the organization through `database.server` using `BelongsToOrganizationThroughParent`, which already supports dotted relations. Restores are route-bound records that can destroy a database, so this scope is load-bearing rather than cosmetic.

## Accepted formats

Plain SQL and gzipped plain SQL, for both engines. The format is resolved by sniffing the first bytes rather than trusting the extension, because `.sql.gz` has no reliable MIME type:

- `1f 8b` means gzip.
- A leading `--`, `SET`, or `/*` means plain SQL.
- A leading `PGDMP` is a custom-format `pg_dump` archive. It is rejected with a message naming `pg_restore`, rather than a generic unsupported-file error, because the fix is specific and the user needs to know it.

## Size ceiling and infrastructure

The ceiling is 1 GB compressed. Nothing in the current stack is sized for that, so all three limits move together:

- PHP runs on defaults today (`upload_max_filesize` 2M, `post_max_size` 8M) because there is no custom ini. Both go to 1200M as `php_admin_value` entries in `docker/php/zz-shipyard.conf`. That file is baked with `COPY`, so applying it needs `docker compose build app`, not a container restart.
- nginx caps bodies at 100M globally (`docker/nginx/default.conf`). The global cap stays. A location block scoped to the restore upload route raises it to 1200M, with a matching `client_body_timeout`. Editing that file needs `docker compose restart nginx`, because the single-file bind mount goes stale when an edit replaces the inode.
- Uploads never enter memory. PHP spools the request body to a temp file and `storeAs()` moves it. The transient cost is roughly the file size twice, once in the nginx body temp directory and once in `storage/app/restores`, so the controller checks free disk before accepting and fails early with a clear message instead of dying mid-write.

## Target selection and guardrails

The target rule from the main spec stands, and the upload source inherits it rather than redefining it. Restoring into a new database name is the default and the recommended path. Overwriting an existing database requires typing the database name to confirm, and the API enforces the match server-side so the dialog is not the only thing standing between a misclick and a wiped database.

Overwriting is implemented as drop, recreate, then load, because plain `pg_dump` and `mysqldump` output assumes an empty target. The recreate reads the current owner and encoding (PostgreSQL) or charset and collation (MySQL) before the drop and reuses the drivers' existing `dropDatabase` and `createDatabase` methods, so the new database matches the old one and the DDL is not duplicated.

A safety dump of the target is taken before any destructive step in the overwrite path, written to `/var/backups/shipyard/` rather than `/var/tmp` so it survives a reboot. Each successful restore prunes that directory to the three most recent dumps per target database, which bounds the growth without needing a new scheduled command.

The upload endpoint is gated by `org.role:admin`, matching the destructive server actions. `org.writes` already blocks members ahead of it, so the admin gate is the meaningful one.

## Credentials

Database credentials follow the convention already used by both drivers, `PGPASSWORD` and `MYSQL_PWD` populated through `escapeshellarg`, never a password on a command line. The restore does not assume passwordless `sudo` on the target server, so it works with the stored admin credentials alone.

## Execution

`ProcessDatabaseRestore` runs with `tries = 1`, because a destructive operation must never be retried automatically, and a timeout sized for a gigabyte load. `BackupRestoreService` drives discrete SSH calls with a log append per step, following `DatabaseInstallationService::runCommand`, rather than one bundled remote script. A bundled script only returns its output at the end, which would leave the user watching a dead panel for several minutes.

Steps for the upload source:

1. Preflight. Confirm the target exists (or does not exist, when restoring to a new name), the client binaries are present, and the server has disk headroom for the dump plus the safety dump.
2. Push the dump to `/var/tmp` over SFTP, mode 600.
3. Safety dump, when overwriting.
4. Drop and recreate, when overwriting. Create, when restoring to a new name.
5. Load, as `gunzip -c dump | psql -v ON_ERROR_STOP=1` or the `mysql` equivalent, so a mid-dump error aborts instead of leaving a silently half-loaded database.
6. Verify. Table count and row totals, appended to the log.
7. Cleanup. Remove the dump from the target server and from the ShipYard host.

## API surface

```
POST   /servers/{server}/databases/{database}/restores    multipart upload, returns 202 and the run
GET    /servers/{server}/databases/{database}/restores    restore history for the connection
GET    /backup-runs/{run}                                 status poll fallback
GET    /backup-runs/{run}/stream                          SSE log
```

The multipart body carries `dump`, `target_database`, `overwrite`, and `confirm_name`. The S3 restore endpoint from the main spec is unchanged and continues to live under its config.

The stream route sits outside `auth:sanctum` with the other SSE routes, so it validates the query parameter token, organization membership, and the admin role by hand. It caps at 1800 seconds and the client reconnects on timeout, resuming from its log offset. This matters because the log is persisted on the run rather than existing only in Redis, and because every SSE stream pins a php-fpm worker. The 300 second cap in `DatabaseInstallationStreamController` is too short to copy here.

## Frontend

`DatabaseDetail.tsx` is already 817 lines, so the new surface goes in its own components, `RestoreDatabaseDialog.tsx` and `RestoreLogPanel.tsx`, rather than growing that file further.

The dialog states what is about to happen (which database is affected, that it will be dropped and recreated when overwriting, that a safety dump is taken first), requires the typed name for overwrites, and uploads with an axios `onUploadProgress` handler so the progress bar reflects real bytes. On a 202 it switches to the log panel on the SSE stream, the same shape as the installation log in `ServerSoftware.tsx`. A short restore history sits below, showing status, size, when, and who ran it.

## Error handling

Every failure appends to the log and marks the run failed. The uploaded file is removed on both success and failure, from the job's `finally` and from `failed()`, so a killed worker cannot leak a gigabyte into `storage/app`.

A load that dies partway leaves the target partially populated. The failure message says so explicitly and prints the safety dump path, so recovery is one command rather than an investigation. This is the same non-transactional warning the main spec makes for S3 restores, and it is the reason restoring to a new name is the default.

## Testing

Feature tests with faked SSH results, following the `fakeResults` approach in `RollbackReliabilityTest`:

- The admin gate, and a member being refused.
- Cross-organization access returning 404 through the `database.server` scope.
- `confirm_name` mismatch refused when overwriting.
- The size cap, and the free-disk refusal.
- Format sniffing for gzip, plain SQL, and the `PGDMP` rejection.
- Target must exist when overwriting, and must not exist when restoring to a new name.
- Safety dump ordering, proving it precedes the drop.
- Upload cleanup on both the success and failure paths.
- The three SSE authorization checks.

Manual verification on a throwaway EC2 instance with a real dump, restoring first to a new database name and then over an existing one.

## Out of scope for this amendment

- Chunked or resumable uploads. The 1 GB ceiling is a single request by design.
- Custom-format `pg_dump` archives and `pg_restore`.
- Uploading a dump into an S3 destination as a side effect of restoring.
- Cross-server restore, which the main spec already excludes.
