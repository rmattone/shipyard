# Fix Plan: DEPLOY-3 through DEPLOY-7 (deployment pipeline robustness)

## Context

With the first five audit items shipped (test suite on MySQL, release cleanup, PM2 restart, test foundation, ESLint), the next batch from TODO.md hardens the deployment pipeline itself: concurrency control (DEPLOY-3), release ID collisions (DEPLOY-4), failed-release cleanup (DEPLOY-5), DB state contradicting server state after activation (DEPLOY-6), and deployments stuck in `running` forever (DEPLOY-7). These five interact, so they are planned as one coherent effort.

Two discoveries from planning that shape the work:

1. `backend/phpunit.xml:23` sets `CACHE_DRIVER=array`, but Laravel 11 reads `CACHE_STORE` (`config/cache.php:6`, default `redis`). Tests currently talk to the Redis cache store without anyone noticing. This must be fixed to `CACHE_STORE=array` before DEPLOY-3, or lock middleware tests will require Redis.
2. Nothing runs the Laravel scheduler anywhere. `routes/console.php` schedules `queue:prune-failed`, but docker-compose has no scheduler service and the Dockerfile CMD is `php-fpm`. DEPLOY-7 (and later SSL renewal, SSL-1) depend on a running scheduler, so this plan adds one. Related dead config worth deleting: `docker/supervisor/laravel-worker.conf` is loaded by nothing.

Work order: DEPLOY-4 → DEPLOY-5 + DEPLOY-6 (single change) → DEPLOY-3 → DEPLOY-7. Each lands with regression tests using the established patterns (`AtomicDeploymentTest` mocked SSHService, `WebhookTest` Queue::fake, existing factories; suite runs on MySQL).

---

## 1. DEPLOY-4: Release ID collisions at 1-second resolution

**Problem.** `Deployment::generateReleaseId()` (`backend/app/Models/Deployment.php:140-143`) returns a bare `YmdHis` timestamp. A webhook push and a manual deploy in the same second produce identical `release_id`/`release_path`; the second clone fails or clobbers the first.

**Fix.** Append a random suffix inside `generateReleaseId()` itself: `now()->format('YmdHis') . '-' . Str::lower(Str::random(6))`. Both call sites (`ApplicationController.php:201-202`, `WebhookController.php:42-43`) and the test helper build `release_path` from the returned string, so nothing else changes.

Verified safe: release ordering everywhere uses lexicographic `sort -r` on a fixed 14-digit timestamp prefix (`AtomicDeploymentService.php:264`, `RollbackService.php:204`), so the suffix only affects ordering within the same second (inherently ambiguous anyway), and mixed old/new IDs still order correctly. No code parses release IDs as timestamps (verified: no `createFromFormat`/`Carbon::parse` on release fields; frontend never touches `release_id`). Optional hardening: prefix the two `sort -r` commands with `LC_ALL=C` so locale collation cannot reorder around the hyphen.

**Tests.** Unit test asserting format `/^\d{14}-[a-z0-9]{6}$/` and that consecutive calls differ. Update `AtomicDeploymentTest` cleanup fixtures to suffixed IDs to lock in ordering.

---

## 2. DEPLOY-5 + DEPLOY-6: Failure handling around activation (one change)

Both items restructure `DeploymentService::runAtomicDeployment` (`backend/app/Services/DeploymentService.php:34-91`) around a shared `$activated` flag, so they ship together.

**Problems.**
- DEPLOY-5: a failed deploy leaves its partial release directory on the server forever (git clone creates the dir itself, `AtomicDeploymentService.php:74-109`; nothing removes it in the catch path).
- DEPLOY-6: if anything fails after `activateRelease` (line 65), the new release IS live but the deployment is marked failed and the previous record keeps `is_active = true`, so later rollbacks reason from wrong state. Also `Deployment::markAsActive()` (`Deployment.php:164-173`) is two non-transactional updates.

