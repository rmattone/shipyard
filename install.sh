#!/bin/bash

set -e

# =============================================================================
# ShipYard Installation Script
# =============================================================================
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/YOUR_USERNAME/shipyard/main/install.sh | bash
#
# Or download and run:
#   wget -O install.sh https://raw.githubusercontent.com/YOUR_USERNAME/shipyard/main/install.sh
#   chmod +x install.sh
#   ./install.sh
# =============================================================================

# Repository URL - Change this to your repository
REPO_URL="${SHIPYARD_REPO_URL:-https://github.com/rmattone/shipyard.git}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# ASCII Art Banner (output to stderr for pipe compatibility)
print_banner() {
    echo -e "${CYAN}" >&2
    cat >&2 << "EOF"
  ____  _     _       __   __            _
 / ___|| |__ (_)_ __  \ \ / /_ _ _ __ __| |
 \___ \| '_ \| | '_ \  \ V / _` | '__/ _` |
  ___) | | | | | |_) |  | | (_| | | | (_| |
 |____/|_| |_|_| .__/   |_|\__,_|_|  \__,_|
               |_|
EOF
    echo -e "${NC}" >&2
    echo -e "${BOLD}Self-Hosted Server Management & Deployment Platform${NC}" >&2
    echo "" >&2
}

# Print colored messages (to stderr so they show when piped)
info() { echo -e "${BLUE}[INFO]${NC} $1" >&2; }
success() { echo -e "${GREEN}[SUCCESS]${NC} $1" >&2; }
warning() { echo -e "${YELLOW}[WARNING]${NC} $1" >&2; }
error() { echo -e "${RED}[ERROR]${NC} $1" >&2; exit 1; }

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Check prerequisites
check_prerequisites() {
    info "Checking prerequisites..."

    local missing=()

    if ! command_exists docker; then
        missing+=("docker")
    fi

    if ! command_exists docker-compose && ! docker compose version >/dev/null 2>&1; then
        missing+=("docker-compose")
    fi

    if ! command_exists git; then
        missing+=("git")
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        error "Missing required dependencies: ${missing[*]}\nPlease install them and try again."
    fi

    # Check if Docker is running
    if ! docker info >/dev/null 2>&1; then
        error "Docker is not running. Please start Docker and try again."
    fi

    success "All prerequisites met!"
}

# Get docker compose command
get_docker_compose_cmd() {
    if docker compose version >/dev/null 2>&1; then
        echo "docker compose"
    else
        echo "docker-compose"
    fi
}

# Prompt for input with default value
# Uses /dev/tty to read from terminal even when script is piped
prompt_with_default() {
    local prompt="$1"
    local default="$2"
    local result

    if [ -n "$default" ]; then
        printf "%s [%s]: " "$prompt" "$default" >&2
        read result </dev/tty
        echo "${result:-$default}"
    else
        printf "%s: " "$prompt" >&2
        read result </dev/tty
        echo "$result"
    fi
}

# Prompt for password (hidden input)
# Uses /dev/tty to read from terminal even when script is piped
prompt_password() {
    local prompt="$1"
    local password
    local password_confirm

    while true; do
        printf "%s: " "$prompt" >&2
        read -s password </dev/tty
        echo >&2
        printf "Confirm password: " >&2
        read -s password_confirm </dev/tty
        echo >&2

        if [ "$password" != "$password_confirm" ]; then
            warning "Passwords do not match. Please try again."
        elif [ ${#password} -lt 8 ]; then
            warning "Password must be at least 8 characters. Please try again."
        else
            echo "$password"
            return
        fi
    done
}

# Validate email format
validate_email() {
    local email="$1"
    if [[ "$email" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
        return 0
    else
        return 1
    fi
}

# Prompt for email with validation
# Uses /dev/tty to read from terminal even when script is piped
prompt_email() {
    local email
    while true; do
        printf "Admin email: " >&2
        read email </dev/tty
        if validate_email "$email"; then
            echo "$email"
            return
        else
            warning "Invalid email format. Please try again."
        fi
    done
}

# Generate random string
generate_random_string() {
    local length="${1:-32}"
    openssl rand -base64 48 | tr -dc 'a-zA-Z0-9' | head -c "$length"
}

# Check if port is available
check_port() {
    local port="$1"
    if command_exists lsof; then
        if lsof -Pi ":$port" -sTCP:LISTEN -t >/dev/null 2>&1; then
            return 1
        fi
    elif command_exists netstat; then
        if netstat -tuln 2>/dev/null | grep -q ":$port "; then
            return 1
        fi
    elif command_exists ss; then
        if ss -tuln 2>/dev/null | grep -q ":$port "; then
            return 1
        fi
    fi
    return 0
}

# Prompt for port with availability check
prompt_port() {
    local prompt="$1"
    local default="$2"
    local port

    while true; do
        port=$(prompt_with_default "$prompt" "$default")

        # Validate port number
        if ! [[ "$port" =~ ^[0-9]+$ ]] || [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
            warning "Invalid port number. Please enter a number between 1 and 65535."
            continue
        fi

        # Check if port is available
        if ! check_port "$port"; then
            warning "Port $port is already in use. Please choose another port."
            continue
        fi

        echo "$port"
        return
    done
}

# Main installation
main() {
    print_banner

    echo -e "${BOLD}Welcome to the ShipYard installer!${NC}" >&2
    echo "This script will set up ShipYard on your server." >&2
    echo "" >&2

    check_prerequisites

    DOCKER_COMPOSE=$(get_docker_compose_cmd)

    # Determine installation directory
    INSTALL_DIR=$(pwd)
    if [ ! -f "$INSTALL_DIR/docker-compose.yml" ]; then
        # We're not in the project directory, need to clone
        info "Cloning ShipYard repository..."
        INSTALL_DIR="$HOME/shipyard"

        if [ -d "$INSTALL_DIR" ]; then
            warning "Directory $INSTALL_DIR already exists."
            echo "" >&2
            echo "  1) Reinstall (remove and clone fresh)" >&2
            echo "  2) Update (use existing directory)" >&2
            echo "  3) Cancel" >&2
            echo "" >&2
            printf "Choose an option [2]: " >&2
            read choice </dev/tty
            choice="${choice:-2}"

            case "$choice" in
                1)
                    info "Removing existing installation..."
                    rm -rf "$INSTALL_DIR"
                    git clone "$REPO_URL" "$INSTALL_DIR"
                    ;;
                2)
                    info "Using existing directory..."
                    cd "$INSTALL_DIR"
                    info "Pulling latest changes..."
                    git pull origin main 2>/dev/null || git pull origin master 2>/dev/null || true
                    ;;
                3)
                    error "Installation cancelled."
                    ;;
                *)
                    info "Using existing directory..."
                    ;;
            esac
            cd "$INSTALL_DIR"
        else
            git clone "$REPO_URL" "$INSTALL_DIR"
            cd "$INSTALL_DIR"
        fi
    fi

    echo "" >&2
    echo -e "${BOLD}=== Admin Account Setup ===${NC}" >&2
    echo "Please enter the credentials for your admin account." >&2
    echo "" >&2

    # Get admin credentials
    ADMIN_NAME=$(prompt_with_default "Admin name" "Admin")
    ADMIN_EMAIL=$(prompt_email)
    ADMIN_PASSWORD=$(prompt_password "Admin password")

    echo "" >&2
    echo -e "${BOLD}=== Application Settings ===${NC}" >&2
    echo "" >&2

    # Get app URL
    DEFAULT_URL="http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo 'localhost')"
    APP_URL=$(prompt_with_default "Application URL" "$DEFAULT_URL")

    # Get ports (with availability check)
    HTTP_PORT=$(prompt_port "HTTP port" "80")
    HTTPS_PORT=$(prompt_port "HTTPS port" "443")

    echo "" >&2
    echo -e "${BOLD}=== Database Configuration ===${NC}" >&2
    echo "" >&2

    # Get database credentials
    DB_DATABASE=$(prompt_with_default "Database name" "server_management")
    DB_USERNAME=$(prompt_with_default "Database username" "shipyard")
    DB_PASSWORD=$(prompt_password "Database password")

    # Generate root password automatically
    info "Generating secure database root password..."
    DB_ROOT_PASSWORD=$(generate_random_string 32)

    echo "" >&2
    info "Configuring environment..."

    # Create root .env file for docker-compose
    cat > .env << EOF
# =============================================================================
# ShipYard Docker Configuration
# Generated by install.sh - DO NOT COMMIT THIS FILE
# =============================================================================

# HTTP/HTTPS Ports
HTTP_PORT=${HTTP_PORT}
HTTPS_PORT=${HTTPS_PORT}

# Database Configuration
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
EOF

    # Create backend .env file
    cat > backend/.env << EOF
APP_NAME="ShipYard"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=${APP_URL}

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis

CACHE_STORE=redis
CACHE_PREFIX=server_mgmt

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Admin credentials (used during initial seeding)
ADMIN_NAME="${ADMIN_NAME}"
ADMIN_EMAIL="${ADMIN_EMAIL}"
ADMIN_PASSWORD="${ADMIN_PASSWORD}"

SANCTUM_STATEFUL_DOMAINS=localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1,${APP_URL##*://}
EOF

    echo "" >&2
    info "Starting Docker containers..."
    $DOCKER_COMPOSE up -d

    echo "" >&2
    info "Waiting for services to be ready..."
    sleep 10

    # Wait for MySQL to be ready
    info "Waiting for MySQL..."
    until $DOCKER_COMPOSE exec -T mysql mysqladmin ping -h localhost -u root -p"${DB_ROOT_PASSWORD}" --silent 2>/dev/null; do
        sleep 2
    done
    success "MySQL is ready!"

    echo "" >&2
    info "Installing PHP dependencies..."
    $DOCKER_COMPOSE exec -T app composer install --no-dev --optimize-autoloader

    echo "" >&2
    info "Generating application key..."
    $DOCKER_COMPOSE exec -T app php artisan key:generate --force

    echo "" >&2
    info "Running database migrations..."
    $DOCKER_COMPOSE exec -T app php artisan migrate --force

    echo "" >&2
    info "Creating admin user..."
    $DOCKER_COMPOSE exec -T app php artisan db:seed --force

    echo "" >&2
    info "Optimizing application..."
    $DOCKER_COMPOSE exec -T app php artisan config:cache
    $DOCKER_COMPOSE exec -T app php artisan route:cache
    $DOCKER_COMPOSE exec -T app php artisan view:cache

    echo "" >&2
    info "Building frontend (inside Docker)..."
    $DOCKER_COMPOSE exec -T app bash -c "cd /var/www/frontend && npm install && npm run build"

    echo "" >&2
    echo -e "${GREEN}${BOLD}" >&2
    echo "==============================================" >&2
    echo "   ShipYard installed successfully!" >&2
    echo "==============================================" >&2
    echo -e "${NC}" >&2
    echo "" >&2
    echo -e "Access your dashboard at: ${CYAN}${APP_URL}/app${NC}" >&2
    echo "" >&2
    echo -e "${BOLD}Login credentials:${NC}" >&2
    echo -e "  Email:    ${CYAN}${ADMIN_EMAIL}${NC}" >&2
    echo -e "  Password: ${CYAN}(the password you entered)${NC}" >&2
    echo "" >&2
    echo -e "${BOLD}Useful commands:${NC}" >&2
    echo "  View logs:      $DOCKER_COMPOSE logs -f" >&2
    echo "  Stop services:  $DOCKER_COMPOSE down" >&2
    echo "  Start services: $DOCKER_COMPOSE up -d" >&2
    echo "  Restart:        $DOCKER_COMPOSE restart" >&2
    echo "" >&2
    echo -e "${YELLOW}Security reminder:${NC}" >&2
    echo "  - Set up SSL/TLS certificates for production use" >&2
    echo "  - Configure your firewall to restrict access" >&2
    echo "  - Keep your system and Docker images updated" >&2
    echo "" >&2
    success "Installation complete!"
}

# Run main function
main "$@"
