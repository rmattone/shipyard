# Automatic HTTPS for the ShipYard Platform (Let's Encrypt via Certbot)

Date: 2026-08-02
Status: Approved

## Problem

ShipYard's own web stack serves plain HTTP only. The compose file publishes port 443, but nginx has no 443 server block, so HTTPS requests to the origin time out. Users who put a domain in front of their instance (with or without Cloudflare) have no supported way to get a certificate, and the installer bakes the server IP into `APP_URL`. Enabling HTTPS today means hand editing `docker/nginx/default.conf`, `docker-compose.yml`, and `backend/.env` on the server.

## Goal

A user with a domain pointing at their server gets working HTTPS with automatic renewal, either during a fresh install or on an existing installation, by answering one prompt or running one script. The dev workflow (`docker compose up` on a fresh clone) is unchanged, and `git pull` on a server never conflicts with HTTPS state.

## Decisions

These were settled during design review:

1. Entry points: both. `install.sh` gains an optional domain prompt, and a standalone `scripts/enable-https.sh` serves existing installations. The installer calls the same script internally.
2. Renewal: a certbot container added via compose override. No packages installed on the host.
3. Port 80 behavior after HTTPS is enabled: serve only the ACME challenge path and 301 redirect everything else to `https://{domain}`.
4. Config mechanism: rendered from tracked templates into gitignored generated files. No tracked file is ever modified by the script.

## Design

### 1. Nginx config refactor (shared include)

