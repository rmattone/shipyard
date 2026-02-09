<p align="center">
  <img src="docs/images/logo.png" alt="ShipYard Logo" width="120" height="120">
</p>

<h1 align="center">ShipYard</h1>

<p align="center">
  <strong>Self-hosted server management and deployment platform</strong>
</p>

<p align="center">
  Deploy Laravel, Node.js, and static sites to your servers without Docker.<br>
  A self-hosted alternative to Laravel Forge, Ploi, and similar platforms.
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#development">Development</a> •
  <a href="#license">License</a>
</p>

---

## The Problem

Deploying applications to servers can be complex and time-consuming:

- **Manual SSH sessions** - Connecting to servers, running commands, hoping nothing breaks
- **Inconsistent deployments** - Different team members deploy differently
- **No rollback strategy** - When things go wrong, recovery is painful
- **Environment variable chaos** - Managing secrets across multiple servers
- **SSL certificate hassle** - Manual certificate generation and renewal
- **No visibility** - Hard to track what's deployed where

## The Solution

ShipYard provides a clean web interface to manage your servers and automate deployments:

- **One-click deployments** - Push to Git, deploy automatically
- **Atomic deployments** - Zero-downtime releases with instant rollback
- **Centralized secrets** - Encrypted environment variables in one place
- **Automatic SSL** - Let's Encrypt certificates with auto-renewal
- **Real-time logs** - Watch deployments happen live
- **Multi-server support** - Manage all your servers from one dashboard

---

## Features

### Server Management
- **Multi-server support** - Connect unlimited servers via SSH
- **Connection testing** - Verify server connectivity before deploying
- **Server metrics** - Monitor CPU, memory, and disk usage
- **Software detection** - Automatically detect installed software (PHP, Node.js, MySQL, etc.)

### Application Deployment
- **Laravel applications** - Full support with migrations, caching, and queue restart
- **Node.js applications** - PM2 process management included
- **Static sites** - Build and deploy frontend applications
- **Custom build commands** - Define your own deployment scripts

### Atomic Deployments
- **Zero-downtime releases** - Symlink-based deployment strategy
- **Instant rollback** - Revert to any previous release in seconds
- **Release history** - Keep track of all deployments

### Git Integration
- **GitHub support** - Connect your GitHub repositories
- **GitLab support** - Full GitLab integration
- **Bitbucket support** - Works with Bitbucket too
- **Webhook deployments** - Automatic deploys on push
- **Branch selection** - Deploy from any branch

### Domain & SSL Management
- **Multiple domains** - Add multiple domains per application
- **Automatic SSL** - Free Let's Encrypt certificates
- **Auto-renewal** - Certificates renew automatically
- **Nginx configuration** - Automatically generated and optimized

### Database Management
- **MySQL support** - Install and manage MySQL databases
- **PostgreSQL support** - Full PostgreSQL support
- **Database creation** - Create databases and users from the UI
- **Secure credentials** - Encrypted storage for database passwords

### Environment Variables
- **Encrypted storage** - All secrets encrypted at rest
- **Easy editing** - Add, edit, delete variables from the UI
- **Sync to server** - Push changes to servers instantly

### Security
- **SSH key authentication** - Secure server connections
- **Encrypted secrets** - All sensitive data encrypted
- **API tokens** - Secure API access with Laravel Sanctum
- **Webhook secrets** - Protected webhook endpoints

---

## Installation

### Quick Install (Recommended)

Run this single command to install ShipYard:

```bash
curl -fsSL https://raw.githubusercontent.com/rmattone/shipyard/main/install.sh | bash
```

The installer will prompt you for:
- **Admin credentials** - Name, email, and password for your admin account
- **HTTP/HTTPS ports** - Which ports to expose (default: 80/443)
- **Database credentials** - Database name, username, and password

The server IP is auto-detected and ShipYard will be accessible at `http://YOUR_SERVER_IP/app`

### Prerequisites

- **Docker** and **Docker Compose** installed
- **Git** installed

That's it! Node.js and all other dependencies run inside Docker.

### Manual Installation

If you prefer to install manually:

