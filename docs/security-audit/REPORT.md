# ShipYard security audit — 2026-10-01

Reviewed commit: `7703051657f1b6727034287f040e64fbd10d33bf`. This completes the validation requested in SECURITY_AUDIT_HANDOFF.md. No application code, dependency locks, deployments, commits, or pushes were changed. Severity reflects the supplied multi-organization Docker deployment.

## Prioritized findings

### 1. Critical — tenant users can schedule commands in the shared ShipYard runtime

Sources: `backend/app/Http/Controllers/Api/OrganizationController.php:20`, `backend/app/Http/Controllers/Api/ServerController.php:31`, `backend/app/Http/Controllers/Api/ApplicationController.php:53`, `backend/app/Services/SSHService.php:180`, `backend/app/Services/Concerns/RunsRemoteScripts.php:17`, `docker-compose.yml:72`.

An authenticated member can create an organization, become its owner, create a server with `is_local=true`, create an application with a custom deployment script, and dispatch deployment. Local SSH service operations execute inside ShipYard. The supplied queue container runs as root with the backend and repository mounted, so this crosses the tenant boundary and puts shared code and credentials at risk.

Evidence: the audit test now creates both server and application through HTTP, verifies the persisted custom script, and confirms ProcessDeployment dispatch. Queue execution is faked; runtime execution follows from the inspected service and Docker configuration. A real malicious deployment and Docker-host escape were not attempted. The API reproduction needs no working external repository because dispatch is the tested boundary.

Fix: require an installation-wide administrator capability for local servers and reject tenant-controlled local execution at the execution boundary too. Separate privileged installation maintenance from tenant deployment workers and reduce worker privileges.

**Remediation — 2026-10-01:** Local execution support has been removed from managed servers entirely, rather than adding an administrator bypass to the shared tenant worker. The server API rejects local creation and mode changes; deployment requests for existing local records return 422. SSHService rejects local records for both SSH and SFTP, disconnects any previous session on rejection, and no longer contains local shell execution or filesystem fallbacks. This also protects jobs queued before the fix and non-HTTP callers. The new-server form offers SSH only, and existing local settings explain the restriction.

Existing local records are preserved for inspection, but their operations are disabled. Add an SSH-connected server on an appropriate deployment host and recreate applications there. Deploy the updated code and restart all long-lived queue/scheduler processes before considering the execution boundary protected. No deployment was performed as part of this change.

Regression coverage: `backend/tests/Feature/LocalServerSecurityTest.php` reproduces organization creation and rejects the local-server path, rejects mode changes and deployment dispatch, and runs already-queued atomic/in-place jobs through the real service boundary. `backend/tests/Unit/SSHServiceLocalSecurityTest.php` covers command and file operations after rejected SSH/SFTP connections; SSH connection tests also cover clearing a prior remote session. The original audit reproduction above remains historical evidence and intentionally expects the old vulnerable behavior.

Validation: 33 targeted backend tests passed (88 assertions) against disposable MySQL 8; frontend production build passed; lint on the two changed frontend files reported zero errors and two pre-existing hook-dependency warnings.

Scope: this closes the direct local-execution path in finding 1. Dedicated maintenance workers and reduced Docker worker privileges remain defense-in-depth work; the independent global-updater authorization issue is addressed separately in finding 3 below.

### 2. High — log filenames allow command execution by read-only members

Sources: `backend/app/Services/LogService.php:70`, especially `:80`, `:88`, `:92`, `:99`; `backend/routes/api.php:256`.

Filename validation rejects path separators but accepts shell operators. The filename is interpolated into unquoted commands. A member can request the URL-encoded filename `missing.log;echo SHIPYARD_AUDIT_MARKER;#`, causing the marker command to execute as the configured server SSH user. The HTTP 404 happens after execution and provides no protection.

Evidence: an actual member HTTP request invokes the generated existence-check command through a mocked SSH service; that command executes locally and emits only the harmless marker. No remote server is contacted.

Fix: quote every path argument, validate filenames against the intended filename policy, use option terminators where supported, and retain a permission-boundary regression test.

**Remediation — 2026-10-01:** LogService now permits only `.log` basenames beginning with an ASCII letter or digit and containing ASCII letters, digits, dots, underscores, or hyphens. Unsupported names are rejected before connecting to SSH and omitted from the file listing. Every log directory/file path is shell-quoted; `stat`, `tail`, and `grep` use option terminators so paths and search terms cannot become command options. The existing 404 response for invalid filenames is preserved.

