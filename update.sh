#!/bin/bash
#
# ShipYard update script.
#
# Two ways to run it:
#   host:      cd /path/to/shipyard && bash update.sh
#   container: the Settings > System page queues a job that runs this file
#              inside the queue container, where the checkout is mounted at
#              /var/www/shipyard (see docker-compose.yml).
#
# Both paths do the same seven steps. Inside a container the commands run
# directly (same image, same mounts); on the host they go through
# `docker compose exec`. Data and .env files are never touched.
#
# Container definition changes (docker-compose.yml, docker/) cannot be
# applied from inside a container. The script says so at the end when the
# pulled range touched them, and the host then runs:
#   docker compose up -d --build
#

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

BRANCH="${SHIPYARD_BRANCH:-main}"
STEP="starting"

fail() {
    echo -e "${RED}Error: $*${NC}" >&2
    exit 1
}

on_error() {
    local code=$?
    echo -e "${RED}Error: update failed during step '${STEP}' (exit ${code}).${NC}" >&2
    # Leave the app reachable even if the pull or build failed midway.
    run_app php artisan up >/dev/null 2>&1 || true
    exit "$code"
}
trap on_error ERR

# ---------------------------------------------------------------------------
# Locate the checkout and decide how to run commands
# ---------------------------------------------------------------------------

IN_CONTAINER=0
if [ -f /.dockerenv ] && [ -f /var/www/shipyard/docker-compose.yml ]; then
    IN_CONTAINER=1
    INSTALL_DIR="/var/www/shipyard"
elif [ -f "docker-compose.yml" ] && [ -d "backend" ]; then
    INSTALL_DIR="$(pwd)"
elif [ -f "/var/www/shipyard/docker-compose.yml" ]; then
    INSTALL_DIR="/var/www/shipyard"
elif [ -f "$HOME/shipyard/docker-compose.yml" ]; then
    INSTALL_DIR="$HOME/shipyard"
else
    fail "Could not find the ShipYard checkout. Run this from the directory that contains docker-compose.yml."
fi

cd "$INSTALL_DIR"

# Run a command in the app environment: directly when we are already in a
# container built from the same image, otherwise via docker compose.
run_app() {
    if [ "$IN_CONTAINER" = "1" ]; then
        (cd /var/www/html && "$@")
    else
        docker compose exec -T app "$@"
    fi
}

[ -f ".env" ] || fail ".env not found in ${INSTALL_DIR}."

if [ "$IN_CONTAINER" = "0" ]; then
    # docker compose needs the variables the compose file interpolates.
    set -a
    # shellcheck disable=SC1091
    source .env
    set +a
fi

# The checkout is a bind mount owned by another uid when seen from a
# container; git refuses to touch it without this.
git config --global --add safe.directory "$INSTALL_DIR" >/dev/null 2>&1 || true

[ -d .git ] || fail "${INSTALL_DIR} is not a git checkout. Re-install with install.sh to update automatically."

# ---------------------------------------------------------------------------
# Guards: only fast-forward a clean checkout of the release branch
# ---------------------------------------------------------------------------

CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [ "$CURRENT_BRANCH" != "$BRANCH" ]; then
    fail "Checkout is on branch '${CURRENT_BRANCH}'. The updater only runs on '${BRANCH}'. Switch branches (or set SHIPYARD_BRANCH) and try again."
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    fail "The checkout has local modifications. Commit, stash, or discard them before updating."
fi

echo -e "${GREEN}"
echo "  ____  _     _       __   __            _ "
echo " / ___|| |__ (_)_ __ \ \ / /_ _ _ __ __| |"
echo " \___ \| '_ \| | '_ \ \ V / _\` | '__/ _\` |"
echo "  ___) | | | | | |_) | | | (_| | | | (_| |"
echo " |____/|_| |_|_| .__/  |_|\__,_|_|  \__,_|"
echo "               |_|                         "
echo -e "${NC}"
echo "Updating ShipYard in ${INSTALL_DIR} (branch ${BRANCH}${IN_CONTAINER:+, from inside the container})"
echo ""

BEFORE="$(git rev-parse HEAD)"

# ---------------------------------------------------------------------------
# Steps
# ---------------------------------------------------------------------------

STEP="maintenance mode"
echo -e "${YELLOW}[1/7]${NC} Enabling maintenance mode..."
run_app php artisan down --retry=60 >/dev/null 2>&1 || true

STEP="backup configuration"
echo -e "${YELLOW}[2/7]${NC} Backing up configuration..."
cp -f .env .env.backup 2>/dev/null || true
cp -f backend/.env backend/.env.backup 2>/dev/null || true

STEP="pull"
echo -e "${YELLOW}[3/7]${NC} Fetching ${BRANCH}..."
git fetch --quiet origin "$BRANCH"
if git merge --ff-only "origin/${BRANCH}" >/dev/null; then
    AFTER="$(git rev-parse HEAD)"
    if [ "$BEFORE" = "$AFTER" ]; then
        echo "      Already at $(git rev-parse --short HEAD); re-running the install steps."
    else
        echo "      $(git rev-parse --short "$BEFORE") -> $(git rev-parse --short "$AFTER")"
        git --no-pager log --oneline "${BEFORE}..${AFTER}" | sed 's/^/      /'
    fi
else
    fail "Local ${BRANCH} has diverged from origin/${BRANCH}; a fast-forward is not possible."
fi

STEP="composer install"
echo -e "${YELLOW}[4/7]${NC} Installing PHP dependencies..."
run_app env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader --no-progress 2>&1 | grep -v '^$' || true

STEP="migrations"
echo -e "${YELLOW}[5/7]${NC} Running database migrations..."
run_app php artisan migrate --force

STEP="frontend build"
echo -e "${YELLOW}[6/7]${NC} Building frontend..."
run_app bash -c "cd /var/www/frontend && npm ci --no-audit --no-fund --loglevel=error && npm run build 2>&1 | tail -n 5"

STEP="finalize"
echo -e "${YELLOW}[7/7]${NC} Finalizing..."
run_app php artisan config:clear
run_app php artisan cache:clear
run_app php artisan view:clear
run_app php artisan route:clear
run_app php artisan queue:restart
run_app php artisan up

# ---------------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------------

echo ""
if git diff --name-only "$BEFORE" "$AFTER" | grep -qE '^(docker-compose\.yml|docker/|update\.sh)'; then
    echo -e "${YELLOW}NOTICE:${NC} container definitions changed in this update."
    echo "        Run on the host to apply them:  cd ${INSTALL_DIR} && docker compose up -d --build"
    echo ""
fi

echo -e "${GREEN}Update complete!${NC}"
echo "ShipYard is now v$(cat VERSION 2>/dev/null || echo '?') at commit $(git rev-parse --short HEAD)."
echo "Your data and configuration have been preserved."
