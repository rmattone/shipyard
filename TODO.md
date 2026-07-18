# ShipYard Audit TODO

Full findings from the July 2026 project audit. Each item is self-contained: what the problem is, where it lives, how it fails, and the direction of the fix. Severity reflects real-world impact for a single-admin, self-hosted deployment (the project's threat model).

Legend: `[ ]` open, `[x]` done. IDs are stable, reference them in commits/PRs.

---

## 1. Tests and tooling (fix first, unblocks everything else)

- [x] **TEST-1 (Critical): Test suite is broken, 10 of 16 tests fail.**
  Migration `backend/database/migrations/2026_01_28_000004_add_pm2_to_database_installations.php:10` (and the `2026_04_04_*` enum migrations) use MySQL-only `ALTER TABLE ... MODIFY COLUMN ... ENUM(...)`. Tests run on sqlite `:memory:` (`backend/phpunit.xml`), so `RefreshDatabase` dies with `SQLSTATE[HY000]: near "MODIFY": syntax error` before any assertion runs. Verified by running `php artisan test`. Effective regression coverage today is one unit test.
  Fixed (July 2026): tests now run against MySQL (`server_management_testing` database) instead of sqlite, per user decision. `phpunit.xml` forces connection and database only; credentials come from `backend/.env`. Init script `docker/mysql/init/create-test-db.sh` creates the test DB on fresh installs; README documents the one-time grant for existing volumes.

- [x] **TEST-2 (High): `npm run lint` can never succeed.**
  `frontend/package.json:9` defines `"lint": "eslint ."` and ESLint 8 is installed, but no ESLint config file exists anywhere in `frontend/`. The command always exits with "couldn't find a configuration file". CLAUDE.md documents this command as if it works.
  Fixed (July 2026): added `.eslintrc.cjs` (Vite react-ts template) plus `@typescript-eslint` v7 packages, cleaned up `tailwind.config.js` (tabs and `require` in an ESM file). Lint exits 0 with 19 `react-hooks/exhaustive-deps` warnings remaining, which relate to FE-5/FE-7.

- [ ] **TEST-3 (High): Near-zero test coverage on everything that matters.**
  Only 3 test files exist (`ServerApiTest`, `ApplicationApiTest`, `DeployScriptTest`, ~14 tests). Zero tests for: webhooks, deployments, rollbacks, env vars (including encryption round-trip), domains/SSL, git providers, databases/users/installations, logs, nginx config, system update, both SSE controllers, and every service class. Only 4 factories exist (User, Server, Application, Deployment).
  Fix: after TEST-1, add factories for the remaining models and cover each bug fixed from this list with a regression test.
  Partially done (July 2026): factories added for DatabaseInstallation, Database, DatabaseUser, GitProvider, Domain, Tag; regression tests for DEPLOY-1/DEPLOY-2 (`AtomicDeploymentTest`); webhook auth tests (`WebhookTest`); env encryption round-trip (`EnvironmentVariableEncryptionTest`). Suite is now 26 tests. Bonus fix: `bootstrap/providers.php` was missing entirely so `AppServiceProvider` never loaded, and its `DeploymentService` binding had wrong constructor args; provider is now registered with the broken bindings removed (container auto-wiring). Coverage of remaining areas continues incrementally.

---

## 2. Deployment pipeline

- [x] **DEPLOY-1 (Critical): Release cleanup never deletes anything, disk fills forever.**
  `backend/app/Services/AtomicDeploymentService.php:279` uses `trim($releaseDir, '/')`, which strips the leading slash from the absolute path returned by `ls -1d`. The subsequent `rm -rf` runs against a nonexistent relative path (relative to the SSH user's home), and `-f` makes it exit 0, so the log claims "Removed: ..." anyway. Every atomic deploy leaves a full clone (vendor/, node_modules/) on the server permanently.
  Fix: `rtrim($releaseDir, '/')`. Same class of bug already fixed once in commit `7da107f`.
  Fixed (July 2026): `rtrim` applied, plus `max(1, releases_to_keep)` guard and a skip for the release the `current` symlink points to (via `getCurrentReleasePath()`). Regression tests in `AtomicDeploymentTest`.

- [x] **DEPLOY-2 (Critical): Atomic Node.js deployments never go live.**
  The atomic Node.js script template ends with a comment "PM2 restart is handled after symlink activation" (`backend/app/Models/Application.php:282`), but nothing in `DeploymentService::runAtomicDeployment` (`backend/app/Services/DeploymentService.php:34-88`) or `AtomicDeploymentService` touches PM2 after `activateRelease`. PM2 keeps running code from the old release directory; on a first deploy nothing is started at all. Once DEPLOY-1 is fixed this worsens: PM2 would run from a deleted directory.
  Fix: add a PM2 reload/start step after `activateRelease` for Node.js apps, using the `current` symlink as cwd.
  Fixed (July 2026): new `Application::buildPm2RestartCommand()` (sources nvm, honors `node_version`, runs `pm2 restart || pm2 start` in the current path) called from `DeploymentService::restartNodeProcess()` after activation, throwing on failure. `RollbackService::runNodejsPostRollbackTasks()` reuses it and now also fails loudly (it previously ran bare `pm2 restart` without nvm and ignored errors). Regression tests in `AtomicDeploymentTest`.

- [x] **DEPLOY-3 (High): No concurrency control on deployments.**
  No lock, no `ShouldBeUnique`, no in-flight check in `ApplicationController::deploy` (`backend/app/Http/Controllers/Api/ApplicationController.php:191-222`), `WebhookController`, or `ProcessDeployment`. It only works because docker-compose runs exactly one queue worker. Two workers (or the frontend double-fire bug FE-2) mean interleaved git operations and symlink swaps on the same app. Note `DatabaseController::install:47-56` already has a duplicate-run guard to copy.
  Fix: per-application lock (cache lock or `WithoutOverlapping` middleware) plus a "deployment already running" 409 in the controller.
  Fixed (July 2026): shared `WithoutOverlapping` lock on both jobs, 409 guards on deploy/rollback endpoints, 200-skip on the webhook. Also fixed `phpunit.xml` to set `CACHE_STORE` (Laravel 11 ignores `CACHE_DRIVER`).

- [x] **DEPLOY-4 (High): Release ID collisions at 1-second resolution.**
  `Deployment::generateReleaseId()` (`backend/app/Models/Deployment.php`, timestamp format) gives two deployments created in the same second the identical `release_id` and `release_path`. The second clone fails with "destination path already exists" (or clobbers, combined with DEPLOY-3).
  Fix: append a uniq suffix (deployment id or random) to the release id.
  Fixed (July 2026): random 6-char suffix, `LC_ALL=C` on release sorts, and a migration widening `deployments.release_id` (it was sized exactly to the old format).

- [x] **DEPLOY-5 (High): Failed releases are never cleaned up and poison the retention window.**
  On failure, the partially built release directory is left on disk (no removal in the catch path of `DeploymentService.php:79-87`). Cleanup (`AtomicDeploymentService.php:263-284`) keeps the N newest directories regardless of success. Five failed deploys in a row can push every good release (including the active one's rollback target) out of the window once DEPLOY-1 is fixed.
  Fix: delete the release dir in the failure path, and make cleanup skip the release the `current` symlink points to.
  Fixed (July 2026): failure path removes the partial release (guarded to paths under the releases dir, only when activation was not reached); the skip-current guard shipped with DEPLOY-1.

- [x] **DEPLOY-6 (Medium): Failure after symlink swap leaves DB state contradicting server state.**
  In `DeploymentService.php:65-75`, if the SSH connection drops between `activateRelease` and `markAsSuccess`, the new release is live but the deployment is marked failed and the previous record keeps `is_active = true`. Later rollbacks then operate on wrong assumptions.
  Fix: mark the deployment record active/successful immediately after the symlink swap succeeds, before cleanup runs.
  Fixed (July 2026): active flag recorded right after activation (even on later failure), cleanup demoted to best-effort, `markAsActive` wrapped in a transaction.

- [x] **DEPLOY-7 (Medium): Deployments stuck in `running` forever if the worker dies.**
  `failed()` handlers cover exceptions and timeouts, but an OOM-killed or restarted queue container (`docker compose down` mid-deploy) leaves the deployment `running` and the app `deploying` with no recovery.
  Fix: scheduled reaper that fails deployments older than the job timeout, or use job middleware with heartbeat.
  Fixed (July 2026): `deployments:reap-stale` command scheduled every ten minutes, plus a new `scheduler` docker-compose service running `schedule:work` (nothing executed the scheduler before, which also unblocks SSL-1).

- [x] **DEPLOY-8 (Medium): Git credentials written to predictable world-readable files in /tmp on the target.**
  `GitProviderService.php:159-186` and `DeploymentService.php:226-236` embed raw private keys or passwords in scripts uploaded to `/tmp/git-clone-{appId}-{time}.sh` (default 0644, predictable name). Any local user on the target can read credentials during the deploy window or pre-create the path.
  Fix: create with 0600 via SFTP, use unpredictable names, delete in a finally path, or use `GIT_SSH_COMMAND` with an agent-forwarded or per-deploy key file.
  Fixed (July 2026): shared `RunsRemoteScripts` trait used by both deploy services: random `/tmp/shipyard-script-{32 chars}.sh` name, `touch && chmod 600` before the content is written (SFTP put keeps the inode's mode), and `rm -f` in a finally block so the script is removed even on failure. Regression tests in `DeploymentShellSafetyTest`.

- [x] **DEPLOY-9 (Medium): Unquoted shell interpolation of deploy_path, branch, repository_url, shared_paths, app name.**
  Throughout `DeploymentService.php` (146, 156, 181, 195), `AtomicDeploymentService.php` (28, 99, 132, 139, 241, 280), `RollbackService.php` (103, 123, 181), `GitProviderService.php` (178, 214). Beyond injection (hardening, per threat model), it is a plain correctness bug: a path containing a space makes `rm -rf` delete a wrong prefix path; a `shared_paths` entry of `""` or `..` rm-rf's the release or releases dir.
  Fix: `escapeshellarg()` every interpolated value and validate `deploy_path`/`shared_paths` against a strict pattern.
  Fixed (July 2026): `escapeshellarg()` on every interpolated path/branch/URL/credential across all four services (including the generated clone/pull scripts, whose askpass credentials previously broke on quotes, and the PM2 slug already being safe). `shared_paths`/`writable_paths` entries are validated at the API (relative, no `..`, `[\w./-]+`) and re-checked at deploy time by `AtomicDeploymentService::assertSafeRelativePath()`, which aborts before any `rm -rf`. `node_version` (interpolated into `nvm use`) is now format-validated (`[A-Za-z0-9._-]+`); the PM2 app name already goes through `Str::slug`. Regression tests in `DeploymentShellSafetyTest`.

- [x] **DEPLOY-10 (Medium): .env escaping corrupts values.**
  `DeploymentService.php:360-368` and `AtomicDeploymentService.php:163-171` use `addslashes`: a value like `it's` becomes `it\'s` (dotenv does not unescape `\'`). Values containing `"` but no whitespace are not quoted at all; embedded newlines corrupt the file. If the admin deletes all env vars the method returns early and the stale remote `.env` survives.
  Fix: proper dotenv quoting (double quotes, escape `\`, `"`, `$`, newlines) shared with ENV-1, and write an empty file instead of returning early.
  Fixed (July 2026): both deploy services use the shared `App\Support\EnvFile` serializer (same fix as ENV-1). Note: the empty-env early-return still leaves a stale remote `.env`; tracked as a small follow-up, not yet addressed.

- [x] **DEPLOY-11 (Low): Symlink swap is not actually atomic.**
  `ln -nfs` (`AtomicDeploymentService.php:241`, `RollbackService.php:123`) is unlink-then-symlink, leaving a window where `current` does not exist. Also, if `current` pre-exists as a real directory (app switched from in-place to atomic), `ln -nfs` creates the symlink inside it and exits 0 while old code keeps serving.
  Fix: `ln -s target current.tmp && mv -T current.tmp current`, plus a guard that `current` is not a real directory.
  Fixed (July 2026): `AtomicDeploymentService::atomicSwapCommand()` stages the link and renames it over `current` (rename(2) is atomic) and refuses to run when `current` exists as a real directory. Used by both activation and rollback. Regression tests in `RollbackReliabilityTest`.

- [x] **DEPLOY-12 (Low): SSH connections leak, no pooling, singleton state.**
  `SSHService.php:19-59` constructs a new `SSH2`/`SFTP` per call without disconnecting the previous; one atomic deploy connects 5+ times. `SSHService` is a singleton (`AppServiceProvider.php:16`) so stale `$server` state persists across jobs in the long-lived worker. CLAUDE.md's claim that it handles pooling is false.
  Fix: reuse a connection per (server, job) and disconnect explicitly; correct CLAUDE.md.
  Fixed (July 2026): `connect()`/`connectSftp()` reuse the live session for the same server (and verify liveness via `isConnected()`), close the old session when switching servers, and `disconnect()` clears server state so nothing stale persists across jobs in the long-lived worker. CLAUDE.md corrected. Tests in `SSHServiceConnectionTest` via an injectable fake client.

- [x] **DEPLOY-13 (Low): phpseclib failure returns `false` output, misread as "not initialized".**
  `SSHService.php:90-97`: on failure `exec()` returns `false`, then `str_contains(false, ...)` in `isInitialized`/`releaseExists`/`verifyReleaseExists` treats a transient SSH failure as "structure missing". On timeout `getExitStatus()` returns `false`, rendering "failed with exit code: " (empty) at `DeploymentService.php:245`.
  Fix: check `$result === false` explicitly and surface a distinct error.
  Fixed (July 2026): `execute()` throws a distinct "command could not be executed" error when phpseclib returns `false`, and a missing exit status (timeout) is reported as exit code -1/failure instead of success. Tests in `SSHServiceConnectionTest`.

- [x] **DEPLOY-14 (Low): Script timeout (600s) is far below the job timeout (1800s).**
  `DeploymentService.php:236` hardcodes 600s while `ProcessDeployment.php:18` allows 1800s. A cold `composer install` plus `npm run build` on a small server exceeds 10 minutes and fails spuriously.
  Fix: make the exec timeout configurable per app or align it with the job timeout.
  Fixed (July 2026): the deploy script now runs with `ProcessDeployment::TIMEOUT_SECONDS - 300` (1500s), keeping it tied to the job timeout in one place. Regression test in `RollbackReliabilityTest`.

- [x] **DEPLOY-15 (Low): Dead config and double logging in the job.**
  `docker-compose.yml:69` passes `--tries=3` but `ProcessDeployment` sets `$tries = 1` (dead flag). `ProcessDeployment::failed()` duplicates the ERROR log line and `markAsFailed` already done by the service catch block.
  Fix: remove the flag or the duplicate handling.
  Fixed (July 2026): `--tries=3` removed from the queue worker command (jobs define their own tries), and both jobs' `failed()` hooks now only act when the service catch block did not already record the failure (worker-death safety net, no duplicate ERROR lines). Regression tests in `RollbackReliabilityTest`.

---

## 3. Rollback

- [x] **RB-1 (High): "Rollback to previous" can activate the wrong release.**
  `RollbackController.php:116-121` and `RollbackService.php:80-85` pick "most recent successful deployment that is not the current record" without comparing against what the `current` symlink points to. Scenario A: after rolling back R5 to R4, pressing rollback-to-previous again re-activates broken R5 (you can never go back more than one hop). Scenario B: deploy D6 fails before activation (D5 still live); rollback-to-previous picks D4, one release too far.
  Fix: resolve the live release via the symlink (`getCurrentReleasePath`, currently dead code) and pick the newest successful release older than it.
  Fixed (July 2026): `rollbackToPrevious` resolves the current release via `getCurrentReleasePath` and selects the newest successful release with a lower id than the release's original deploy, so repeated rollbacks walk backwards instead of ping-ponging.

- [x] **RB-2 (Medium): Rollback to failed deployments is allowed.**
  `RollbackController.php:54-75` checks ownership, `release_path`, and `is_active` but never `status === 'success'` (unlike `Application::rollbackableDeployments()`). A deployment that cloned but failed its build passes the directory existence check and its broken release goes live.
  Fix: require success status in the controller validation.
  Fixed (July 2026): `RollbackController::rollback` returns 422 when the target's status is not `success`.

- [ ] **RB-3 (Medium): In-place apps have no rollback story at all.**
  `RollbackService.php:21-23` throws for non-atomic apps. No fallback (e.g. `git reset --hard <previous commit>`).
  Fix: either implement a git-based rollback for in-place, or hide/disable rollback affordances for in-place apps and document it.

- [x] **RB-4 (Low): Post-rollback artisan commands are fire-and-forget.**
  `RollbackService.php:164-169` never checks results of `optimize:clear` / `optimize` / `queue:restart`; a failing `optimize` (bad cached config) leaves the app 500ing while rollback reports success.
  Fix: check exit codes and mark the rollback failed (or at least warn) when they fail.
  Fixed (July 2026): post-rollback tasks throw on failure; the rollback is marked active right after the symlink swap (mirroring DEPLOY-6) so a late task failure marks the rollback failed while `is_active` still reflects what the server serves. Regression test in `RollbackReliabilityTest`.

- [x] **RB-5 (Low): `markAsActive` is two non-transactional updates.**
  `Deployment.php` (`markAsActive`): clear-all-then-set without a transaction. A concurrent deploy plus rollback can leave zero or two active deployments.
  Fix: wrap in a transaction (relates to DEPLOY-3 locking).
  Fixed (July 2026): already resolved by the DEPLOY-6 work (`markAsActive` runs in a `DB::transaction`); verified and closed.

---

## 4. Nginx and SSL

- [x] **NGINX-1 (Critical): A failed `nginx -t` leaves the broken config enabled, poisoning the whole server.**
  `NginxService::deploy()` (`backend/app/Services/NginxService.php:35-44`) uploads and symlinks into `sites-enabled` before testing, and has no rollback on failure (unlike `updateConfigContent()` at 113-119). One bad config makes every later `nginx -t` fail (all apps, cert issuance) and an nginx restart takes every site down.
  Fix: test the candidate config before enabling (write to a temp path, `nginx -t` with it staged, or snapshot and restore on failure like `updateConfigContent` does).
  Fixed (July 2026): both `deploy()` and `updateConfigContent()` snapshot the existing config and restore it on a failed `nginx -t` (or remove the file when there was no predecessor). `updateConfigContent` previously "restored" a freshly generated config, which is not guaranteed valid either.

- [x] **NGINX-2 (High): Legacy SSL path generates invalid config (`server_name ;`, no 443 block).**
  `NginxService.php:253-271` (also 413-430, 547-564): an app with `ssl_enabled = true` but zero Domain rows produces an empty `$sslDomainNames` in the redirect block and no 443 server block. Reachable via deprecated `CertbotService::obtainCertificate()` (`CertbotService.php:204`). Combined with NGINX-1, this leaves a broken config enabled.
  Fix: remove the legacy app-level SSL path or make it synthesize a Domain row; guard template generation against the empty-domain case.
  Fixed (July 2026): templates emit SSL blocks only when SSL-enabled Domain rows exist (the legacy app-level flag alone now yields a valid plain HTTP config), and the deprecated `obtainCertificate()` synthesizes the missing primary Domain row so the issued certificate actually gets served. Regression tests in `NginxTemplateTest`/`CertbotWebrootTest`.

- [x] **NGINX-3 (High): Node.js apps hardcoded to `proxy_pass http://localhost:3000`.**
  `NginxService.php:400,445,469`. `Application` has no port field. Two Node apps on one server both route to whatever holds port 3000.
  Fix: add a `port` column to applications, template it into the proxy blocks and into the PM2 start command.
  Fixed (July 2026): nullable `applications.port` (default 3000 via `Application::getPort()`), templated into all Node.js proxy blocks; `buildPm2RestartCommand()` exports `PORT` and restarts with `--update-env`. API accepts `port` on create/update. UI field not wired yet (see GAP-UI-4 territory). Regression tests in `NginxTemplateTest`/`DeployScriptTest`.

- [x] **NGINX-4 (High): Config file named after the mutable primary domain, never cleaned up on change.**
  `NginxService.php:29-30,56-58` names the file `sites-enabled/{primaryDomain}`; `DomainService::setPrimary()` (`DomainService.php:87-103`) never redeploys or removes the old file. Changing the primary domain leaves the old config enabled (duplicate/conflicting server blocks, stale cert paths), and `remove()` only deletes the file matching the current name, so deleted apps can keep serving.
  Fix: name configs after an immutable key (app id or slug), and add cleanup on primary-domain change and app deletion.
  Fixed (July 2026): configs are now named `shipyard-app-{id}`. `deploy()` and `remove()` clean up files deployed under the old domain-based naming (after a successful `nginx -t` only, so failed deploys keep the serving files), `deploy()` accepts extra legacy names for the just-renamed-domain case (used by app update), and `DomainService::setPrimary()` now syncs nginx. Regression tests in `NginxConfigNamingTest`; `NginxConfigRollbackTest` updated to the new naming.

- [x] **NGINX-5 (High): PHP-FPM socket hardcoded to `php8.3-fpm.sock`.**
  `NginxService.php:242,302,342` pin php8.3 while the installer (`DatabaseInstallationService.php:238-245`) installs whatever the `php` metapackage resolves to (ondrej PPA resolves newer). Mismatch means every Laravel request 502s.
  Fix: detect the installed FPM version per server (or add per-app php_version) and template the socket path.
  Fixed (July 2026): socket templated from `Application::getPhpVersion()` (per-app `php_version`, falling back to `servers.php_version`, then 8.3). The server version is recorded by the PHP installer and by `checkSoftware`. Per-site version *switching* (multiple FPM pools) remains FEAT-5. Regression tests in `NginxTemplateTest`.

- [x] **SSL-1 (Critical): Certificate renewal is never wired up; nginx never reloads renewed certs.**
  `CertbotService::renewCertificates()` (`CertbotService.php:222-235`) has zero callers. `routes/console.php` schedules only `queue:prune-failed`. Issuance (`CertbotService.php:34-39`) uses `certonly --webroot` with no `--deploy-hook`, so even certbot's own systemd timer renewing files never reloads nginx. Sites serve expired certs ~90 days after issuance. README claims auto-renewal.
  Fix: schedule `renewCertificates()` (with `--deploy-hook 'systemctl reload nginx'`), update `ssl_expires_at` after renewal, and correct the README until done.
  Fixed (July 2026): issuance and renewal register a nginx reload deploy hook; daily `certificates:renew` command (03:30) runs one `certbot renew` per server and refreshes stored expiry via `checkDomainStatus`. Runs on the scheduler service added in DEPLOY-7. The README auto-renewal claim is now true.

- [x] **SSL-2 (High): Cert issuance for Node.js apps always fails (ACME webroot mismatch).**
  `CertbotService.php:33-39` uses `$app->getDocumentRoot()` as webroot, but the non-SSL Node template proxies everything (including `/.well-known/acme-challenge/`) to the Node process (`NginxService.php:462-480`), and SSL variants serve challenges from `/var/www/html` (a third location). Let's Encrypt gets a 404 every time.
  Fix: add a `location /.well-known/acme-challenge/` block to all templates pointing at one canonical webroot, and use that same path in the certbot command.
  Fixed (July 2026): every template (all types, SSL and non-SSL) serves `location ^~ /.well-known/acme-challenge/` from `NginxService::ACME_WEBROOT` (`/var/www/letsencrypt`); issuance mkdirs and uses the same path, and `certbot renew` passes `--webroot -w` so certificates issued under the old per-app webroots keep renewing. Regression tests in `NginxTemplateTest`/`CertbotWebrootTest`.

- [x] **SSL-3 (Medium): DB updated before nginx redeploy on cert issuance.**
  `CertbotService.php:49-61` persists `ssl_enabled`/`ssl_expires_at`, then calls `nginxService->deploy()`; if that throws (NGINX-1) the domain shows SSL active in the UI while HTTPS is dead.
  Fix: only persist after the nginx deploy succeeds, or mark a distinct "issued but not serving" state.
  Fixed (July 2026): the flag must be set before the deploy (templates emit 443 blocks from SSL-enabled Domain rows), so instead both issuance paths revert `ssl_enabled` and rethrow when the deploy fails. The certificate stays on disk; re-running issuance after fixing nginx re-activates it. Regression test in `CertbotWebrootTest`.

- [x] **NGINX-6 (Medium): sudo usage inconsistent across services; only one server configuration can work.**
  `DatabaseInstallationService` prefixes everything with `sudo`; `NginxService` (writes `/etc/nginx`, `systemctl reload`) and `CertbotService` never do. Non-root SSH user: installs work, every nginx/cert operation fails. Root user: the sudo prefixes are pointless.
  Fix: pick one convention (probably `sudo -n` everywhere with a documented sudoers requirement) and apply it consistently.
  Fixed (July 2026): new `App\Support\RemoteSudo::wrap()` prefixes privileged commands with `sudo -n` for non-root, non-local SSH users (root runs them bare, since minimal systems may lack sudo). Applied to all privileged `NginxService`/`CertbotService` commands; config writes are staged in /tmp and moved into place as root because SFTP cannot write /etc/nginx as a non-root user. Passwordless sudo requirement documented in the README. `DatabaseInstallationService` keeps its unconditional `sudo` (works for both cases); normalizing it onto `RemoteSudo` is cosmetic follow-up. Regression tests in `NginxPrivilegeTest`.

- [x] **NGINX-7 (Low): `remove()` reloads nginx without testing and callers ignore the result.**
  `NginxService.php:60-69`.
  Fix: `nginx -t` before reload; surface failures.
  Fixed (July 2026): `remove()` runs `nginx -t` after deleting the config and throws instead of reloading when it fails (a reload with another site's broken config would take everything down). App deletion still proceeds when nginx cleanup throws (the catch in `ApplicationController::destroy` remains; logging it properly is API-11). Regression test in `NginxConfigNamingTest`.

---

## 5. Databases (MySQL / PostgreSQL / installer)

- [x] **DB-1 (Medium): Passwords containing a single quote produce malformed shell commands.**
  `MySQLService.php:254,261` and `PostgreSQLService.php:338-339` wrap passwords in single quotes escaped with `addcslashes(..., "'")` producing `\'`, which does not work inside bash single quotes (correct is `'\''`). Manually registered connections with `'` in the password fail on every operation with confusing shell errors.
  Fix: use `escapeshellarg()` or the `'\''` idiom (see also the correct-but-wrong-layer escaping in DB-6).
  Fixed (July 2026): both drivers pass the password via `escapeshellarg()` (MySQL through `MYSQL_PWD`, which also silences the password-on-command-line stderr warning). Regression tests in `DatabaseDriverSafetyTest`.

- [x] **DB-2 (Medium): MySQL driver discards all error output.**
  `MySQLService.php:261` appends `2>/dev/null`, so every failure surfaces as "Failed to create database: " with an empty reason, and error-content checks (like PostgreSQL's "already exists" tolerance) are impossible.
  Fix: capture stderr, redact the password before logging.
  Fixed (July 2026): stderr is captured (`2>&1`), possible since DB-1 moved the password to `MYSQL_PWD` (no more password warning noise); the password never appears in output. Regression tests in `DatabaseDriverSafetyTest`.

- [x] **DB-3 (Medium): PostgreSQL "ALL" grant omits sequences and default privileges.**
  `PostgreSQLService.php:147-161` grants database, schema, and existing tables only. Laravel apps then fail with "permission denied for sequence xxx_id_seq" on first INSERT; tables created later are inaccessible.
  Fix: add `GRANT ... ON ALL SEQUENCES IN SCHEMA` and `ALTER DEFAULT PRIVILEGES`.
  Fixed (July 2026): ALL grant/revoke now include `ON ALL SEQUENCES IN SCHEMA public` and `ALTER DEFAULT PRIVILEGES ... ON TABLES/SEQUENCES`. Regression tests in `DatabaseDriverSafetyTest`.

- [x] **DB-4 (Medium): Grant/revoke loops have no rollback; partial state diverges from the record.**
  `PostgreSQLService.php:190-199,246-253`: if command 2 of 3 fails, command 1 is applied but the exception aborts and `DatabaseUser.privileges` is not updated.
  Fix: apply, verify, then persist what actually succeeded (or re-read effective grants after failure).
  Fixed (July 2026): on a grant/revoke failure the controller re-reads effective privileges from the server (`getUserPrivileges`) and persists them (best-effort), so the stored record reflects reality after a partial application. Regression test in `DatabaseDriverSafetyTest`.

- [x] **DB-5 (Medium): Privilege names, charset, and collation interpolated raw into SQL.**
  `MySQLService.php:280-287,150-158,44-49`, `PostgreSQLService.php:163-187`; validated only as `'privileges.*' => 'string'` (`DatabaseUserController.php:174`). Arbitrary SQL as the admin user (hardening per threat model), and plain breakage on typos.
  Fix: validate against an allowlist of known privilege names / charsets.
  Fixed (July 2026): privileges validated against an allowlist of known MySQL/PostgreSQL privilege keywords (case-insensitive), charset/collation against `[A-Za-z0-9_.-]+` in all three DatabaseController validation sites. Regression tests in `DatabaseDriverSafetyTest`.

- [x] **DB-6 (Low): Installer password escaping is wrong-layer (latent).**
  `DatabaseInstallationService.php:92-95,125-126`: shell single-quote escaping embedded inside a double-quoted shell string within SQL single quotes. Only safe because `Str::random(32)` is alphanumeric; any change to password generation silently breaks or injects.
  Fix: base64-pipe the SQL or use here-docs to keep layers separate.
  Fixed (July 2026): both installers pipe base64-encoded SQL into the client, with the password SQL-string-escaped only. Regression tests in `DatabaseDriverSafetyTest`.

- [x] **DB-7 (Low): `mysql_native_password` fails on MySQL 8.4+.**
  `DatabaseInstallationService.php:95`. Plugin deprecated/removed in newer MySQL; breaks once Ubuntu ships 8.4.
  Fix: use `caching_sha2_password` (default) and verify client compatibility.
  Fixed (July 2026): the installer uses `caching_sha2_password` (supported by the mysql CLI the panel uses and by PHP mysqlnd). Regression test in `DatabaseDriverSafetyTest`.

- [x] **DB-8 (Low): MySQL user listing breaks on usernames with spaces.**
  `MySQLService.php:92-96`: whitespace split truncates legal usernames; header heuristic can skip real rows containing "User" and "Host".
  Fix: query with a delimiter-safe format (`--batch` with tab parsing or JSON).
  Fixed (July 2026): rows are split on the tab delimiter mysql's batch output actually uses (the `-N` flag already suppresses the header, so the fragile header heuristic is gone too). Regression test in `DatabaseDriverSafetyTest`.

- [x] **DB-9 (Low): Destructive drop is the unvalidated one.**
  `DatabaseController.php:283-285` (`dropRemoteDatabase`) accepts any string as DB name while `createRemoteDatabase:251` enforces an identifier regex.
  Fix: apply the same regex to drop.
  Fixed (July 2026): drop enforces the same identifier regex as create. Regression test in `DatabaseDriverSafetyTest`.

- [x] **DB-10 (Low): Dead statement in PostgreSQL dropDatabase.**
  `PostgreSQLService.php:73`: `buildCommand(...)` result discarded, command built twice. Copy/paste slip.
  Fix: remove the dead line.
  Fixed (July 2026): dead line removed.

- [ ] **DB-11 (Low): PM2 modeled as a database engine.**
  The `database_installations.engine` enum is `('mysql','postgresql','pm2')` and `DatabaseInstallationService` actually installs PHP, Node, nginx, certbot, and PM2 too. Misleading naming, confuses ownership of provisioning logic, and caused TEST-1.
  Fix: rename the concept to "software installations" (table, service, job) or split provisioning from databases.

---

## 6. Environment variables

- [x] **ENV-1 (Medium): Env sync corrupts values containing quotes or dollar signs.**
  `EnvSyncService.php:74-81`: `addslashes()` writes `\'` (invalid dotenv escape), and `$` is not in the quoting regex at all, so `pa$sword` is written unquoted and phpdotenv performs variable interpolation, silently mangling secrets. Value works in the panel, deployed app gets a different one.
  Fix: single shared dotenv serializer with correct double-quote escaping of `\`, `"`, `$`, and newlines (same fix as DEPLOY-10).
  Fixed (July 2026): `App\Support\EnvFile` quotes/escapes only what phpdotenv v5 unescapes (verified against the vendored parser), escaping `$` so it is never interpolated. Used by the sync service, both deploy services, and the env-file editor. Unit tests round-trip quotes, `$`, backslashes, and empties.

- [x] **ENV-2 (Medium): `updateEnvFile` deletes all vars then recreates, without a transaction.**
  `EnvironmentVariableController.php:151-160`: an encryption/DB error mid-loop permanently destroys the app's secrets. Also performs synchronous SSH inside the request.
  Fix: wrap in a DB transaction; move the SSH sync out of the critical section (or make its failure non-destructive, see FE-9).
  Fixed (July 2026): the delete+recreate now runs inside `DB::transaction`. The synchronous SSH sync remains after the transaction (its failure is already reported non-destructively via `sync_result`); moving it fully out of the request is a separate improvement.

- [ ] **ENV-3 (Low): Inconsistent key validation between endpoints.**
  Single-var endpoints require `^[A-Z][A-Z0-9_]*$` (`EnvironmentVariableController.php:24`) while `updateEnvFile:136-137` accepts `_FOO`/lowercase (uppercased), creating keys the item-level API can't recreate.
  Fix: one shared validation rule.

---

## 7. Logs, metrics, system

- [ ] **LOG-1 (Medium): Remote command injection via log filename route parameter.**
  `LogService.php:71-104` (reached from `GET /api/applications/{id}/logs/{filename}`, `routes/api.php:122`): sanitization only rejects `/`, `\`, `.`, `..`; the name is interpolated unescaped into `test -f`, `stat`, `wc`, `tail` over SSH. `x;reboot` executes on the managed server. Admin-only (hardening per threat model), but also breaks legitimate filenames with spaces (rotated logs).
  Fix: `escapeshellarg()` plus a strict filename allowlist regex.

- [ ] **LOG-2 (Low): grep no-match indistinguishable from failure; search covers only the tail window.**
  `LogService.php:96-109`: grep exits 1 on no match so `success=false` returns empty content with no error flag; search runs on `tail -n {lines}` output only, silently missing older matches.
  Fix: distinguish exit code 1 from >1; search the whole file (bounded) or state the window in the UI.

- [ ] **MET-1 (Medium): CPU metric shows average since boot, not current usage.**
  `ServerMetricsService.php:66-77`: single read of `/proc/stat` yields a lifetime average (correct measurement needs two samples with a delta). A server pegged at 100% for an hour can show 3%. The `top` fallback is locale-fragile (label and decimal separator).
  Fix: two `/proc/stat` samples with a short sleep, computed server-side in one SSH call; force `LC_ALL=C` on fallbacks.

- [ ] **MET-2 (Low): Uptime formatting broken by strict float/int comparisons.**
  `ServerMetricsService.php:150-174`: `floor()` returns float so `$days === 1` is never true ("1 days"), and sub-hour uptimes render "Less than a minute" because the minutes branch is unreachable.
  Fix: cast to int before comparing.

- [ ] **SYS-1 (Medium): Self-update output capture likely dead after the HTTP response returns.**
  `SystemController.php:84-92`: `Process::start()` with an output callback, then the request returns; under PHP-FPM the callback stops being pumped, so `update-status` (95-121) never observes "Update complete!" and the `system_update_running` flag only clears via the 10-minute TTL. Also a check-then-set race on the cache flag (46-54) and failure detection by grepping for the literal `Error:`.
  Fix: run the update via a queued job writing progress to cache/DB, or have `update.sh` itself write a status file the endpoint reads.

- [ ] **SYS-2 (Low): Docker detection gives false negatives.**
  `SystemController.php:22` reads `/proc/1/cgroup` without existence check (warning on macOS) and cgroup-v2 hosts don't contain "docker"; `?? ''` is ineffective because `file_get_contents` returns `false`.
  Fix: also check `/.dockerenv`; handle `false` return.

---

## 8. API, auth, webhooks

- [x] **API-1 (Critical): No rate limiting anywhere, including `/auth/login`.**
  Laravel 11 only applies `throttle:api` when a limiter is configured; no `RateLimiter::for()` exists in `app/` or `bootstrap/` (`bootstrap/app.php:14-21`). The panel stores SSH private keys, git tokens, and DB admin passwords; the login endpoint can be brute-forced at line speed.
  Fix: configure the `api` limiter plus a strict per-IP limiter on login (and consider lockout).
  Fixed (July 2026): `api` limiter at 120/min per user or IP applied via `throttleApi()`, login capped at 5/min per IP.

- [x] **API-2 (Critical): Seeder falls back to a known default admin password.**
  `DatabaseSeeder.php:14-16`: `env('ADMIN_PASSWORD', 'password')` creates `admin@example.com` / `password` on any setup that seeds without the env vars (install.sh prompts correctly, manual setups don't). Also `env()` in a seeder returns null under `config:cache`, silently forcing the defaults.
  Fix: refuse to seed without explicit credentials (throw), read via `config()`.
  Fixed (July 2026): seeder throws with instructions when `ADMIN_PASSWORD` is unset (install.sh already prompts and writes it, so the normal flow is unaffected).

- [x] **API-3 (High): Webhooks only work for GitLab; GitHub/Bitbucket always rejected.**
  `WebhookController.php:22` delegates only to `GitLabService` (`GitLabService.php:11-24`): validates the `X-Gitlab-Token` header and requires `object_kind === 'push'`. GitHub (`X-Hub-Signature-256` HMAC) and Bitbucket pushes get 401/422 even though both providers are fully supported for repo browsing and cloning. README documents `/api/webhook/github/...` routes that do not exist.
  Fix: per-provider webhook parsers (GitHub HMAC verification, Bitbucket), dispatch on the app's provider type; fix the README.
  Fixed (July 2026): `App\Services\Webhooks\WebhookHandlerFactory` resolves a per-provider handler (GitLab token, GitHub HMAC, Bitbucket URL token/signature); the controller dispatches on the app's provider. GitLabService was replaced by `GitLabWebhookHandler`. README webhook routes/instructions corrected (single `/api/webhook/{app-id}` endpoint).

- [x] **API-4 (High): Duplicate app names silently share a deploy path.**
  No unique constraint on `applications.name` or `deploy_path` (migration `2024_01_01_000004`), no duplicate check in `ApplicationController::store:30-56`; `deploy_path` auto-generates from the slugged name, so "My App" and "my-app" collide. Deployments overwrite each other and `DELETE /applications/{id}?delete_files=1` rm-rf's the surviving app's files. The deploy-path allowlist also accepts `/home/` exactly (`ApplicationController.php:166-189`), so `deploy_path=/home/` plus delete wipes all home directories.
  Fix: unique constraint on (server_id, deploy_path), duplicate check with clear error, minimum path depth validation.
  Fixed (July 2026): deploy paths are validated (absolute, ≥3 levels deep, no `..`), collision-checked per server in store/update, and backed by a unique index on (server_id, deploy_path). `deleteServerFiles` uses the same safety check plus `escapeshellarg`, so bare prefixes like `/home/` can no longer be wiped.

- [ ] **API-5 (Medium): SSE streams busy-poll the DB and pin PHP-FPM workers; Redis pipeline half-built.**
  `DeploymentStreamController.php:77-127` and `DatabaseInstallationStreamController.php:74-117` poll `find()` every 500ms up to 5 minutes per open stream. A few tabs exhaust the FPM pool and the whole panel hangs. Meanwhile `Deployment::publishLogChunk()` publishes to Redis channels nothing subscribes to.
  Fix: subscribe to the already-published Redis channels in the stream loop (or at least lengthen the poll interval and cap concurrent streams).

- [ ] **API-6 (Medium): Sanctum token in SSE query strings, and tokens never expire.**
  `routes/api.php:29-31`, both stream controllers; `config/sanctum.php` has `'expiration' => null` and `AuthController::login:30` mints a new eternal token per login with no pruning. A token in an nginx access log is a permanent admin credential.
  Fix: set token expiration, prune old tokens on login, and pass the SSE token via a short-lived signed URL or cookie instead of the query string.

- [ ] **API-7 (Medium): Commit message column overflows break webhook deploys.**
  `deployments.commit_message` is VARCHAR(255) (`2024_01_01_000006_create_deployments_table.php:16`); a push whose head commit message exceeds 255 chars throws a QueryException in `WebhookController.php:47-55` and the deploy never runs. Also unvalidated in `ApplicationController::deploy:209`.
  Partially mitigated (July 2026): the webhook path now truncates `commit_message` to 255 chars before insert, so pushes no longer 500. Widening the column to TEXT is still worthwhile.
  Fix: truncate before insert (or make the column TEXT).

- [x] **API-8 (Medium): App `domain` update is a no-op once a Domain row exists.**
  `ApplicationController.php:120-132` writes the legacy `applications.domain` column, but `NginxService` resolves from the `domains` table first; the Domain row created in `store:59-64` is never updated. `PUT /applications/{id}` with a new domain returns 200 and changes nothing.
  Fix: finish the migration to the `domains` table (update the Domain row, stop dual-writing, eventually drop the column).
  Fixed (July 2026): the update now updates (or creates) the primary Domain row and removes the old nginx config by its previous domain name before redeploying. Full removal of the legacy `applications.domain` column is left for a dedicated cleanup.

- [ ] **API-9 (Medium): Deployment responses ship full logs (LONGTEXT), multi-MB payloads.**
  `DeploymentController.php:12-19` paginates 20 with full `log`; `ApplicationController::show:85-87` embeds 10 latest with logs. `Deployment` has no `$hidden`.
  Fix: exclude `log` from lists (select columns or API resource), fetch logs only in the detail/stream endpoints.

- [ ] **API-10 (Low): Webhook secret compared with `===` instead of `hash_equals()`.**
  `GitLabService.php:15`. Timing side channel (low practical risk).
  Fix: `hash_equals()`.

- [ ] **API-11 (Low): Silent empty catch blocks around nginx deploy failures.**
  `ApplicationController.php:69-71,129-131,145-147,152-155`: comments say "Log error" but nothing logs; the API reports success while nginx deploy failed.
  Fix: actually log, and surface a warning field in the response.

- [ ] **API-12 (Low): Changing `server_id` on an app leaves the old server dirty.**
  `ApplicationController::update:101` allows moving an app between servers but never removes nginx config/files from the old one.
  Fix: disallow server changes, or implement cleanup.

- [ ] **API-13 (Low): `is_local` cannot be changed after creation.**
  `ServerController::update:65-72` omits it; converting a local server to remote is impossible without recreating.
  Fix: allow the field or document why not.

- [ ] **API-14 (Low): Token embedded in persistent git remote URLs.**
  `GitProvider::getAuthenticatedUrl` embeds the decrypted token in the clone URL; if used as a persistent remote it lands in `.git/config` on every target server.
  Fix: use askpass/credential-helper for pulls too, never persist the token in the remote.

- [ ] **API-15 (Low): CORS origins hardcoded to localhost variants.**
  `config/cors.php` has no env override; breaks any split-origin deployment.
  Fix: read origins from env.

---

## 9. Frontend

- [x] **FE-1 (High): Fake hardcoded SSH public key displayed with a working Copy button.**
  `frontend/src/pages/servers/ServerSettings.tsx:387,394` renders `ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample... server-manager@{host}`. Users paste it into GitHub/GitLab and clones never authenticate, with no signal why.
  Fix: fetch and display the server's real public key (backend endpoint exists via SSH key services) or remove the section until wired.
  Fixed (July 2026): the SSH section with the fake key was removed (no backend endpoint actually exposes a per-server public key; SSHKeyService only generates keypairs). A real key display/rotation UI belongs to GAP-UI-3.

- [x] **FE-2 (High): Deploy button double-fires.**
  `frontend/src/pages/apps/AppOverview.tsx:165-182`: the Button is both a `DropdownMenuTrigger` child (asChild merges handlers) and has `onClick={handleDeploy}`. One click starts a deployment AND opens the menu; clicking the menu item queues a second concurrent deployment (compounds DEPLOY-3).
  Fix: split into a split-button (primary action + separate chevron trigger).
  Fixed (July 2026): split button in AppOverview; the primary button only deploys, the chevron only opens the menu.

- [x] **FE-3 (High): Navigation to routes that do not exist.**
  `GitProviderNew.tsx:187` navigates to `/git-providers/${id}` (real route is `/settings/git-providers/:id`, `App.tsx:72`); `GitProviderNew.tsx:438` and `GitProviderDetail.tsx:128` go to `/git-providers`; `AppNew.tsx:61,89` and `ServerNew.tsx:268` go to `/servers`; `DeploymentDetail.tsx:143` breadcrumb links `/apps`. All render a blank page (no catch-all route, `App.tsx:55-92`).
  Fix: correct the paths, add a catch-all 404 route, and delete the dead legacy pages (FE-15) these paths came from.
  Fixed (July 2026): paths corrected (`/settings/git-providers/...`, `/`, app overview), a catch-all 404 page added inside the layout, and the seven dead legacy pages deleted (also closes FE-15).

- [x] **FE-4 (High): Failed login reloads the page, wiping the error.**
  `frontend/src/services/api.ts:31-41`: the 401 interceptor unconditionally sets `window.location.href = '/app/login'`, including for the login request itself. Wrong credentials cause a hard reload before the "Invalid credentials" toast renders.
  Fix: skip the redirect for the login endpoint (and when already on the login page).
  Fixed (July 2026): the 401 interceptor ignores the login request itself and skips the redirect when already on the login page, so "Invalid credentials" actually renders.

- [ ] **FE-5 (Medium): Stale sidebar/navigation after creating resources.**
  `NavigationContext.tsx:85-97` sets `currentServer` to null when the URL id is missing from the cached list instead of fetching it; `refreshServers`/`refreshApps` are never called by `ServerNew.tsx`/`AppNew.tsx`. New servers/apps don't appear in the sidebar until a hard refresh.
  Fix: fetch-on-miss in the context and call the refreshers after creation.

- [ ] **FE-6 (Medium): Env editor ignores sync failures and fetch failures.**
  `AppEnv.tsx:44-56` discards the `sync_result` from `PUT /env-file` (the same handling exists correctly in `AppSettings.tsx:185-192`), so "saved" can mean "saved to DB, not on the server". `AppEnv.tsx:23-42`: fetch failure shows a silent empty textarea; the fallback promise has no catch.
  Fix: surface `sync_result` warnings; add error states.

- [ ] **FE-7 (Medium): Polling without catch floods unhandled rejections and lies on failure.**
  `DeploymentDetail.tsx:16-25,99-105` (2s poll, try/finally without catch, initial failure renders "Deployment not found"); `AppOverview.tsx:74-80`, `AppDeployments.tsx:48-54` (5s poll, `.then()` without catch); `OrganizationOverview.tsx:45-49` (failure shows "No servers configured yet").
  Fix: add catch handlers with error states distinct from empty states.

- [ ] **FE-8 (Medium): Installation SSE has no reconnect and no polling fallback.**
  `ServerSoftware.tsx:230-237`: `es.onerror` marks the install "failed" and stops, while the backend `GET /database-installations/{id}` status endpoint (already in `api.ts:384`) goes unused. A network blip during a 5-minute MySQL install shows "Failed" while it actually completes.
  Fix: on SSE error, fall back to polling the status endpoint.

- [ ] **FE-9 (Medium): "Test Connection" creates a real provider and can orphan it.**
  `GitProviderNew.tsx:144-162` creates, tests, then deletes in finally; a failed delete leaves an orphan "Test Connection" provider in the list.
  Fix: backend test endpoint that doesn't persist (or test-before-create server-side).

- [x] **FE-10 (Medium): Dev proxy misses `/sanctum/csrf-cookie`.**
  `frontend/vite.config.ts:17-24` proxies only `/api`; `getCsrfCookie()` (`api.ts:29`) 404s under `npm run dev`, so login always throws in dev.
  Fix: proxy `/sanctum` too.
  Fixed (July 2026): `/sanctum` proxied alongside `/api`, both honoring a `BACKEND_URL` env override (edit made directly in the working tree, shipped with the FE-1..4 commit).

- [ ] **FE-11 (Medium): Enter key on wizard step 1 submits the whole app-creation form.**
  `AppNew.tsx:249`: one `<form>` wraps all steps; the required `repository_url` input isn't mounted on step 1 so constraint validation can't block, and a 422 fires out of context.
  Fix: submit only on the final step (guard by current step, or use type="button" for step navigation).

- [ ] **FE-12 (Medium): "Check & Update" triggers a full self-update instead of checking.**
  `Settings.tsx:362-377` calls `startUpdate()` directly, even when `update_available` is false or version info hasn't loaded.
  Fix: separate check and update actions with a confirm on update.

- [ ] **FE-13 (Low): Expired certs render "Expires in -12 days".**
  `AppDomains.tsx:138-145`.
  Fix: handle past dates ("Expired 12 days ago").

- [ ] **FE-14 (Low): SSE misc.** Token not `encodeURIComponent`ed in `ServerSoftware.tsx:186` (Sanctum tokens contain `|`; `DeploymentDetail.tsx:44` encodes it); `DeploymentDetail.tsx:84-89` permanently disables SSE after one error and re-creates the EventSource on every status transition.
  Fix: encode consistently; reset the SSE flag; narrow effect deps.

- [x] **FE-15 (Low): ~1,300 lines of dead legacy pages.**
  Not imported or routed: `pages/Dashboard.tsx`, `apps/AppList.tsx`, `apps/AppDetail.tsx`, `servers/ServerList.tsx`, `servers/ServerDetail.tsx`, `servers/ServerApps.tsx`, `git-providers/GitProviderList.tsx`. Source of the broken paths in FE-3.
  Fix: delete them.
  Fixed (July 2026): deleted together with FE-3.

- [ ] **FE-16 (Low): Dead footer links.** `Layout.tsx:294-296` ("Status", "Docs", "Help" are `href="#"`).
  Fix: wire or remove.

---

## 10. Built in the backend, missing in the UI

- [x] **GAP-UI-1 (High): No rollback UI at all.** `GET /applications/{id}/releases`, `POST .../rollback`, `POST .../rollback/previous` exist in the backend but are not even present in `api.ts`. Atomic deployments advertise instant rollback with no way to trigger it.
  Fixed (July 2026): added the three API client methods and a Releases section on the deployments page (atomic apps) with per-release roll-back actions and a rollback-to-previous button, each behind a confirm dialog.
- [ ] **GAP-UI-2 (Medium): No revoke-privileges UI.** Client exists (`api.ts:417`) but `DatabaseDetail.tsx` only grants; a granted privilege can never be removed short of deleting the user.
- [ ] **GAP-UI-3 (Medium): No SSH key rotation for servers.** `PUT /servers/{id}` accepts `private_key` (`ServerController.php:70-76`) but ServerSettings has no field, so rotating a key requires recreating the server (compounds FE-1).
- [ ] **GAP-UI-4 (Low): Unused endpoints/client code.** App-level SSL (`setupSsl`), live SSL status (`getSslStatus`), default Node version, deploy-path generation (`AppNew` reimplements slugging client-side, divergence risk), installation history/status endpoints, per-variable env CRUD (`envApi.*` dead).
  Fix: wire them or remove the dead client code and endpoints.

---

## 11. Missing features (roadmap, ordered by impact for a Laravel-focused platform)

- [ ] **FEAT-1 (Critical gap): Queue worker / daemon management.** Nothing installs or supervises workers (no supervisor/systemd unit management anywhere). The default Laravel deploy script runs `php artisan queue:restart` (`Application.php:~134,247`) against workers that were never started. The single biggest functional hole versus Forge/Ploi/RunCloud.
- [ ] **FEAT-2 (Critical gap): Cron / scheduler management.** Zero crontab code in the repo; deployed Laravel apps never get `schedule:run`, so their schedulers silently don't run.
- [ ] **FEAT-3 (High): Database backups.** The UI already promises it (`ServerStorage.tsx` Backups tab says "Coming soon..."); zero backend code (dump, restore, schedule, offsite).
- [ ] **FEAT-4 (High): Notifications and alerting.** No email/Slack/Discord/outbound-webhook on deploy success/failure or cert expiry; `Mail::`/`Notification::` never used in `backend/app`. `Domain::isExpiringSoon()` exists but nothing consumes it.
- [ ] **FEAT-5 (High): Per-site PHP version selection.** See NGINX-5; also no version switching or multiple FPM pools.
- [ ] **FEAT-6 (Medium): Redis/memcached installation on target servers.** Not in the installer engine list (`DatabaseInstallationService::install`) or software detection (`ServerController::checkSoftware`), despite the default Laravel script assuming queues.
- [ ] **FEAT-7 (Medium): Post-deploy health check gate.** The atomic symlink swap happens purely on script exit code; no smoke test before/after activation and no auto-rollback on failed activation.
- [ ] **FEAT-8 (Medium): Deployment cancel.** A stuck 30-minute deployment (`ProcessDeployment::$timeout = 1800`) cannot be aborted via API or UI.
- [ ] **FEAT-9 (Medium): Admin password change / management.** No endpoint to change the admin password or manage the account; only tinker/reseed.
- [ ] **FEAT-10 (Medium): Commit pinning / redeploy specific commit.** `commit_hash` is recorded and shown but deploys always take branch HEAD; the recorded hash may not match deployed code and there is no redeploy-at-commit.
- [ ] **FEAT-11 (Medium): Firewall (UFW) management.** No firewall/fail2ban code anywhere.
- [ ] **FEAT-12 (Medium): Site isolation.** Every app on a server runs as the same SSH user (`servers.username`); one compromised app reads every other app's `.env`. Forge-style per-site Linux users.
- [ ] **FEAT-13 (Medium): Server log viewing.** nginx access/error logs, PHP-FPM, system, and PM2 logs are all unavailable (LOG-1 scope is app `*.log` files only); no live tail.
- [ ] **FEAT-14 (Low): One-shot server provisioning.** Installer is à la carte (Ubuntu/Debian only); no fresh-server flow (UFW, swap, redis, supervisor, deploy user, unattended-upgrades).
- [ ] **FEAT-15 (Low): PM2 completeness.** No `pm2 save` after start (processes lost on reboot despite startup config), no process status/logs UI, no ecosystem file, no cluster mode.
- [ ] **FEAT-16 (Low): Wildcard / DNS-01 certificates and custom certs.** Webroot HTTP-01 only; `python3-certbot-nginx` is installed but never used.
- [ ] **FEAT-17 (Low): Health checks / uptime monitoring.** No endpoint monitors, no historical metrics storage or background polling (MET-1 covers correctness of the live metric).
- [ ] **FEAT-18 (Low): Webhook event coverage.** Only `push` handled; no tag or merge/PR-triggered deploys, no Gitea provider, no path-filtered deploys.
- [ ] **FEAT-19 (Low): ed25519 SSH keys.** `SSHKeyService.php:11` generates RSA only; passphrase-protected git keys explicitly unsupported (`GitProviderService.php:42-44`).
- [ ] **FEAT-20 (Low): Deployment queueing UX.** Correctness silently depends on the single-worker docker-compose topology (DEPLOY-3); after locking, queued deploys should be visible in the UI.

---

## 12. Documentation debt

- [ ] **DOC-1: README overclaims.** Documents GitHub webhook routes that don't exist (API-3), claims SSL auto-renewal (SSL-1), and implies queue worker management via "queue restart" (FEAT-1). Reconcile in whichever direction each item is resolved.
  Partially done (July 2026): webhook routes/instructions corrected (API-3) and SSL auto-renewal is now real (SSL-1). Remaining: the "queue restart" wording still implies worker management that does not exist (FEAT-1).
- [x] **DOC-2: CLAUDE.md inaccuracies.** Claims `SSHService` handles connection pooling (it doesn't, DEPLOY-12) and documents `npm run lint` (broken, TEST-2).
  Done (July 2026): `npm run lint` works since TEST-2, and the SSH note now matches reality since DEPLOY-12 (sessions are reused per server, disconnect explicitly).

---

## Suggested order of attack

1. TEST-1 (unblocks regression testing), then TEST-2.
2. Small fixes with the biggest blast radius: DEPLOY-1, DEPLOY-2, NGINX-1, SSL-1, API-1, API-2.
3. DEPLOY-3 + FE-2 together (deployment concurrency), then RB-1/RB-2.
4. API-3 (GitHub/Bitbucket webhooks) and GAP-UI-1 (rollback UI), both advertised capabilities.
5. NGINX-3/NGINX-5 (Node port, PHP version) and SSL-2.
6. Roadmap: FEAT-1, FEAT-2, FEAT-3, FEAT-4.
