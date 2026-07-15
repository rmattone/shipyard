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
- **Framework**: Laravel 11 (PHP 8.2) with Sanctum for API authentication
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

## Key Patterns

### SSH Operations
All server interactions go through `SSHService`. Never execute SSH commands directly. The service handles connections, key decryption, and error handling (note: it does not pool connections; each connect() opens a fresh session).

### Environment Variables
Secrets are encrypted at rest using Laravel's encryption. Access via the `EnvironmentVariable` model, never store plaintext.

### Nginx Configuration
Generated dynamically by `NginxService`. Templates are built per-application and pushed to target servers.

### Real-time Logs
Deployment logs stream via SSE (Server-Sent Events) to the frontend.

## Configuration

Two `.env` files:
- Root `.env`: Docker configuration (ports, database credentials)
- `backend/.env`: Laravel configuration (app URL, encryption key, admin credentials)

Both have `.example` templates. The `install.sh` script handles initial setup.
