# Automatic HTTPS (Let's Encrypt via Certbot) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A ShipYard instance with a domain gets working HTTPS with automatic renewal, from one installer prompt or one script run, per the approved spec at `docs/superpowers/specs/2026-08-02-installer-https-design.md`.

**Architecture:** Nginx app routing moves into a shared snippet included by both the tracked HTTP config and an HTTPS config rendered from a template by `scripts/enable-https.sh`. The script obtains the certificate with a one-shot certbot container (webroot challenge), then applies a generated `docker-compose.override.yml` that mounts the rendered config and adds a certbot renewal service. No tracked file is modified at runtime; all HTTPS state is gitignored.

**Tech Stack:** bash, nginx, Docker Compose, certbot/certbot image, Laravel artisan.

**Testing note:** This repo has no shell test framework and the feature is infrastructure glue, so TDD does not apply. Each task instead has explicit verification commands (`bash -n`, `docker compose config -q`, `nginx -t`, HTTP probes against the local stack) that MUST pass before committing. The local Docker stack is expected to be running (it is on the primary dev machine). Full end-to-end verification happens on a real VPS after implementation and is listed at the end.

---

### Task 1: Extract nginx app routing into a shared snippet

The location blocks must be identical in HTTP and HTTPS modes, so they move to one include file. Behavior after this task is identical to before it.

**Files:**
- Create: `docker/nginx/shipyard-app.conf`
- Modify: `docker/nginx/default.conf` (replace entire file)
- Modify: `docker-compose.yml` (nginx volumes, around line 28)

- [ ] **Step 1: Create `docker/nginx/shipyard-app.conf`**

The content is the body of the current `docker/nginx/default.conf` server block with `listen` and `server_name` removed and comments preserved. Merge note: the unmerged branch `feature/database-restore-upload` adds two more location blocks (terminal SSE stream, restore uploads) to `default.conf`; when that branch merges, its blocks belong in this snippet, and the conflict on `default.conf` should be resolved that way.

```nginx
# Shared ShipYard app routing. Included at server level by the tracked
# HTTP config (docker/nginx/default.conf) and by the HTTPS config that
# scripts/enable-https.sh generates. Keep every location block here so
# both modes stay identical.

root /var/www/html/public;
index index.php index.html;

client_max_body_size 100M;

# API routes
location /api {
    try_files $uri $uri/ /index.php?$query_string;
}

# Sanctum routes
location /sanctum {
    try_files $uri $uri/ /index.php?$query_string;
}

# PHP handling
location ~ \.php$ {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_read_timeout 300;
}

# Frontend SPA - serve from /app subfolder
location /app {
    alias /var/www/html/public/app;
    try_files $uri $uri/ /app/index.html;
}

# Root redirect to app
location = / {
    return 301 /app;
}

# Static files
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
}

# Deny hidden files
location ~ /\. {
    deny all;
}

# Health check
location /health {
    access_log off;
    return 200 "OK";
    add_header Content-Type text/plain;
}
```

- [ ] **Step 2: Replace `docker/nginx/default.conf` with the thin HTTP server block**

```nginx
# App routing lives in /etc/nginx/snippets/shipyard-app.conf so the same
# rules serve both this plain HTTP config and the HTTPS config generated
# by scripts/enable-https.sh.
server {
    listen 80;
    server_name _;  # Accept any hostname

    include /etc/nginx/snippets/shipyard-app.conf;
}
```

- [ ] **Step 3: Mount the snippet in `docker-compose.yml`**

In the `nginx` service `volumes` list, after the `default.conf` line, add:

```yaml
      - ./docker/nginx/shipyard-app.conf:/etc/nginx/snippets/shipyard-app.conf
```

- [ ] **Step 4: Verify compose file and nginx config**

Run:
```bash
docker compose config -q && echo COMPOSE_OK
docker compose up -d --force-recreate nginx
docker compose exec -T nginx nginx -t
docker compose exec -T nginx wget -qO- http://localhost/health
```
Expected: `COMPOSE_OK`, `syntax is ok` plus `test is successful`, and `OK` from the health check. If wget prints nothing, the refactor broke routing; diff the snippet against the original default.conf before proceeding.