```bash
# 1. Clone the repository
git clone https://github.com/rmattone/shipyard.git
cd shipyard

# 2. Copy environment files
cp .env.example .env
cp backend/.env.example backend/.env

# 3. Edit .env and set your database credentials
nano .env

# 4. Edit backend/.env and configure your settings
nano backend/.env

# 5. Start Docker containers
docker compose up -d

# 6. Wait for MySQL to be ready (about 30 seconds)
docker compose logs -f mysql
# Press Ctrl+C when you see "ready for connections"

# 7. Install PHP dependencies
docker compose exec app composer install

# 8. Generate application key
docker compose exec app php artisan key:generate

# 9. Run database migrations
docker compose exec app php artisan migrate --seed

# 10. Build the frontend (inside Docker)
docker compose exec app bash -c "cd /var/www/frontend && npm install && npm run build"

# 11. Access ShipYard at http://localhost/app
```

---

## Usage

### Adding a Server

1. Go to **Servers** → **Add Server**
2. Enter your server details:
   - **Name** - A friendly name for the server
   - **IP Address** - The server's IP address
   - **SSH Port** - Usually 22
   - **SSH User** - The user to connect as (e.g., `root` or `deploy`)
   - **SSH Key** - Your private SSH key
3. Click **Test Connection** to verify
4. Save the server

### Deploying an Application

1. Go to **Applications** → **New Application**
2. Configure your application:
   - **Name** - Application name
   - **Server** - Select the target server
   - **Repository** - Git repository URL
   - **Branch** - Branch to deploy
   - **Type** - Laravel, Node.js, or Static
   - **Domain** - Your application's domain
3. Save and click **Deploy**

### Setting Up Automatic Deployments

1. Go to your application's **Settings**
2. Copy the **Webhook URL**
3. Add it to your Git provider:
   - **GitHub**: Settings → Webhooks → Add webhook
   - **GitLab**: Settings → Webhooks → Add webhook
   - **Bitbucket**: Settings → Webhooks → Add webhook
4. Push to your repository - ShipYard will deploy automatically

### Managing Environment Variables

1. Go to your application's **Environment** tab
2. Add variables one by one or paste a `.env` file
3. Click **Sync to Server** to push changes
4. Restart your application if needed

---

## Configuration

### Environment Variables

#### Root `.env` (Docker configuration)

| Variable | Description | Default |
|----------|-------------|---------|
| `HTTP_PORT` | HTTP port for Nginx | `80` |
| `HTTPS_PORT` | HTTPS port for Nginx | `443` |
| `DB_DATABASE` | Database name | `server_management` |
| `DB_USERNAME` | Database username | (required) |
| `DB_PASSWORD` | Database password | (required) |
| `DB_ROOT_PASSWORD` | MySQL root password | (required) |

#### Backend `.env` (Laravel configuration)

| Variable | Description |
|----------|-------------|
| `APP_URL` | Your ShipYard URL (auto-detected during install) |
| `APP_KEY` | Application encryption key (auto-generated) |
| `DB_*` | Database connection settings |
| `REDIS_*` | Redis connection settings |
| `ADMIN_NAME` | Initial admin name |
| `ADMIN_EMAIL` | Initial admin email |
| `ADMIN_PASSWORD` | Initial admin password |

**Note:** To use a custom domain, just point your domain's DNS A record to your server's IP address. ShipYard accepts any hostname automatically.

---

## Uninstallation

### Using the Uninstall Script

```bash
./uninstall.sh
```

The script will prompt you to:
- Stop and remove containers
- Remove database volumes (optional)
- Remove configuration files (optional)
- Remove the installation directory (optional)

### Manual Uninstallation

```bash
# Stop and remove containers
docker compose down

# Remove volumes (deletes all data)
docker compose down -v

# Remove configuration files
rm -f .env backend/.env

# Remove installation directory (if desired)
cd .. && rm -rf shipyard
```

---

## Architecture

### Tech Stack

| Component | Technology |
|-----------|------------|
| **Backend** | Laravel 11 (PHP 8.2) |
| **Frontend** | React 18 + TypeScript + Vite |
| **Database** | MySQL 8 |
| **Cache/Queue** | Redis |
| **Web Server** | Nginx |
| **SSH** | phpseclib |
| **Auth** | Laravel Sanctum |

### Docker Services

