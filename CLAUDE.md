# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ShipYard is a self-hosted server management and deployment platform. It enables teams to deploy Laravel, Node.js, and static sites to their own servers via SSH, without requiring Docker on the target servers. The platform runs in Docker locally but deploys to bare metal servers.

## Commands

### Docker Services
```bash
docker compose up -d                    # Start all services
docker compose down                     # Stop services
docker compose logs -f [service]        # View logs (app, nginx, mysql, redis, queue)
```

### Backend (Laravel)
```bash
docker compose exec app php artisan test              # Run all tests (requires the Docker stack; tests use MySQL DB server_management_testing)
docker compose exec app php artisan test --filter=X  # Run specific test
docker compose exec app php artisan migrate           # Run migrations
docker compose exec app php artisan tinker            # Laravel shell
docker compose exec app php artisan optimize:clear    # Clear all caches
docker compose exec app php artisan queue:restart     # Restart queue workers
docker compose exec app composer install              # Install dependencies
docker compose exec app ./vendor/bin/pint             # Format PHP code
```

### Frontend (React/TypeScript)
```bash
# Inside Docker
docker compose exec app bash -c "cd /var/www/frontend && npm run dev"    # Dev server
docker compose exec app bash -c "cd /var/www/frontend && npm run build"  # Production build
docker compose exec app bash -c "cd /var/www/frontend && npm run lint"   # ESLint

# Or locally (if Node.js installed)
cd frontend && npm run dev
cd frontend && npm run build
```

## Architecture

### Backend (`backend/`)
- **Framework**: Laravel 12 (PHP 8.2) with Sanctum for API authentication
- **Services pattern**: Business logic lives in `app/Services/`, controllers are thin
- **Key services**:
  - `SSHService` - All remote server communication via phpseclib
  - `DeploymentService` / `AtomicDeploymentService` - Orchestrate deployments
  - `DatabaseService` with `MySQLService` / `PostgreSQLService` drivers
  - `NginxService` / `CertbotService` - Server configuration
- **Background jobs**: `app/Jobs/` with Redis queue, watched by `queue` container
- **API routes**: `routes/api.php`

### Frontend (`frontend/`)
- **Stack**: React 18, TypeScript, Vite, Tailwind CSS
- **UI Components**: Radix UI primitives in `src/components/ui/`
- **Pages**: `src/pages/` (dashboard, apps, servers, git-providers, settings)
- **API client**: `src/services/api.ts`
- **Auth**: Context-based in `src/contexts/`

### Deployment Strategies
The system supports two deployment modes:
1. **Atomic**: Zero-downtime symlink-based releases in `/releases/` with instant rollback
2. **In-place**: Direct updates without symlink strategy

### Docker Services
| Service | Purpose |
|---------|---------|
| `app` | PHP-FPM (port 9000 internal) |
| `nginx` | Web server (ports 80/443) |
| `mysql` | Database (internal only) |
| `redis` | Cache and queue (internal only) |
| `queue` | Background job worker |
| `scheduler` | Laravel scheduler (`schedule:work`) |

## Key Patterns

### Multi-Tenancy (Organizations)
Users belong to organizations (pivot `organization_user` with role owner/admin/member). Tenant root tables (`servers`, `git_providers`, `notification_channels`) carry `organization_id` and use the `BelongsToOrganization` trait. The `SetOrganizationContext` middleware binds the request's organization into `App\Support\CurrentOrganization`; the global `OrganizationScope` filters queries only while that context is bound, so queue workers and artisan commands run unscoped by design. It must run before route model binding (registered in the middleware priority list in `bootstrap/app.php`). Beware: `exists:` validation rules bypass global scopes, constrain them to the current organization explicitly. In tests, `TestCase::createOrgUser()` creates an acting user and binds the context so factories land in the same organization.

### SSH Operations
All server interactions go through `SSHService`. Never execute SSH commands directly. The service handles connections, key decryption, and error handling. Repeated `connect()` calls to the same server reuse the live session; `disconnect()` closes it. The service is resolved per injection (transient), so instances are not shared between services; always call `disconnect()` on your own instance when an operation finishes.

### Server Soft Delete
`servers` uses `SoftDeletes`. Deleting moves a server to the trash (Settings › Trash) where it stays restorable for `Server::TRASH_RETENTION_DAYS`; `servers:purge-trashed` force-deletes past that. The applications guard in `ServerController::destroy` stays: a server with applications is never trashed, so restore never has to reconcile orphaned apps. Child rows (databases, daemons, scheduled tasks, tags, ssh keys) cascade only on the force delete, which is what makes restore whole. `restore` and `force` bind `->withTrashed()`, bypassing the soft-delete scope but not `OrganizationScope`. Both are gated by `org.role:admin`; note `org.writes` already blocks members from every write, so that gate is defense-in-depth. Destructive server actions require typing the server name in the dialog.