The location blocks currently in `docker/nginx/default.conf` (API, Sanctum, PHP handling, SPA, root redirect, static files, hidden file deny, health check) move to a tracked snippet. Note: `feature/database-restore-upload` (merged to main as PR #2) added terminal SSE and restore upload location blocks to `default.conf`; those blocks now live in the snippet, resolved that way when main was merged into this branch.

* `docker/nginx/shipyard-app.conf`, mounted at `/etc/nginx/snippets/shipyard-app.conf` (new mount in the base `docker-compose.yml`).

The tracked `docker/nginx/default.conf` becomes a thin HTTP server block that includes the snippet. Behavior today stays identical. The HTTPS template reuses the same snippet, so app routing has a single source of truth.

### 2. `scripts/enable-https.sh`

Idempotent and re-runnable. Accepts the domain as `$1` or prompts for it (reading from `/dev/tty`, same pattern as `install.sh`). Obtains the Let's Encrypt account email from `backend/.env` (`ADMIN_EMAIL`) with a prompt fallback.

Steps:

1. Preflight checks, each with a clear failure message:
   * `HTTP_PORT` in the root `.env` must be 80 (the HTTP-01 challenge requires port 80).
   * The Docker stack must be running.
   * The domain must resolve in DNS. A mismatch with the server IP produces a warning, not a failure, because Cloudflare proxied domains resolve to Cloudflare IPs by design.
2. Phase 1 (obtain certificate):
   * Render a temporary port 80 config that adds the ACME webroot location (`/.well-known/acme-challenge/` served from a shared volume) alongside the normal app config.
   * Reload nginx.
   * Run one-shot certbot (`docker compose run --rm --entrypoint certbot certbot certonly --webroot`) with the shared webroot. Certificates land in `./docker/certbot/conf` (gitignored), webroot in `./docker/certbot/www` (gitignored).
3. Phase 2 (switch to HTTPS):
   * Render the final config from the tracked template `docker/nginx/templates/https.conf.template`, substituting the domain. Port 80 serves only the ACME path and redirects everything else. Port 443 terminates TLS with the new certificate and includes the shared app snippet.
   * Write the rendered file to `docker/nginx/conf.d-generated/default.conf` (gitignored).
   * Write `docker-compose.override.yml` (gitignored) that: mounts the generated config over `/etc/nginx/conf.d/default.conf` (same container target as the base mount, so the override replaces it), mounts the certbot directories into nginx, adds the certbot renewal service, and wraps the nginx command with a periodic reload loop.
   * `docker compose up -d` to apply.
4. Update Laravel config in `backend/.env`:
   * `APP_URL=https://{domain}`
   * Append the domain to `SANCTUM_STATEFUL_DOMAINS` if not already present.
   * Run `php artisan optimize:clear`, `php artisan config:cache`, `php artisan route:cache`, then restart app, queue, and scheduler containers.
5. Verify by curling `https://{domain}/health` and print a summary. The summary includes a Cloudflare note: users behind the Cloudflare proxy must switch the SSL/TLS mode to Full (strict), because the new origin redirect makes Flexible mode loop.

If certbot fails (DNS not propagated, firewall blocking, rate limits), the script prints certbot's error and exits nonzero. On a first run it removes the generated state, returning the stack to the tracked plain HTTP config. On a re-run it instead restores the previously working generated config, so an existing HTTPS setup survives a failed attempt for a new domain.

### 3. Renewal

The compose override adds:

* A `certbot` service running an entrypoint loop: `certbot renew` against the shared webroot, sleep 12 hours, repeat.
* A wrapped nginx command that reloads config every 6 hours, so renewed certificates are picked up without manual action.

Let's Encrypt certificates last 90 days and renew when 30 days remain, so a 12 hour renew cadence plus a 6 hour reload cadence gives wide margin.

### 4. `install.sh` integration

After the existing install steps succeed, one new prompt: "Domain pointing at this server (leave empty to skip)". If a domain is entered, the installer runs `scripts/enable-https.sh {domain}`. On success the final summary prints `https://{domain}/app`. On failure the installer warns, tells the user to re-run `scripts/enable-https.sh` once DNS is ready, and completes normally on HTTP. A failed certificate attempt never fails the install.

### 5. Rollback

Deleting `docker-compose.override.yml` and `docker/nginx/conf.d-generated/`, then `docker compose up -d`, returns the stack to the stock HTTP-only state. Documented in the script's failure output and in the README section this feature adds.

## New and changed files

| Path | State | Purpose |
|------|-------|---------|
| `docker/nginx/shipyard-app.conf` | new, tracked | shared location blocks |
| `docker/nginx/default.conf` | changed, tracked | thin HTTP server block including the snippet |
| `docker/nginx/templates/https.conf.template` | new, tracked | HTTPS config with `{{DOMAIN}}` placeholder |
| `docker/nginx/templates/http-acme.conf.template` | new, tracked | phase 1 config (HTTP plus ACME webroot) |
| `scripts/enable-https.sh` | new, tracked | the enable script |
| `docker-compose.yml` | changed, tracked | snippet mount added to nginx |
| `install.sh` | changed, tracked | optional domain prompt |
| `.gitignore` | changed, tracked | ignore override file, generated config, certbot dirs |
| `docker-compose.override.yml` | generated | HTTPS mounts, certbot service, nginx reload loop |
| `docker/nginx/conf.d-generated/default.conf` | generated | rendered HTTPS config |
| `docker/certbot/` | generated | certificates and ACME webroot |

## Out of scope

Multiple domains, www aliases, non-80 HTTP ports, DNS-01 challenges, wildcard certificates, and Cloudflare origin certificates. The design leaves room for these later (the template mechanism can grow variants) but none are built now.

## Verification

1. Fresh VPS: run the full installer with a real subdomain pointed at the box, confirm `https://{domain}/app` works, log in, confirm HTTP redirects, confirm the certbot container is healthy.
2. Existing install: on the current Hostinger instance, `git pull` and run `scripts/enable-https.sh shipyard.rmattone.dev`, confirm the same, then switch Cloudflare to Full (strict) and confirm no redirect loop.
3. Failure path: run the script with a domain that does not resolve, confirm the stack stays on HTTP and the error message is actionable.
4. Renewal dry run: `docker compose exec certbot certbot renew --dry-run` succeeds.