| Service | Purpose |
|---------|---------|
| `app` | PHP-FPM application server |
| `nginx` | Web server and reverse proxy |
| `mysql` | Database server |
| `redis` | Cache and queue backend |
| `queue` | Background job processor |

### Project Structure

```
shipyard/
├── backend/                 # Laravel API
│   ├── app/
│   │   ├── Http/Controllers/Api/   # API controllers
│   │   ├── Models/                 # Eloquent models
│   │   ├── Services/               # Business logic
│   │   └── Jobs/                   # Background jobs
│   ├── database/migrations/        # Database migrations
│   └── routes/api.php              # API routes
├── frontend/                # React application
│   ├── src/
│   │   ├── pages/                  # Page components
│   │   ├── components/             # Reusable components
│   │   └── services/               # API client
│   └── package.json
├── docker/                  # Docker configuration
│   ├── Dockerfile
│   └── nginx/default.conf
├── docker-compose.yml
├── install.sh               # Installation script
└── uninstall.sh             # Uninstallation script
```

---

## Development

### Running Locally

```bash
# Start all services
docker compose up -d

# Frontend development (with hot reload) - inside Docker
docker compose exec app bash -c "cd /var/www/frontend && npm run dev"

# Or if you have Node.js locally installed
cd frontend && npm run dev

# View backend logs
docker compose logs -f app

# View queue worker logs
docker compose logs -f queue
```

### Running Tests

```bash
# Backend tests
docker compose exec app php artisan test

# Frontend tests (inside Docker)
docker compose exec app bash -c "cd /var/www/frontend && npm test"
```

### Useful Commands

```bash
# Access Laravel shell
docker compose exec app php artisan tinker

# Run migrations
docker compose exec app php artisan migrate

# Clear caches
docker compose exec app php artisan optimize:clear

# Restart queue workers
docker compose exec app php artisan queue:restart
```

---

## API Reference

### Authentication

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/auth/login` | POST | Login and get token |
| `/api/auth/logout` | POST | Logout and invalidate token |
| `/api/auth/user` | GET | Get current user |

### Servers

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/servers` | GET | List all servers |
| `/api/servers` | POST | Create a server |
| `/api/servers/{id}` | GET | Get server details |
| `/api/servers/{id}` | PUT | Update a server |
| `/api/servers/{id}` | DELETE | Delete a server |
| `/api/servers/{id}/test-connection` | POST | Test SSH connection |

### Applications

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/applications` | GET | List all applications |
| `/api/applications` | POST | Create an application |
| `/api/applications/{id}` | GET | Get application details |
| `/api/applications/{id}` | PUT | Update an application |
| `/api/applications/{id}` | DELETE | Delete an application |
| `/api/applications/{id}/deploy` | POST | Trigger deployment |
| `/api/applications/{id}/setup-ssl` | POST | Setup SSL certificate |

### Environment Variables

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/applications/{id}/env` | GET | List variables |
| `/api/applications/{id}/env` | POST | Create variable |
| `/api/applications/{id}/env/{key}` | PUT | Update variable |
| `/api/applications/{id}/env/{key}` | DELETE | Delete variable |

### Webhooks

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/webhook/github/{app-id}` | POST | GitHub webhook |
| `/api/webhook/gitlab/{app-id}` | POST | GitLab webhook |
| `/api/webhook/bitbucket/{app-id}` | POST | Bitbucket webhook |

---

## Security

- **SSH Keys** - Encrypted at rest using Laravel's encryption
- **Environment Variables** - Stored encrypted in the database
- **API Authentication** - Token-based auth via Laravel Sanctum
- **Webhook Protection** - Secret token validation
- **Database Isolation** - MySQL and Redis only accessible internally

---

## Troubleshooting

### Container won't start

```bash
# Check container logs
docker compose logs app

# Verify .env file exists
ls -la .env backend/.env
```

### Database connection failed

```bash
# Wait for MySQL to be ready
docker compose logs -f mysql

# Test connection
docker compose exec app php artisan db:show
```

### Frontend not loading

```bash
# Rebuild frontend
cd frontend && npm run build

# Check if dist exists
ls frontend/dist/
```

### Deployment fails

1. Check the deployment logs in the UI
2. Verify SSH connection: **Servers** → **Test Connection**
3. Check server has required software installed

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with passion for developers who deploy their own servers
</p>