Regression coverage: `backend/tests/Feature/LogSecurityTest.php` rejects the original member HTTP payload and additional shell/control-character filenames without invoking SSH, checks cross-organization denial, and exercises listing, stats, line counts, tailing, and option-prefixed searches against disposable local files through a mocked SSH transport. The fixture path contains spaces, quotes, and literal shell substitution to verify actual shell argument handling. These command fixtures use the Linux utilities expected by the application.

Validation: 14 tests passed (61 assertions) in the cached PHP 8.2/Linux container against disposable MySQL 8. No managed server was contacted or deployment performed. The original audit reproduction remains historical evidence and intentionally expects the old vulnerable behavior. Finding 3 is addressed below.

### 3. High — creating an organization grants access to the global updater

Sources: `backend/routes/api.php:263`, `backend/app/Http/Controllers/Api/OrganizationController.php:28`, `backend/app/Jobs/RunSystemUpdate.php`.

Installation-wide system routes check only organization ownership, which any authenticated member can acquire by creating another organization. An account denied access to POST /api/system/update receives 202 immediately after creating its own organization. This permits triggering changes to the shared installation independently of finding 1; it does not establish arbitrary update-code selection.

Evidence: HTTP reproduction confirms 403 before organization creation and 202 afterwards, with SystemUpdateService mocked. No update or maintenance mode was run.

Fix: gate all global system operations on a separate installation administrator capability.

**Remediation — 2026-10-01:** All four `/api/system/*` endpoints now require an authenticated user with the separate `is_installation_admin` capability. They sit outside organization middleware, so creating, owning, joining, or switching organizations cannot grant this capability or affect it. The flag defaults to false, is not mass assignable, and cannot be changed through profile or organization APIs. The System settings section is shown only to installation administrators.

Fresh installs grant the capability only to a newly created installer admin. Running the seeder against an existing account does not promote it based on its email. Existing installations deliberately receive no automatic administrator assignment: a trusted host operator must identify the intended existing user ID and grant access using `php artisan shipyard:installation-admin USER_ID`. Use `--revoke` to remove access. This command is host-console-only; there is no tenant-accessible grant endpoint.

Deployment: run `php artisan migrate --force`, clear/rebuild the route cache (`php artisan route:clear` or `php artisan route:cache`), deploy the rebuilt frontend, and restart long-lived application processes. For Compose, execute the Artisan commands with `docker compose exec app`. Grant the intended installation administrator after migration. Existing installations have no web updater access until this explicit grant. Already queued updates are not canceled by this HTTP authorization change. No deployment or production administrator assignment was performed here.

Validation: 28 targeted backend tests passed (114 assertions) in PHP 8.2/Linux against disposable MySQL 8, with the old local route cache bypassed. Coverage includes the original create-organization escalation, every system endpoint, rejected profile/mass-assignment escalation, console grant/revoke, seeder behavior, and successful update dispatch by an installation administrator whose organization role is member. Frontend production build passed. The stale local route cache was subsequently cleared. Finding 4 is the next unresolved application finding.

### 4. High — SSH/SFTP and Git connections do not retain or verify server identity

Sources: `backend/app/Services/SSHService.php:50`, `:86`; `backend/app/Services/TerminalService.php:45`; Git SSH wrappers in `backend/app/Services/DeploymentService.php` and `GitProviderService.php`.

SSH and SFTP authenticate without checking a pinned server key. Git wrappers disable strict checking or use accept-new with /dev/null, discarding trust between connections. An attacker who can intercept or redirect these connections can impersonate a server, receive uploaded secrets/scripts, or supply altered repository content. SSH public-key authentication does not itself reveal the client private key.

Evidence: code review, no live MITM experiment. This is separate from the phpseclib dependency advisory.

Fix: store and verify fingerprints, provide an explicit first-connection trust flow, reject unexpected key changes, and retain known-hosts data for Git.

### 5. Medium — empty gzip restore is rejected only after replacing the target database

Sources: `backend/app/Support/DumpInspector.php:90`, `backend/app/Http/Controllers/Api/DatabaseRestoreController.php:72`, `backend/app/Services/BackupRestoreService.php:113` and `:230`.

A valid gzip stream containing zero decompressed bytes is accepted as a dump. An authorized overwrite then takes a safety dump, drops/recreates the target, loads nothing, and fails only at table-count verification. An accidental empty upload therefore causes avoidable downtime and manual recovery. The required overwrite confirmation and retained safety dump limit severity; this is not a member authorization bypass or proof of unrecoverable data loss.

Evidence: the new test uploads gzencode('') through HTTP, receives 202, then invokes the real restore orchestration with SSH mocked. It observes the safety dump before DROP DATABASE, CREATE DATABASE, and a failed zero-table check. The resulting run retains its safety_dump_path. No real target database was dropped.