### Deploy User Layout
`servers.deploy_user` (nullable) selects the filesystem layout. Null means the legacy `/var/www/shipyard/{app}` defaults and the distro PHP-FPM socket. When set (provisioned via `ServerUserService`, home mode 711), new apps default to `/home/{deploy_user}/{app}`, `PhpFpmPoolService` installs a pool running as that user, and `NginxService` points vhosts at the ShipYard pool socket. Because the pool and socket decision is server-wide, assigning or changing `deploy_user` is refused while the server has applications outside the new user's home (`ServerUserService::assertNoApplicationsOutsideHome`); migrating existing apps is deliberately unsupported. Never hardcode either base path; use `Server::default_deploy_base` / `Application::generateDeployPath()`.

### Environment Variables
Secrets are encrypted at rest using Laravel's encryption. Access via the `EnvironmentVariable` model, never store plaintext.

### Nginx Configuration
Generated dynamically by `NginxService`. Templates are built per-application and pushed to target servers.

### Real-time Logs
Deployment logs stream via SSE (Server-Sent Events) to the frontend.

### Server Metrics History
`servers:collect-metrics` runs every five minutes and dispatches one `CollectServerMetrics` job per active server (one job each so an unreachable host and its 30s SSH login timeout cannot delay the others; `WithoutOverlapping` drops a sample rather than queueing a backlog). Each job stores a `ServerMetric` row; the command also prunes rows older than `ServerMetric::RETENTION_DAYS`. The live `GET /servers/{server}/metrics` read (polled by the overview every 30s) stores a row too, skipped when one exists within `ServerMetric::LIVE_SAMPLE_GUARD_SECONDS`, so history is denser while a server is being watched; storage failures there are reported, never surfaced to the client. `ServerMetricsService` samples `/proc/stat` twice one second apart for CPU, since a single read is only the since-boot average. `GET /servers/{server}/metrics/history?range=7d|30d` (`ServerMetricsHistoryService`) buckets with `UNIX_TIMESTAMP()`, which is MySQL only, and computes peak/p95/avg in PHP. `ServerMetric` has no organization trait: it is only reachable under `/servers/{server}`, whose binding is already scoped.

### Self-update
Settings > System is backed by `SystemController` + `SystemUpdateService` + the `RunSystemUpdate` job. The checkout is bind-mounted at `/var/www/shipyard` in `app`, `queue`, and `scheduler` (`config('shipyard.install_dir')` overrides the lookup, used by tests). Installed version = `VERSION` file + `git rev-parse HEAD`; "update available" = the commit differs from `origin/<branch>` on GitHub (falls back to comparing `VERSION` numbers when git or the API is unavailable). The update itself must run as root with write access to the checkout, so the controller only dispatches the job; php-fpm runs as `www-data` and cannot do it. `update.sh` detects when it is inside a container and runs commands directly instead of through `docker compose exec`; it refuses branches other than the target and dirty trees, fast-forwards only, and cannot apply `docker-compose.yml`/`docker/` changes (it prints a notice to run `docker compose up -d --build` on the host). State lives in the cache, the log in `storage/app/system-update.log`; `api/system/update-status` is exempt from maintenance mode so the UI can follow the run.

### Web Terminal
`TerminalStreamController` holds an interactive PTY (`TerminalService` + `App\Support\Ssh\InteractiveSSH2`, which adds the `window-change` request phpseclib 3.0 lacks) for the life of one SSE request; keystrokes arrive as POSTs that `LPUSH` onto `terminal:{session}:input` and the stream loop drains with `RPOP`. Never route terminal I/O through `SSHService`: it is exec-only and reuses/destroys sessions in ways a long-lived shell cannot tolerate. Each stream pins one php-fpm worker, hence the 3-session cap, the stale-session reaper (`last_seen_at` touched every 15s), the enlarged pool in `docker/php/zz-shipyard.conf`, and the dedicated unbuffered nginx location. Like the other SSE routes it sits outside `auth:sanctum`, so ownership, organization membership, and the admin role are all checked by hand in the controller.

## Configuration

Two `.env` files:
- Root `.env`: Docker configuration (ports, database credentials)
- `backend/.env`: Laravel configuration (app URL, encryption key, admin credentials)

Both have `.example` templates. The `install.sh` script handles initial setup.