- [ ] **Step 5: Commit**

```bash
git add docker/nginx/shipyard-app.conf docker/nginx/default.conf docker-compose.yml
git commit -m "Extract nginx app routing into a shared snippet"
```

---

### Task 2: Add the HTTPS config templates and gitignore entries

**Files:**
- Create: `docker/nginx/templates/http-acme.conf.template`
- Create: `docker/nginx/templates/https.conf.template`
- Modify: `.gitignore` (append at end)

- [ ] **Step 1: Create `docker/nginx/templates/http-acme.conf.template`**

Used only between "nginx is HTTP-only" and "certificate issued". The `^~` modifier is required: without it the `location ~ /\.` deny rule in the snippet would swallow ACME challenge requests.

```nginx
# Rendered by scripts/enable-https.sh into docker/nginx/conf.d-generated/.
# Phase 1 config: serve the app over HTTP and answer ACME challenges from
# the shared certbot webroot while the first certificate is issued.
server {
    listen 80;
    server_name _;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    include /etc/nginx/snippets/shipyard-app.conf;
}
```

- [ ] **Step 2: Create `docker/nginx/templates/https.conf.template`**

`{{DOMAIN}}` is the only placeholder and is substituted with sed, which leaves nginx's own `$` variables untouched (the reason envsubst is not used).

```nginx
# Rendered by scripts/enable-https.sh into docker/nginx/conf.d-generated/.
# {{DOMAIN}} is replaced with the real domain at render time.

# Port 80 answers ACME renewals and redirects everything else to HTTPS.
server {
    listen 80;
    server_name _;

    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://{{DOMAIN}}$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name {{DOMAIN}};

    ssl_certificate /etc/letsencrypt/live/{{DOMAIN}}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{{DOMAIN}}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Renewal challenges can arrive over HTTPS when an edge proxy (for
    # example Cloudflare with "Always Use HTTPS") redirects the plain-HTTP
    # challenge. Serve them here too so the snippet's hidden-file deny rule
    # does not 403 them.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    include /etc/nginx/snippets/shipyard-app.conf;
}
```

- [ ] **Step 3: Append to `.gitignore`**

```gitignore

# HTTPS state generated by scripts/enable-https.sh
docker-compose.override.yml
docker/nginx/conf.d-generated/
docker/certbot/
```

- [ ] **Step 4: Verify the template renders**

Run:
```bash
sed "s/{{DOMAIN}}/example.com/g" docker/nginx/templates/https.conf.template | grep -c "example.com"
sed "s/{{DOMAIN}}/example.com/g" docker/nginx/templates/https.conf.template | grep '$request_uri'
```
Expected: `5` (five substitutions: redirect, server_name, two cert paths, and the redirect target counts once; accept any count of 4 or more), and the second command must print the redirect line proving nginx variables survived sed.

- [ ] **Step 5: Commit**

```bash
git add docker/nginx/templates .gitignore
git commit -m "Add nginx HTTPS templates and ignore generated HTTPS state"
```

---

### Task 3: Create `scripts/enable-https.sh`

**Files:**
- Create: `scripts/enable-https.sh` (mode 755)

- [ ] **Step 1: Create the script with this exact content**