**Fix.** Restructure the happy path in this order:
1. `activateRelease` → set `$activated = true`.
2. `restartNodeProcess` (PM2).
3. `markAsActive()` + `$app->update(['status' => 'active'])`, moved up from the end.
4. `cleanupOldReleases` wrapped in try/catch that logs `WARNING: cleanup failed` and swallows (housekeeping must not fail a live deployment).
5. `markAsSuccess()` last, then disconnect. Keeping it after cleanup preserves the SSE contract (`markAsSuccess` publishes `is_complete: true`, `Deployment.php:108-116`; firing earlier would stream cleanup logs after the frontend considers the deploy finished).

In the catch block:
- If `$activated`: call `$deployment->markAsActive()` before `markAsFailed()`. The symlink did swap, so the active record must track the server even though the deployment failed (e.g. PM2 restart failure). App status stays `failed` as the operator signal.
- If not `$activated`: best-effort removal of the partial release using `$deployment->release_path` (NOT the local `$releasePath` variable, which is unassigned if `createRelease` threw mid-clone; both controllers persist `release_path` at creation):

```php
if (! $activated && $deployment->release_path
    && str_starts_with($deployment->release_path, $app->getReleasesPath().'/')) {
    try {
        $this->sshService->execute("rm -rf {$deployment->release_path}");
        $deployment->appendLog("Cleaned up failed release: {$deployment->release_id}");
    } catch (\Exception $cleanupError) {
        $deployment->appendLog("WARNING: could not clean up failed release: {$cleanupError->getMessage()}");
    }
}
```

Also wrap the two updates inside `Deployment::markAsActive()` in `DB::transaction()`.

**Tests** (AtomicDeploymentTest pattern): failing deploy script (`fakeResults['bash /tmp/deploy-'] => success false`) → exception, `rm -rf {release_path}` issued, status failed. Success path → new release never rm-rf'd. PM2 failure after activation → `is_active === true` AND status `failed` AND app `failed`, and no `rm -rf` of the release. Cleanup failure → status still `success`.

---

## 3. DEPLOY-3: Concurrency control for deployments and rollbacks

**Problem.** No lock, no in-flight check anywhere (`ApplicationController::deploy:191-222`, `WebhookController::handle`, `RollbackController`, jobs). Today safety comes only from the single queue worker in docker-compose. The frontend Deploy button double-fire (FE-2) makes real double dispatch easy.

**Fix (two layers).**

Layer A, job middleware: add `middleware()` to BOTH `ProcessDeployment` and `ProcessRollback`:

```php
return [
    (new WithoutOverlapping("app-pipeline:{$this->deployment->application_id}"))
        ->shared()
        ->expireAfter(1830)   // ProcessRollback: 330 (its timeout is 300)
        ->releaseAfter(30),
];
```

Critical details, verified in vendor:
- `->shared()` is mandatory. Without it the lock key is prefixed with the job class, so deploy and rollback would NOT serialize against each other (`WithoutOverlapping::getLockKey`).
- `releaseAfter` conflicts with `$tries = 1`: a lock-blocked release counts as an attempt and the job dies with `MaxAttemptsExceededException`, spuriously failing the deployment. Change both jobs to `public int $tries = 60; public int $maxExceptions = 1;` (real exceptions still fail immediately; only lock releases retry). Keep the existing `$timeout` values.

Layer B, controller guards (mirror the existing pattern at `DatabaseController.php:46-56`): reject when the application already has a deployment with status `pending` or `running`.
- `ApplicationController::deploy` (before release-id generation, ~line 197): return 409 JSON.
- `RollbackController::rollback` (before ~line 78) and `rollbackToPrevious` (before ~line 130): return 409.
- `WebhookController::handle` (after the branch check, before ~line 38): return 200 with a "deployment already in progress, skipped" message, not 409, so GitLab does not mark the webhook as failing and retry.

Prerequisite: fix `backend/phpunit.xml:23` from `CACHE_DRIVER` to `CACHE_STORE=array` (ArrayStore implements LockProvider, verified, so middleware works in tests). Production uses `CACHE_STORE=redis` per `.env.example:30`.

