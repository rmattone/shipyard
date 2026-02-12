#!/bin/bash

#
# ShipYard Update Script
# Safe update that preserves all data
#

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Find installation directory
if [ -f "docker-compose.yml" ] && grep -q "shipyard" docker-compose.yml 2>/dev/null; then
    INSTALL_DIR="$(pwd)"
elif [ -d "/var/www/shipyard" ]; then
    INSTALL_DIR="/var/www/shipyard"
elif [ -d "$HOME/shipyard" ]; then
    INSTALL_DIR="$HOME/shipyard"
else
    echo -e "${RED}Error: Could not find ShipYard installation directory${NC}"
    echo "Please run this script from the ShipYard directory or specify the path"
    exit 1
fi

cd "$INSTALL_DIR"

# Check for .env file
if [ ! -f ".env" ]; then
    echo -e "${RED}Error: .env file not found in $INSTALL_DIR${NC}"
    echo "Please ensure you have a .env file with database credentials."
    exit 1
fi

# Export env vars for docker compose
set -a
source .env
set +a

echo -e "${GREEN}"
echo "  ____  _     _       __   __            _ "
echo " / ___|| |__ (_)_ __ \ \ / /_ _ _ __ __| |"
echo " \___ \| '_ \| | '_ \ \ V / _\` | '__/ _\` |"
echo "  ___) | | | | | |_) | | | (_| | | | (_| |"
echo " |____/|_| |_|_| .__/  |_|\__,_|_|  \__,_|"
echo "               |_|                         "
echo -e "${NC}"
echo "Updating ShipYard..."
echo ""

# Step 1: Enable maintenance mode
echo -e "${YELLOW}[1/7]${NC} Enabling maintenance mode..."
docker compose exec -T app php artisan down --retry=60 2>/dev/null || true

# Step 2: Backup .env files (just in case)
echo -e "${YELLOW}[2/7]${NC} Backing up configuration..."
cp -f .env .env.backup 2>/dev/null || true
cp -f backend/.env backend/.env.backup 2>/dev/null || true

# Step 3: Pull latest code
echo -e "${YELLOW}[3/7]${NC} Pulling latest changes..."
if [ -d ".git" ]; then
    git fetch origin
    git pull origin main
else
    # No git repo - download and extract latest
    echo "No git repository found, downloading latest release..."
    curl -fsSL https://github.com/rmattone/shipyard/archive/refs/heads/main.tar.gz -o /tmp/shipyard-update.tar.gz
    tar -xzf /tmp/shipyard-update.tar.gz -C /tmp

    # Copy new files (preserving .env files)
    rsync -a --exclude='.env' --exclude='backend/.env' /tmp/shipyard-main/ "$INSTALL_DIR/"

    rm -rf /tmp/shipyard-update.tar.gz /tmp/shipyard-main
fi

# Step 4: Install PHP dependencies
echo -e "${YELLOW}[4/7]${NC} Installing PHP dependencies..."
docker compose exec -T app composer install --no-interaction --no-dev --optimize-autoloader

# Step 5: Run migrations (safe - only adds new tables/columns)
echo -e "${YELLOW}[5/7]${NC} Running database migrations..."
docker compose exec -T app php artisan migrate --force

# Step 6: Build frontend
echo -e "${YELLOW}[6/7]${NC} Building frontend..."
docker compose exec -T app bash -c "cd /var/www/frontend && npm install && npm run build"

# Step 7: Clear caches and disable maintenance mode
echo -e "${YELLOW}[7/7]${NC} Finalizing update..."
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan cache:clear
docker compose exec -T app php artisan view:clear
docker compose exec -T app php artisan route:clear
docker compose exec -T app php artisan up

echo ""
echo -e "${GREEN}Update complete!${NC}"
echo ""
echo "ShipYard has been updated successfully."
echo "Your data and configuration have been preserved."
echo ""
