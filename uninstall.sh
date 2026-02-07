#!/bin/bash

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color
BOLD='\033[1m'

info() { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1"; }
warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# Prompt function that works with piped scripts
prompt_confirm() {
    local prompt="$1"
    local result
    printf "%s " "$prompt" >&2
    read result </dev/tty 2>/dev/null || read result
    echo "$result"
}

# Get docker compose command
get_docker_compose_cmd() {
    if docker compose version >/dev/null 2>&1; then
        echo "docker compose"
    else
        echo "docker-compose"
    fi
}

main() {
    echo -e "${RED}${BOLD}"
    echo "=============================================="
    echo "   ShipYard Uninstaller"
    echo "=============================================="
    echo -e "${NC}"
    echo ""

    DOCKER_COMPOSE=$(get_docker_compose_cmd)

    warning "This will stop and remove all ShipYard containers and data."
    echo ""
    confirm=$(prompt_confirm "Are you sure you want to continue? (y/N):")

    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        info "Uninstallation cancelled."
        exit 0
    fi

    echo ""
    remove_data=$(prompt_confirm "Do you want to remove the database volume (all data will be lost)? (y/N):")

    echo ""
    info "Stopping containers..."
    $DOCKER_COMPOSE down

    if [ "$remove_data" = "y" ] || [ "$remove_data" = "Y" ]; then
        info "Removing volumes..."
        $DOCKER_COMPOSE down -v
        success "All containers and volumes removed."
    else
        success "Containers stopped. Database volume preserved."
    fi

    echo ""
    remove_env=$(prompt_confirm "Do you want to remove configuration files (.env files)? (y/N):")

    if [ "$remove_env" = "y" ] || [ "$remove_env" = "Y" ]; then
        rm -f .env backend/.env
        success "Configuration files removed."
    fi

    echo ""
    remove_dir=$(prompt_confirm "Do you want to remove the installation directory? (y/N):")

    if [ "$remove_dir" = "y" ] || [ "$remove_dir" = "Y" ]; then
        INSTALL_DIR=$(pwd)
        cd ..
        rm -rf "$INSTALL_DIR"
        success "Installation directory removed."
    fi

    echo ""
    success "ShipYard has been uninstalled."
}

main "$@"
