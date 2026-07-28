# Database Backups with Restore

Date: 2026-07-28
Status: Approved

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