```bash
#!/bin/bash

set -e

# =============================================================================
# ShipYard HTTPS Setup
# =============================================================================
# Obtains a Let's Encrypt certificate for this ShipYard instance and switches
# nginx to HTTPS with automatic renewal (certbot container, webroot challenge).
#
# Usage:
#   bash scripts/enable-https.sh yourdomain.com
#
# Requirements:
#   - The domain's DNS already points at this server (directly, or through a
#     proxy such as Cloudflare).
#   - HTTP_PORT is 80 (the Let's Encrypt HTTP challenge cannot use other ports).
#
# Rollback: delete docker-compose.override.yml and docker/nginx/conf.d-generated/
# then run "docker compose up -d" to return to plain HTTP.
# =============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

info() { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
error() { echo -e "${RED}[ERROR]${NC} $1" >&2; exit 1; }

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR"

if docker compose version >/dev/null 2>&1; then
    DOCKER_COMPOSE="docker compose"
else
    DOCKER_COMPOSE="docker-compose"
fi

GENERATED_DIR="docker/nginx/conf.d-generated"
CERTBOT_DIR="docker/certbot"
OVERRIDE_FILE="docker-compose.override.yml"
TEMPLATE_DIR="docker/nginx/templates"

# --- Domain ---
DOMAIN="${1:-}"
if [ -z "$DOMAIN" ]; then
    printf "Domain pointing at this server: " >&2
    read DOMAIN </dev/tty
fi
if ! [[ "$DOMAIN" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]]; then
    error "Invalid domain: $DOMAIN"
fi

# --- Email for the Let's Encrypt account ---
EMAIL=""
if [ -f backend/.env ]; then
    EMAIL=$(grep -E '^ADMIN_EMAIL=' backend/.env | head -1 | cut -d= -f2- | tr -d '"')
fi
if [ -z "$EMAIL" ]; then
    printf "Email for Let's Encrypt expiry notices: " >&2
    read EMAIL </dev/tty
fi
[ -z "$EMAIL" ] && error "An email address is required by Let's Encrypt."

# --- Preflight ---
[ -f "$TEMPLATE_DIR/https.conf.template" ] || error "Run this script from a ShipYard checkout (templates not found)."

HTTP_PORT=$(grep -E '^HTTP_PORT=' .env 2>/dev/null | cut -d= -f2)
HTTP_PORT="${HTTP_PORT:-80}"
if [ "$HTTP_PORT" != "80" ]; then
    error "HTTP_PORT is ${HTTP_PORT}. The Let's Encrypt HTTP challenge requires port 80."
fi

HTTPS_PORT=$(grep -E '^HTTPS_PORT=' .env 2>/dev/null | cut -d= -f2)
HTTPS_PORT="${HTTPS_PORT:-443}"
if [ "$HTTPS_PORT" != "443" ]; then
    error "HTTPS_PORT is ${HTTPS_PORT}. HTTPS setup requires the default port 443 (the redirect targets it)."
fi

if ! $DOCKER_COMPOSE ps nginx 2>/dev/null | grep -qE "Up|running"; then
    error "The nginx container is not running. Start the stack first: $DOCKER_COMPOSE up -d"
fi

if command -v getent >/dev/null 2>&1; then
    if ! getent hosts "$DOMAIN" >/dev/null 2>&1; then
        error "$DOMAIN does not resolve in DNS yet. Point it at this server, wait for propagation, and try again."
    fi
    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    RESOLVED_IP=$(getent hosts "$DOMAIN" | awk '{print $1}' | head -1)
    if [ -n "$SERVER_IP" ] && [ "$RESOLVED_IP" != "$SERVER_IP" ]; then
        warning "$DOMAIN resolves to $RESOLVED_IP, not this server's $SERVER_IP."
        warning "That is expected behind a proxy such as Cloudflare. Continuing."
    fi
fi

# Remember whether HTTPS state already existed so a failed FIRST run can roll
# back cleanly without destroying a previously working setup on a re-run.
FRESH_SETUP=true
[ -f "$OVERRIDE_FILE" ] && FRESH_SETUP=false

rollback() {
    if [ "$FRESH_SETUP" = true ]; then
        warning "Rolling back to plain HTTP..."
        rm -f "$OVERRIDE_FILE"
        rm -rf "$GENERATED_DIR"
        $DOCKER_COMPOSE up -d nginx >/dev/null 2>&1 || true
    fi
}

mkdir -p "$GENERATED_DIR" "$CERTBOT_DIR/conf" "$CERTBOT_DIR/www"

# Never overwrite an override this script did not create; the file is the
# standard Compose customization point and its content is unrecoverable.
OVERRIDE_MARKER="# Generated by scripts/enable-https.sh. Not tracked by git."
if [ -f "$OVERRIDE_FILE" ] && [ "$(head -n1 "$OVERRIDE_FILE")" != "$OVERRIDE_MARKER" ]; then
    error "$OVERRIDE_FILE already exists and was not created by this script.\nMove it away or merge the HTTPS settings into it manually, then re-run."
fi

# --- Compose override: generated config, certbot mounts, renewal service ---
# Compose merges volume lists by container target, so only the new and
# replaced mounts appear here. The nginx command wrapper reloads config every
# 6h so renewed certificates are picked up automatically.
info "Writing ${OVERRIDE_FILE}..."
cat > "$OVERRIDE_FILE" << 'EOF'
# Generated by scripts/enable-https.sh. Not tracked by git.
# Delete this file plus docker/nginx/conf.d-generated/ and run
# "docker compose up -d" to return to plain HTTP.
services:
  nginx:
    command: /bin/sh -c 'while :; do sleep 6h; nginx -s reload; done & exec nginx -g "daemon off;"'
    volumes:
      - ./docker/nginx/conf.d-generated/default.conf:/etc/nginx/conf.d/default.conf
      - ./docker/certbot/conf:/etc/letsencrypt
      - ./docker/certbot/www:/var/www/certbot
  certbot:
    image: certbot/certbot
    container_name: server-mgmt-certbot
    restart: unless-stopped
    entrypoint: /bin/sh -c 'trap exit TERM; while :; do certbot renew --webroot -w /var/www/certbot --quiet; sleep 12h & wait $${!}; done'
    volumes:
      - ./docker/certbot/conf:/etc/letsencrypt
      - ./docker/certbot/www:/var/www/certbot
    networks:
      - server-mgmt
EOF

# --- Phase 1: obtain the certificate over HTTP ---
if [ -f "$CERTBOT_DIR/conf/live/$DOMAIN/fullchain.pem" ]; then
    info "Certificate for $DOMAIN already exists, skipping issuance."
else
    info "Configuring nginx for the ACME challenge..."
    cp "$TEMPLATE_DIR/http-acme.conf.template" "$GENERATED_DIR/default.conf"
    $DOCKER_COMPOSE up -d nginx

    info "Requesting a certificate for $DOMAIN from Let's Encrypt..."
    if ! $DOCKER_COMPOSE run --rm --entrypoint certbot certbot certonly \
        --webroot -w /var/www/certbot \
        -d "$DOMAIN" \
        --email "$EMAIL" \
        --agree-tos --no-eff-email --non-interactive; then
        rollback
        error "Certificate request failed. Common causes: DNS not propagated yet, a firewall blocking port 80, or Let's Encrypt rate limits.\nFix the cause, then run: bash scripts/enable-https.sh $DOMAIN"
    fi
fi

# --- Phase 2: switch nginx to HTTPS ---
info "Switching nginx to HTTPS..."
sed "s/{{DOMAIN}}/$DOMAIN/g" "$TEMPLATE_DIR/https.conf.template" > "$GENERATED_DIR/default.conf"
$DOCKER_COMPOSE up -d nginx certbot

if ! $DOCKER_COMPOSE exec -T nginx nginx -t; then
    rollback
    error "The generated nginx config failed validation."
fi
$DOCKER_COMPOSE exec -T nginx nginx -s reload

# --- Point Laravel at the domain ---
info "Updating backend/.env..."
sed -i "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" backend/.env
if ! grep -E '^SANCTUM_STATEFUL_DOMAINS=' backend/.env | grep -q "$DOMAIN"; then
    sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|&,$DOMAIN|" backend/.env
fi

info "Refreshing Laravel caches..."
$DOCKER_COMPOSE exec -T -w /var/www/html app php artisan optimize:clear >/dev/null
$DOCKER_COMPOSE exec -T -w /var/www/html app php artisan config:cache >/dev/null
$DOCKER_COMPOSE exec -T -w /var/www/html app php artisan route:cache >/dev/null
$DOCKER_COMPOSE restart app queue scheduler >/dev/null 2>&1

# --- Verify against the origin directly (bypasses any proxy in front) ---
info "Verifying HTTPS on the origin..."
if curl -fsS --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health" >/dev/null 2>&1; then
    success "HTTPS is answering on this server."
else
    warning "Could not verify https://$DOMAIN/health locally. Check: $DOCKER_COMPOSE logs nginx"
fi

echo "" >&2
success "HTTPS enabled for $DOMAIN"
echo "" >&2
echo "  Dashboard:  https://$DOMAIN/app" >&2
echo "  Renewal:    automatic (certbot container checks every 12h)" >&2
echo "" >&2
echo "  Plain HTTP now redirects to HTTPS." >&2
echo "  If this domain sits behind Cloudflare, set its SSL/TLS mode to" >&2
echo "  \"Full (strict)\" now. Staying on Flexible causes a redirect loop." >&2
echo "" >&2
```

