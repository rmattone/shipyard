<?php

namespace App\Services;

use App\Models\Database;
use App\Models\DatabaseInstallation;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseInstallationService
{
    public function __construct(
        private SSHService $sshService
    ) {}

    public function install(DatabaseInstallation $installation): void
    {
        $installation->markAsRunning();
        $installation->appendLog("Starting {$installation->engine} installation...");

        try {
            $this->sshService->connect($installation->server);

            $this->verifyDistro($installation);

            if ($installation->engine === 'pm2') {
                $this->installPm2($installation);
            } elseif ($installation->engine === 'php') {
                $this->installPhp($installation);
            } elseif ($installation->engine === 'node') {
                $this->installNode($installation);
            } elseif ($installation->engine === 'nginx') {
                $this->installNginx($installation);
            } elseif ($installation->engine === 'certbot') {
                $this->installCertbot($installation);
            } else {
                $password = Str::random(32);

                if ($installation->engine === 'mysql') {
                    $this->installMySQL($installation, $password);
                } else {
                    $this->installPostgreSQL($installation, $password);
                }

                // Store the generated password
                $installation->update(['admin_password' => $password]);

                // Auto-create Database connection record
                $this->createDatabaseRecord($installation, $password);
            }

            $installation->appendLog("{$installation->engine} installation completed successfully.");
            $installation->markAsSuccess();
        } catch (\Exception $e) {
            $installation->appendLog("ERROR: {$e->getMessage()}");
            $installation->markAsFailed();
            throw $e;
        } finally {
            $this->sshService->disconnect();
        }
    }

    private function verifyDistro(DatabaseInstallation $installation): void
    {
        $installation->appendLog('Verifying operating system...');

        $result = $this->sshService->execute('cat /etc/os-release 2>/dev/null', 30);
        if (! $result['success']) {
            throw new RuntimeException('Could not detect operating system. Only Ubuntu/Debian is supported.');
        }

        $output = strtolower($result['output']);
        if (! str_contains($output, 'ubuntu') && ! str_contains($output, 'debian')) {
            throw new RuntimeException('Unsupported operating system. Only Ubuntu/Debian is supported.');
        }

        $installation->appendLog('Operating system verified (Ubuntu/Debian).');
    }

    private function installMySQL(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog('Updating package lists...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog('Installing MySQL server...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server', 600);

        $installation->appendLog('Enabling and starting MySQL service...');
        $this->runCommand($installation, 'sudo systemctl enable mysql && sudo systemctl start mysql', 60);

        $installation->appendLog('Configuring MySQL root user authentication...');
        // MySQL 8.0 on Ubuntu/Debian: use debian-sys-maint credentials to connect,
        // which are auto-generated during installation and stored in /etc/mysql/debian.cnf.
        // The SQL travels base64-encoded so the password only needs SQL-string
        // escaping (the old shell-inside-SQL-inside-shell layering was only
        // safe because the password happened to be alphanumeric).
        $sqlPassword = str_replace(['\\', "'"], ['\\\\', "''"], $password);
        // caching_sha2_password: the MySQL 8 default; mysql_native_password is
        // deprecated and removed in 8.4+ (breaks once Ubuntu ships it)
        $sql = "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '{$sqlPassword}'; FLUSH PRIVILEGES;";
        $alterCmd = sprintf(
            'echo %s | base64 -d | sudo mysql --defaults-file=/etc/mysql/debian.cnf',
            escapeshellarg(base64_encode($sql))
        );
        $this->runCommand($installation, $alterCmd, 30);

        $installation->appendLog('Verifying MySQL service is active...');
        $result = $this->sshService->execute('sudo systemctl is-active mysql', 15);
        if (! $result['success'] || trim($result['output']) !== 'active') {
            throw new RuntimeException('MySQL service is not running after installation.');
        }

        // Detect installed version
        $versionResult = $this->sshService->execute('mysql --version 2>/dev/null', 15);
        if ($versionResult['success']) {
            $installation->update(['version_installed' => trim($versionResult['output'])]);
        }

        $installation->appendLog('MySQL is running and configured.');
    }

    private function installPostgreSQL(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog('Updating package lists...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog('Installing PostgreSQL...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql postgresql-contrib', 600);

        $installation->appendLog('Enabling and starting PostgreSQL service...');
        $this->runCommand($installation, 'sudo systemctl enable postgresql && sudo systemctl start postgresql', 60);

        $installation->appendLog('Setting postgres user password...');
        // base64-piped SQL keeps quoting layers separate (see installMySQL)
        $sqlPassword = str_replace("'", "''", $password);
        $sql = "ALTER USER postgres WITH PASSWORD '{$sqlPassword}';";
        $alterCmd = sprintf(
            'echo %s | base64 -d | sudo -u postgres psql',
            escapeshellarg(base64_encode($sql))
        );
        $this->runCommand($installation, $alterCmd, 30);

        $installation->appendLog('Configuring pg_hba.conf for password authentication...');
        // Find pg_hba.conf location
        $findResult = $this->sshService->execute("sudo -u postgres psql -t -c 'SHOW hba_file;'", 15);
        $hbaFile = trim($findResult['output']);
        if (empty($hbaFile)) {
            // Fallback: find it
            $findResult = $this->sshService->execute('sudo find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1', 15);
            $hbaFile = trim($findResult['output']);
        }

        if (! empty($hbaFile)) {
            // Replace peer with md5 for local connections
            $sedCmd = "sudo sed -i 's/local\\s\\+all\\s\\+all\\s\\+peer/local   all             all                                     md5/' {$hbaFile}";
            $this->runCommand($installation, $sedCmd, 15);

            $installation->appendLog('Reloading PostgreSQL configuration...');
            $this->runCommand($installation, 'sudo systemctl reload postgresql', 30);
        } else {
            $installation->appendLog('WARNING: Could not find pg_hba.conf. Password authentication may not work for local connections.');
        }

        $installation->appendLog('Verifying PostgreSQL service is active...');
        $result = $this->sshService->execute('sudo systemctl is-active postgresql', 15);
        if (! $result['success'] || trim($result['output']) !== 'active') {
            throw new RuntimeException('PostgreSQL service is not running after installation.');
        }

        // Detect installed version
        $versionResult = $this->sshService->execute('psql --version 2>/dev/null', 15);
        if ($versionResult['success']) {
            $installation->update(['version_installed' => trim($versionResult['output'])]);
        }

        $installation->appendLog('PostgreSQL is running and configured.');
    }

    private function installPm2(DatabaseInstallation $installation): void
    {
        $nvmPrefix = $this->nvmPrefix();

        // Check if nvm is already installed
        $installation->appendLog('Checking if nvm is installed...');
        $nvmCheck = $this->sshService->execute($nvmPrefix.'command -v nvm 2>/dev/null', 15);

        if (! $nvmCheck['success'] || empty(trim($nvmCheck['output']))) {
            // Install nvm
            $installation->appendLog('nvm not found. Installing nvm...');
            $this->runCommand(
                $installation,
                'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash',
                120
            );
            $installation->appendLog('nvm installed successfully.');
        } else {
            $installation->appendLog('nvm is already installed.');
        }

        // Check if node is available via nvm
        $installation->appendLog('Checking if Node.js is available...');
        $nodeCheck = $this->sshService->execute($nvmPrefix.'node --version 2>/dev/null', 15);

        if (! $nodeCheck['success'] || empty(trim($nodeCheck['output']))) {
            $installation->appendLog('Node.js not found. Installing Node.js LTS via nvm...');
            $this->runCommand($installation, $nvmPrefix.'nvm install --lts', 120);
            $installation->appendLog('Node.js LTS installed successfully.');
        } else {
            $installation->appendLog('Node.js found: '.trim($nodeCheck['output']));
        }

        $installation->appendLog('Installing pm2 globally via npm...');
        $this->runCommand($installation, $nvmPrefix.'npm install -g pm2', 300);

        $installation->appendLog('Configuring pm2 startup service...');
        $startupResult = $this->sshService->execute(
            $nvmPrefix.'sudo env PATH=$PATH:$(dirname $(which node)) pm2 startup systemd -u $(whoami) --hp $HOME 2>&1',
            60
        );
        if (! empty(trim($startupResult['output']))) {
            $installation->appendLog($startupResult['output']);
        }

        $installation->appendLog('Verifying pm2 installation...');
        $versionResult = $this->sshService->execute($nvmPrefix.'pm2 --version 2>/dev/null', 15);
        // pm2's first run prints its banner and daemon spawn messages before
        // the version, so pull the last bare-semver line instead of the raw
        // output (which overflows the version_installed column)
        preg_match_all('/^\s*(\d+\.\d+\.\d+)\s*$/m', $versionResult['output'] ?? '', $matches);
        if (! $versionResult['success'] || empty($matches[1])) {
            throw new RuntimeException('pm2 installation verification failed.');
        }

        $version = end($matches[1]);
        $installation->update(['version_installed' => "pm2 v{$version}"]);
        $installation->appendLog("pm2 v{$version} installed and configured.");
    }

    private function installPhp(DatabaseInstallation $installation): void
    {
        // 1. Add Ondrej's PPA (latest PHP versions)
        $installation->appendLog('Adding PHP repository...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common', 120);

        // The PPA lags new Ubuntu releases; adding it for an unpublished
        // series makes apt update 404 and kills the install, so probe its
        // Release file first and fall back to the distro's own PHP packages
        $probe = $this->sshService->execute(
            '. /etc/os-release && curl -fsI --max-time 15 "https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/${VERSION_CODENAME}/Release" >/dev/null 2>&1 && echo available || echo unavailable',
            30
        );

        if (trim($probe['output']) === 'available') {
            $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php', 180);
        } else {
            $installation->appendLog('The ondrej/php PPA is not published for this release yet. Using distro PHP packages.');
        }

        // 2. Update and install PHP packages
        $installation->appendLog('Updating package lists...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        // Apache has to be blocked before apt resolves a single PHP package.
        // The phpX.Y metapackage depends on
        // "libapache2-mod-phpX.Y | phpX.Y-fpm | phpX.Y-cgi" and apt takes the
        // first alternative, so installing it pulls apache2 in even when
        // php-fpm is on the same command line. apache2 then wins the race for
        // port 80 on the next reboot and nginx dies with EADDRINUSE.
        $this->blockApache($installation);

        // 3. Resolve the concrete version, then install only versioned
        // packages. Installing unversioned metapackages and asking the server
        // afterwards is what let sury's php-redis drag a second, FPM-less PHP
        // series onto a box provisioned for 8.4.
        $phpVersion = $this->resolvePhpVersion($installation);

        $installation->appendLog("Installing PHP {$phpVersion} and extensions...");
        $packages = $this->phpPackages($phpVersion);
        $this->runCommand($installation, "sudo DEBIAN_FRONTEND=noninteractive apt-get install -y {$packages}", 600);

        $installation->appendLog("Enabling and starting php{$phpVersion}-fpm service...");
        $this->runCommand($installation, "sudo systemctl enable php{$phpVersion}-fpm && sudo systemctl start php{$phpVersion}-fpm", 60);

        // Record the installed version so nginx configs template the right
        // PHP-FPM socket path for this server
        $installation->server->update(['php_version' => $phpVersion]);

        // 4. Install Composer
        $installation->appendLog('Installing Composer...');
        $this->runCommand($installation, 'curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php', 60);
        $this->runCommand($installation, 'sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer', 60);
        $this->runCommand($installation, 'rm /tmp/composer-setup.php', 15);

        // 5. Verify installations
        $installation->appendLog('Verifying PHP installation...');
        $phpVersionResult = $this->sshService->execute('php --version 2>/dev/null | head -1', 15);
        if (! $phpVersionResult['success'] || empty(trim($phpVersionResult['output']))) {
            throw new RuntimeException('PHP installation verification failed.');
        }

        // A pre-existing PHP on the box can outrank ours in update-alternatives,
        // so /usr/bin/php may not be the series we just installed. Not fatal
        // (vhosts, daemons and scheduled tasks all use the versioned binary and
        // socket) but worth surfacing, since it silently changes what a bare
        // "php" runs in a deploy hook.
        $defaultCli = $this->sshService->execute('php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"', 15);
        $defaultCliVersion = trim($defaultCli['output']);
        if ($defaultCliVersion !== '' && $defaultCliVersion !== $phpVersion) {
            $installation->appendLog(
                "WARNING: the default `php` on this host is {$defaultCliVersion}, not the {$phpVersion} ShipYard installed. ".
                "Deploy hooks that call a bare `php` will run {$defaultCliVersion}."
            );
        }

        $installation->appendLog('Verifying Composer installation...');
        $composerVersionResult = $this->sshService->execute('composer --version 2>/dev/null | head -1', 15);
        if (! $composerVersionResult['success'] || empty(trim($composerVersionResult['output']))) {
            throw new RuntimeException('Composer installation verification failed.');
        }

        $installation->appendLog("Verifying php{$phpVersion}-fpm service is active...");
        $fpmResult = $this->sshService->execute("sudo systemctl is-active php{$phpVersion}-fpm", 15);
        if (! $fpmResult['success'] || trim($fpmResult['output']) !== 'active') {
            throw new RuntimeException('PHP-FPM service is not running after installation.');
        }

        // Store version info
        $phpVersionShort = trim($phpVersionResult['output']);
        $composerVersion = trim($composerVersionResult['output']);
        $installation->update(['version_installed' => "{$phpVersionShort} + {$composerVersion}"]);
        $installation->appendLog('PHP and Composer installed successfully.');
    }

    private function installNode(DatabaseInstallation $installation): void
    {
        $nvmPrefix = $this->nvmPrefix();

        // Check if nvm is already installed
        $installation->appendLog('Checking if nvm is installed...');
        $nvmCheck = $this->sshService->execute($nvmPrefix.'command -v nvm 2>/dev/null', 15);

        if (! $nvmCheck['success'] || empty(trim($nvmCheck['output']))) {
            // Install nvm
            $installation->appendLog('nvm not found. Installing nvm...');
            $this->runCommand(
                $installation,
                'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash',
                120
            );
            $installation->appendLog('nvm installed successfully.');
        } else {
            $installation->appendLog('nvm is already installed.');
        }

        // Determine which version to install
        $versionToInstall = $installation->version_requested ?: '--lts';
        $isLts = $versionToInstall === '--lts';

        $installation->appendLog('Installing Node.js '.($isLts ? 'LTS' : "v{$versionToInstall}").' via nvm...');
        $this->runCommand($installation, $nvmPrefix."nvm install {$versionToInstall}", 180);

        // Set as default version
        $installation->appendLog('Setting Node.js as default version...');
        if ($isLts) {
            // For LTS, we need to get the actual version installed and set it as default
            $this->runCommand($installation, $nvmPrefix.'nvm alias default node', 30);
        } else {
            $this->runCommand($installation, $nvmPrefix."nvm alias default {$versionToInstall}", 30);
        }

        // Verify Node.js installation
        $installation->appendLog('Verifying Node.js installation...');
        $nodeVersionResult = $this->sshService->execute($nvmPrefix.'node --version 2>/dev/null', 15);
        if (! $nodeVersionResult['success'] || empty(trim($nodeVersionResult['output']))) {
            throw new RuntimeException('Node.js installation verification failed.');
        }

        // Verify npm installation
        $installation->appendLog('Verifying npm installation...');
        $npmVersionResult = $this->sshService->execute($nvmPrefix.'npm --version 2>/dev/null', 15);
        if (! $npmVersionResult['success'] || empty(trim($npmVersionResult['output']))) {
            throw new RuntimeException('npm installation verification failed.');
        }

        $nodeVersion = trim($nodeVersionResult['output']);
        $npmVersion = trim($npmVersionResult['output']);
        $installation->update(['version_installed' => "Node.js {$nodeVersion} / npm v{$npmVersion}"]);
        $installation->appendLog("Node.js {$nodeVersion} and npm v{$npmVersion} installed successfully.");
    }

    private function installNginx(DatabaseInstallation $installation): void
    {
        $installation->appendLog('Updating package lists...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog('Installing nginx...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nginx', 300);

        $installation->appendLog('Enabling and starting nginx service...');
        $this->runCommand($installation, 'sudo systemctl enable nginx && sudo systemctl start nginx', 60);

        $installation->appendLog('Verifying nginx service is active...');
        $result = $this->sshService->execute('sudo systemctl is-active nginx', 15);
        if (! $result['success'] || trim($result['output']) !== 'active') {
            throw new RuntimeException('nginx service is not running after installation.');
        }

        // "active" alone is not enough: another web server can already hold
        // port 80, leaving nginx running but beaten to the socket on the next
        // boot. Check who actually owns the port.
        $this->assertNginxOwnsPortEighty($installation);

        // Detect installed version (nginx outputs to stderr)
        $versionResult = $this->sshService->execute('nginx -v 2>&1', 15);
        if ($versionResult['success']) {
            $installation->update(['version_installed' => trim($versionResult['output'])]);
        }

        $installation->appendLog('nginx is running and configured.');
    }

    private function installCertbot(DatabaseInstallation $installation): void
    {
        $installation->appendLog('Updating package lists...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog('Installing certbot and nginx plugin...');
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx', 300);

        $installation->appendLog('Verifying certbot installation...');
        $versionResult = $this->sshService->execute('certbot --version 2>/dev/null', 15);
        if (! $versionResult['success'] || empty(trim($versionResult['output']))) {
            throw new RuntimeException('Certbot installation verification failed.');
        }

        $version = trim($versionResult['output']);
        $installation->update(['version_installed' => $version]);
        $installation->appendLog("Certbot installed successfully: {$version}");
    }

    /**
     * Ask apt which concrete PHP series the php-fpm metapackage points at,
     * without installing anything. This reproduces the version apt would have
     * chosen on its own, so the resolved number is deterministic and known
     * before a single package lands.
     */
    private function resolvePhpVersion(DatabaseInstallation $installation): string
    {
        $installation->appendLog('Resolving the default PHP version from the configured apt sources...');

        $result = $this->sshService->execute(
            "apt-cache depends php-fpm 2>/dev/null | grep -oE 'php[0-9]+\.[0-9]+-fpm' | head -1 | grep -oE '[0-9]+\.[0-9]+'",
            30
        );

        $version = trim($result['output']);

        if (! preg_match('/^\d+\.\d+$/', $version)) {
            throw new RuntimeException('Could not determine which PHP version to install from the configured apt sources.');
        }

        return $version;
    }

    /**
     * Versioned package names only. The unversioned "php" metapackage pulls
     * libapache2-mod-php, and unversioned extension packages track whatever
     * series is newest in the repo, which is how a box provisioned for 8.4
     * ended up with a half-installed 8.5 (cli but no fpm) beside it.
     */
    private function phpPackages(string $version): string
    {
        $extensions = ['fpm', 'cli', 'mysql', 'pgsql', 'mbstring', 'xml', 'curl', 'zip', 'bcmath', 'gd', 'intl', 'redis'];

        return implode(' ', array_map(fn (string $ext): string => "php{$version}-{$ext}", $extensions));
    }

    /**
     * ShipYard serves every site through nginx, so apache2 on the same host is
     * only ever a port-80 conflict waiting for a reboot.
     */
    private function blockApache(DatabaseInstallation $installation): void
    {
        $installation->appendLog('Pinning apache2 out of apt...');

        // Priority -1 makes apt refuse these outright, so the phpX.Y
        // alternative group falls through to phpX.Y-fpm instead of resolving
        // to libapache2-mod-phpX.Y. base64 keeps the quoting layers separate
        // (see installMySQL).
        $pin = "Package: apache2 apache2-bin apache2-data apache2-utils libapache2-mod-php*\nPin: release *\nPin-Priority: -1\n";
        $this->runCommand($installation, sprintf(
            'echo %s | base64 -d | sudo tee /etc/apt/preferences.d/shipyard-no-apache >/dev/null',
            escapeshellarg(base64_encode($pin))
        ), 30);

        // Never yank a web server that is currently serving traffic. If apache2
        // is live the operator has to make that call, so say so and move on;
        // the nginx install refuses to pass its port-80 check anyway.
        $active = $this->sshService->execute('systemctl is-active apache2 2>/dev/null || true', 15);
        if (trim($active['output']) === 'active') {
            $installation->appendLog(
                'WARNING: apache2 is currently serving on this host, so ShipYard left it running. '.
                'It will fight nginx for port 80 on the next reboot. Purge it before deploying sites.'
            );

            return;
        }

        // Masking survives a reinstall: even if apache2 arrives some other way,
        // its postinst cannot start it and it cannot come back at boot.
        $this->runCommand(
            $installation,
            'sudo systemctl disable --now apache2 >/dev/null 2>&1; sudo systemctl mask apache2 >/dev/null 2>&1; true',
            60
        );

        $installation->appendLog('apache2 is pinned out of apt and masked in systemd.');
    }

    private function assertNginxOwnsPortEighty(DatabaseInstallation $installation): void
    {
        $installation->appendLog('Verifying nginx owns port 80...');

        $result = $this->sshService->execute("sudo ss -ltnp 2>/dev/null | grep -E ':80[[:space:]]' || true", 15);
        $listeners = trim($result['output']);

        if ($listeners === '') {
            throw new RuntimeException('Nothing is listening on port 80 after the nginx install.');
        }

        if (str_contains($listeners, 'apache2')) {
            throw new RuntimeException(
                'apache2 is listening on port 80 and will beat nginx to it on the next reboot. '.
                'Purge apache2 (sudo apt-get purge apache2 apache2-bin apache2-data apache2-utils "libapache2-mod-php*") and re-run this install.'
            );
        }

        if (! str_contains($listeners, 'nginx')) {
            throw new RuntimeException("Port 80 is held by another process instead of nginx: {$listeners}");
        }

        $installation->appendLog('nginx owns port 80.');
    }

    private function nvmPrefix(): string
    {
        return 'export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"; [ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh" 2>/dev/null; ';
    }

    private function runCommand(DatabaseInstallation $installation, string $command, int $timeout = 300): array
    {
        $result = $this->sshService->execute($command, $timeout);

        if (! empty(trim($result['output']))) {
            $installation->appendLog($result['output']);
        }

        if (! $result['success']) {
            throw new RuntimeException("Command failed (exit code {$result['exit_code']}): {$command}");
        }

        return $result;
    }

    private function createDatabaseRecord(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog('Creating database connection record...');

        $isMySQL = $installation->engine === 'mysql';

        // updateOrCreate: the server-side install already succeeded at this
        // point, so an existing record must absorb the fresh credentials
        // instead of tripping the unique key and stranding a stale password
        Database::updateOrCreate(
            [
                'server_id' => $installation->server_id,
                'name' => $isMySQL ? 'MySQL' : 'PostgreSQL',
            ],
            [
                'type' => $installation->engine,
                'host' => 'localhost',
                'port' => $isMySQL ? 3306 : 5432,
                'admin_user' => $isMySQL ? 'root' : 'postgres',
                'admin_password' => $password,
                'status' => 'active',
            ]
        );

        $installation->appendLog('Database connection record created.');
    }
}
