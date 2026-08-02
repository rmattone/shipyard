# ShipYard Testbed Application

Date: 2026-07-28
Status: Draft for review
Companion to: 2026-07-28-shipyard-user-home-layout-design.md (the feature this app verifies)

## Context

ShipYard's deploy and provisioning features are verified manually on fresh EC2 instances that are deleted after each run. That verification currently means SSH commands and log tailing, with the knowledge of "what should be true" living in a checklist. The deploy user home layout feature added more subsystems worth checking on every run (FPM pool user, writable path ownership, single writer daemons and cron).

The testbed is a small Laravel application that ShipYard deploys to the target server. It makes every subsystem's health observable from one URL, so an EC2 verification run collapses to: deploy the testbed, open `/status`, expect green.

## Goals

1. One `/status` page (HTML and JSON) aggregating pass/fail checks for every subsystem a ShipYard deploy exercises.
2. Failures are diagnostic, not binary: each check reports expected, actual, and detail, so a red row names the broken subsystem and the offending fact (for example, the unix user that wrote a stale heartbeat).
3. The same app validates legacy servers and home layout servers: expectations arrive via environment variables, and checks without an expectation report facts without judging.
4. A README run book that turns the manual EC2 checklist into ordered, executable steps ending at `/status`.

## Non-goals

1. Not a product. No auth, throwaway servers only, stated plainly in the README.
2. No SSL/certbot automation (needs a real domain; listed in the run book as a manual extra).
3. No Node.js or static site variants yet (trivial to add later as separate repos).
4. No frontend build step (Blade templates only, keeping deploys free of npm).
5. No Redis. The queue uses the database driver so the testbed exercises ShipYard's database provisioning instead of adding a dependency fresh servers lack.

## Design

### Repository

Public GitHub repository `shipyard-testbed` under the owner's account. Laravel 12, PHP 8.2 or newer (target servers default to 8.3). Cloned anonymously over HTTPS, which also exercises ShipYard's no-git-provider deploy path. The app is developed in a sibling directory of the server-management checkout.

### Check layer

`app/Checks/` holds one class per check implementing a small interface: `name(): string` and `run(): CheckResult`. `CheckResult` is a value object with `name`, `pass` (bool, or null meaning informational), `expected` (nullable string), `actual` (string), `detail` (nullable string). Checks never throw: `run()` bodies wrap their work and convert exceptions into `pass: false` with the message in `detail`. A `ChecksRunner` collects results from a registered list.

The checks:

1. `RuntimeUserCheck`. The unix user PHP executes as (posix functions). When env `TESTBED_EXPECTED_USER` is set, pass means equal; unset means informational. This is the FPM pool verification.
2. `DatabaseCheck`. Insert, read back, and delete a row in the heartbeats table. Actual reports driver and server version. Proves credentials, the ShipYard-created database, and env sync in one step.
3. `QueueHeartbeatCheck`. Age of the newest `source = queue` heartbeat plus the `ran_as` user that wrote it. Fails when older than `TESTBED_QUEUE_MAX_AGE` seconds (default 300). Proves the daemon runs, reaches the database, and runs as the right user.
4. `SchedulerHeartbeatCheck`. Same for `source = scheduler`, default max age 120 seconds.
5. `StorageWriteCheck`. Write and delete a file under `storage/app`, append a line to the log. Actual reports owner and mode of `storage/`. Catches the ownership class of bug.
6. `EnvSyncCheck`. Presence of `TESTBED_MARKER`, a variable set through ShipYard's env panel. Proves the encrypted environment pipeline end to end.
7. `ReleaseInfoCheck`. Informational: absolute base path (shows `/home/...` or `/var/www/...`, and whether it is a `releases/N` path, proving atomic deploys), PHP version, current commit when a REVISION file exists.

### Heartbeat plumbing

One `heartbeats` table: `id`, `source` (string: queue or scheduler), `ran_as` (string, unix user), `created_at`. A queued `RecordHeartbeat` job inserts a queue row. A console command `testbed:heartbeat`, scheduled every minute, inserts a scheduler row and dispatches `RecordHeartbeat`. Once cron works, queue heartbeats flow with no manual action, and the two checks triangulate failures: scheduler fresh with queue stale means a daemon problem; both stale means a cron problem.

### CRUD and routes

A `notes` resource (title, body) with plain Blade views and no auth, for interactive database testing. Routes: `/` is a landing page linking everything; `/status` renders the HTML table (green, red, grey rows); `/status/json` returns the same results as JSON for curl and Playwright assertions. `/status` must render when the database is down: database dependent checks fail with detail, the page still loads.

### Deploy and environment contract

ShipYard's default Laravel deploy script works unchanged (composer install, `php artisan migrate --force`, cache warmup). The repo ships `.env.example`; real values arrive through ShipYard's env panel. Environment variables the testbed reads:

| Variable | Meaning | Default |
|---|---|---|
| `TESTBED_EXPECTED_USER` | Expected PHP runtime user | unset (informational) |
| `TESTBED_QUEUE_MAX_AGE` | Queue heartbeat freshness in seconds | 300 |
| `TESTBED_SCHEDULER_MAX_AGE` | Scheduler heartbeat freshness in seconds | 120 |
| `TESTBED_MARKER` | Any value, proves env sync | unset (check fails until set) |
| standard `DB_*`, `APP_KEY`, `QUEUE_CONNECTION=database` | Laravel basics | from env panel |

### Run book (the testbed README)

Ordered steps mapping one to one onto ShipYard actions: connect the fresh server, provision the `shipyard` deploy user with "use as deploy user", switch the connection to it, create a database and database user in ShipYard, create the application from the public repo URL (no git provider), set env vars including `TESTBED_MARKER` and `TESTBED_EXPECTED_USER=shipyard`, deploy, add a `queue:work` daemon, add a scheduled task running `php8.3 /home/shipyard/{app}/current/artisan schedule:run` every minute, open `/status`, expect all rows green within two minutes. A closing section lists legacy mode differences (`TESTBED_EXPECTED_USER` unset, paths under `/var/www/shipyard`) and manual extras (SSL issuance, rollback, import: delete the app row in ShipYard, run import, confirm re-adoption).

### Error handling

Checks catch everything and degrade to failed rows with the exception message in detail. The status controller catches nothing beyond what checks handle, so a page level failure (for example, a fatal in the framework) is itself a signal. Heartbeat writers record `ran_as` from posix at execution time, never from configuration.

### Testing the testbed

Light by intent, it is a fixture: feature tests that `/status` and `/status/json` render with all checks present and with the database connection broken (checks fail, page loads), a unit test per check for the pass/fail/informational boundaries using faked env and clock, and one test that `testbed:heartbeat` inserts a scheduler row and dispatches the job. SQLite in memory for the test suite so it runs anywhere.

## Success criteria

A fresh EC2 run following the README ends with every `/status` row green, and each of these deliberate breakages turns exactly the expected row red: stop the daemon (queue heartbeat), remove the crontab line (scheduler heartbeat), chown `storage/` to root (storage write), unset `TESTBED_MARKER` (env sync), point the app at a wrong DB password (database, plus the two heartbeat rows going stale later).