- [ ] **Step 2: Make it executable and syntax-check**

Run:
```bash
chmod +x scripts/enable-https.sh
bash -n scripts/enable-https.sh && echo SYNTAX_OK
```
Expected: `SYNTAX_OK`.

- [ ] **Step 3: Sanity-check the failure path logic by reading it**

Confirm these three properties hold in the script as written (they are the spec's failure guarantees): a failed certbot run on a FIRST enable removes the override and generated dir and recreates nginx (back to the tracked HTTP config); a failed certbot run on a RE-run leaves the existing working state alone (`FRESH_SETUP=false` makes `rollback` a no-op); the script never writes to any git-tracked file.

- [ ] **Step 4: Commit**

```bash
git add scripts/enable-https.sh
git commit -m "Add enable-https.sh for Let's Encrypt setup with auto renewal"
```

Portability note (do not "fix" this): `sed -i`, `hostname -I`, and `getent` are Linux-only. The script targets the Linux servers ShipYard installs on; it is not meant to run on macOS.

---

### Task 4: Wire the optional domain prompt into `install.sh`

**Files:**
- Modify: `install.sh` (three spots: after the route:cache line around line 414, the dashboard URL block around lines 423 to 427, the custom domain block around lines 439 to 441)

- [ ] **Step 1: Add the HTTPS prompt after the "Optimizing application..." block**

Directly after the `php artisan route:cache` line and before the success banner, insert:

```bash
    echo "" >&2
    echo -e "${BOLD}=== HTTPS Setup (optional) ===${NC}" >&2
    echo "If a domain already points at this server, ShipYard can obtain a free" >&2
    echo "Let's Encrypt certificate and serve over HTTPS with automatic renewal." >&2
    echo "Leave empty to skip. You can enable it any time later with:" >&2
    echo "  bash ${INSTALL_DIR}/scripts/enable-https.sh yourdomain.com" >&2
    echo "" >&2

    HTTPS_ENABLED=false
    HTTPS_DOMAIN=$(prompt_with_default "Domain (leave empty to skip)" "")
    if [ -n "$HTTPS_DOMAIN" ]; then
        if bash scripts/enable-https.sh "$HTTPS_DOMAIN"; then
            HTTPS_ENABLED=true
        else
            warning "HTTPS setup did not complete."
            warning "Once the cause is fixed, run: bash ${INSTALL_DIR}/scripts/enable-https.sh $HTTPS_DOMAIN"
        fi
    fi
```

Note `set -e` is active in install.sh; the `if bash ...` guard is what keeps a certbot failure from aborting the installer. Do not change it to a bare call.

- [ ] **Step 2: Make the summary print the right URL**

Replace the current dashboard block:

```bash
    echo -e "${BOLD}Access your dashboard:${NC}" >&2
    echo -e "  ${CYAN}http://${SERVER_IP}/app${NC}" >&2
    if [ "$HTTP_PORT" != "80" ]; then
        echo -e "  ${CYAN}http://${SERVER_IP}:${HTTP_PORT}/app${NC}" >&2
    fi
```

with:

```bash
    echo -e "${BOLD}Access your dashboard:${NC}" >&2
    if [ "$HTTPS_ENABLED" = true ]; then
        echo -e "  ${CYAN}https://${HTTPS_DOMAIN}/app${NC}" >&2
    else
        echo -e "  ${CYAN}http://${SERVER_IP}/app${NC}" >&2
        if [ "$HTTP_PORT" != "80" ]; then
            echo -e "  ${CYAN}http://${SERVER_IP}:${HTTP_PORT}/app${NC}" >&2
        fi
    fi
```

- [ ] **Step 3: Update the custom domain hint**

Replace:

```bash
    echo -e "${BOLD}Custom domain:${NC}" >&2
    echo "  Point your domain's DNS A record to ${SERVER_IP}" >&2
    echo "  Then access via: http://yourdomain.com/app" >&2
```

with:

```bash
    echo -e "${BOLD}Custom domain:${NC}" >&2
    echo "  Point your domain's DNS A record to ${SERVER_IP}" >&2
    echo "  Then run: bash ${INSTALL_DIR}/scripts/enable-https.sh yourdomain.com" >&2
```

Also wrap the summary's SSL security reminder line in `if [ "$HTTPS_ENABLED" != true ]; then ... fi` so a fresh HTTPS install is not told to set up certificates it just got. The firewall and updates reminder lines stay unconditional.

- [ ] **Step 4: Syntax-check**

Run:
```bash
bash -n install.sh && echo SYNTAX_OK
```
Expected: `SYNTAX_OK`.

- [ ] **Step 5: Commit**

```bash
git add install.sh
git commit -m "Offer optional HTTPS setup during install"
```

---

### Task 5: Document HTTPS in the README

**Files:**
- Modify: `README.md` (insert a subsection at the end of the Installation section, immediately before the `## Usage` heading around line 168)

- [ ] **Step 1: Insert this subsection before `## Usage`**

````markdown
### Enabling HTTPS

If a domain points at your server, ShipYard can obtain a free Let's Encrypt certificate and serve everything over HTTPS with automatic renewal. The installer offers this as an optional step. On an existing installation, run:

```bash
bash scripts/enable-https.sh yourdomain.com
```

Requirements: the domain's DNS A record must point at the server (a proxy such as Cloudflare in front is fine) and ShipYard must be reachable on port 80.

The script obtains the certificate with a one time certbot run, switches nginx to HTTPS (plain HTTP then redirects, keeping only the certificate renewal path), updates `APP_URL`, and starts a certbot container that renews automatically. All of its state lives in `docker-compose.override.yml`, `docker/nginx/conf.d-generated/`, and `docker/certbot/`, none of which are tracked by git.

Behind Cloudflare, switch the SSL/TLS encryption mode to Full (strict) right after enabling HTTPS. Flexible mode combined with the new redirect causes a redirect loop.

To return to plain HTTP, delete `docker-compose.override.yml` and `docker/nginx/conf.d-generated/`, then run `docker compose up -d`.
````

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "Document HTTPS setup in the README"
```

---

### End-to-end verification (manual, after all tasks)

Per the spec, on real infrastructure (the user's usual throwaway VPS flow):

1. Fresh VPS: run the installer with a real subdomain. Expect `https://{domain}/app` to load, HTTP to redirect, and `docker compose ps` to show the certbot container up.
2. Existing install (the Hostinger box): `git pull`, run `bash scripts/enable-https.sh shipyard.rmattone.dev`, then set Cloudflare to Full (strict) and confirm login works with no redirect loop.
3. Failure path: run the script with a domain that does not resolve; expect a clean error, exit code 1, and the stack still serving HTTP.
4. Renewal dry run: `docker compose exec certbot certbot renew --dry-run` succeeds.