**Tests.** Feature test per controller: `Deployment::factory()->running()` for the app, POST deploy/rollback → 409 (webhook → 200 skipped) and `Queue::assertNothingPushed()`. Middleware unit test: assert both jobs return a shared `WithoutOverlapping` with matching lock keys, rather than trying to provoke overlap on the sync driver.

---

## 4. DEPLOY-7: Reaper for stuck deployments + a scheduler that actually runs

**Problem.** If the queue container dies mid-deploy (OOM, `docker compose down`), the deployment stays `running` and the app stays `deploying` forever. And nothing executes the Laravel scheduler at all, so any scheduled fix is dead on arrival.

**Fix.**
1. New command `backend/app/Console/Commands/ReapStaleDeployments.php` (`deployments:reap-stale`), auto-discovered in Laravel 11, scheduled in `routes/console.php` with `->everyTenMinutes()` (keep the existing `Schedule::` facade style):
   - `running` deployments with `started_at < now()->subSeconds(2100)` (covers the 1800s deploy and 300s rollback timeouts plus buffer): `appendLog('Marked as failed: worker died or job timed out')` + `markAsFailed()`. Note `markAsFailed` also publishes SSE completion (`Deployment.php:118-126`), which unsticks any browser still watching the stream.
   - `pending` deployments with `created_at < now()->subHours(2)` (NOT `started_at`, which is null until `markAsRunning`). The 2-hour threshold comfortably exceeds any lock-release bouncing from DEPLOY-3.
   - Application status: only set `failed` if the app is currently `deploying`, so reaping an old stale row cannot clobber an app that has since deployed successfully.
2. New `scheduler` service in `docker-compose.yml`, mirroring the `queue` service but with `command: php artisan schedule:work`. This is also the prerequisite for SSL-1 (certificate renewal) later.
3. Delete the dead `docker/supervisor/laravel-worker.conf` (loaded by nothing; its `--tries=3` would conflict with job settings if ever wired).

**Tests.** `$this->artisan('deployments:reap-stale')` with: a running deployment started an hour ago (reaped), a fresh running one (untouched), a 3-hour-old pending one (reaped; set `created_at` directly on the model and save), and app status assertions for the deploying vs active cases.

---

## Files touched (summary)

| Item | Files |
|---|---|
| DEPLOY-4 | `backend/app/Models/Deployment.php` (generateReleaseId), optional `LC_ALL=C` in `AtomicDeploymentService.php` + `RollbackService.php` sort commands |
| DEPLOY-5/6 | `backend/app/Services/DeploymentService.php` (runAtomicDeployment restructure), `backend/app/Models/Deployment.php` (markAsActive transaction) |
| DEPLOY-3 | `backend/app/Jobs/ProcessDeployment.php`, `ProcessRollback.php`, `ApplicationController.php`, `RollbackController.php`, `WebhookController.php`, `backend/phpunit.xml` (CACHE_STORE) |
| DEPLOY-7 | `backend/app/Console/Commands/ReapStaleDeployments.php` (new), `backend/routes/console.php`, `docker-compose.yml` (scheduler service), delete `docker/supervisor/laravel-worker.conf` |

## Ordering constraints

1. DEPLOY-5 and DEPLOY-6 are one change (same `$activated` flag, same method).
2. The phpunit.xml `CACHE_STORE` fix lands with or before DEPLOY-3.
3. The scheduler service lands with or before the reaper command.
4. DEPLOY-4 is independent; doing it first keeps WebhookController churn together with DEPLOY-3's guard.

## Verification

1. `docker compose exec app php artisan test` green after each item (or the throwaway MySQL container flow if the compose stack is not set up).
2. DEPLOY-3 manual check: dispatch two deploys for the same app quickly; second returns 409, queue processes one.
3. DEPLOY-7 manual check: `docker compose up -d` shows the scheduler container running `schedule:work`; kill the queue container mid-test-deploy, wait for the reaper window, confirm the deployment flips to failed.
4. Mark items done in `TODO.md` as they land.