Fix: reject empty decompressed input and check gzip integrity before destructive work. For stronger protection against syntactically invalid or partial SQL, restore and validate in a staging database before replacement; prefix classification alone cannot establish that an entire dump is restorable.

The earlier corrupt-gzip fixture fails on local PHP 8.4 but is rejected on cached PHP 8.2.33. That exact compatibility regression is not a confirmed production PHP 8.2 vulnerability.

### 6. Medium — safety-backup pruning never expands the filename wildcard

Source: `backend/app/Services/BackupRestoreService.php:270`.

The entire `/var/backups/shipyard/shop-*.sql.gz` argument is shell-quoted, making the asterisk literal. Normal timestamped backup files never match. The pipeline hides the error and repeated restores can retain dumps indefinitely, consuming disk space.

Evidence: the test captures the actual generated command via reflection, substitutes only a disposable fixture directory, and executes it. All five timestamped files remain although retention is three; the pipeline exits zero. Fixtures are cleaned up afterwards.

Fix: enumerate files safely, for example using find with a quoted name pattern, and verify real file retention rather than only asserting command text.

### 7. Medium — quick-install queue reservation is shorter than job timeouts

Sources: `backend/config/queue.php:30`, `install.sh:358`, `backend/.env.example:36`, `backend/app/Jobs/ProcessDatabaseRestore.php:36`.

Redis retry_after defaults to 90 seconds, while jobs allow 1800 seconds and restores 3000 seconds. The example environment sets 3600, but install.sh writes its own environment without that setting and update.sh does not migrate it. With multiple workers, a still-running job can be redelivered, causing concurrent work or premature failure depending on its attempt policy.

Evidence: configuration and job review. No Redis redelivery experiment. The supplied Compose file has one queue worker; ordinary single-worker deployments are not demonstrated to duplicate after 90 seconds. Deployment locks and restore claims mitigate duplicate execution, but do not correct reservation/attempt accounting.

Fix: set a safe default above the longest job timeout and write/migrate the value consistently during install and update.

## Dependency exposure assessment

Fresh audits on 2026-10-01 still report 15 Composer advisories across four packages and 21 npm affected package entries (12 high, 7 moderate, 2 low). Package counts are not independent exploitable application findings. Raw snapshots are saved alongside this report.

- Laravel debug-page advisory: install.sh sets APP_DEBUG=false, while the example environment enables debug. Exposure depends on debug deployment configuration; no exploit reproduced.
- CommonMark: no application Markdown rendering or Markdown-mail calls were found in backend/app. Twelve advisories remain dependency hygiene work, but no attacker-controlled rendering path was established.
- Flysystem: restore storage uses a generated UUID filename and fixed directory; the client filename is metadata. The malformed-path advisory was not shown reachable through this upload path.
- phpseclib: the advisory expressly excludes its normal ephemeral SSH key exchange. No long-lived X25519 operation with the required fine-grained observation was established. See the [advisory restrictions](https://github.com/advisories/GHSA-q97c-8qh3-fpc6). This does not reduce finding 4.
- Axios: usage found in frontend/src/services/api.ts is browser-side. Node HTTP/proxy advisories are not evidence of server SSRF here. Prototype-pollution gadget advisories require additional preconditions; no enabling prototype-pollution path was established in this audit.
- React Router: the app uses BrowserRouter, not SSR hydration. Reviewed navigation targets are static paths or application-generated ID paths; no attacker-controlled arbitrary redirect target was identified. This is a reachability assessment, not a guarantee that every advisory is inapplicable.
- Vite/build tooling: supplied production Nginx serves built assets. Development-server advisories require a relevant exposed development server or build workflow, not merely an affected lockfile.

Upgrade affected dependencies in a separate tested remediation change. Prioritize the demonstrated application authorization and command-execution flaws first.

## Validation and limits

New evidence: `ShipyardAuditTest.php`, `targeted-tests-2026-10-01.log`, `composer-audit-2026-10-01.json`, `npm-audit-2026-10-01.json`.

The five targeted tests passed with 30 assertions against disposable MySQL 8. They intentionally assert vulnerable behavior; passing is evidence of the findings, not a security pass. They remain outside backend/tests. SSH, restore commands, deployment execution, and updater execution are mocked except for the harmless local log marker and disposable retention fixture.

Prior handoff checks remain applicable to the unchanged reviewed commit: frontend build passed; lint had zero errors and 18 warnings; backend suite had 663 passing tests and the one PHP-version-dependent corrupt-gzip failure described above. Broad checks were not unnecessarily rerun. No real managed servers were modified. This was a targeted repository audit, not a live penetration test or proof that no other defects exist.
