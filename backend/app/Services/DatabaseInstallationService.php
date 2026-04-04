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
        $installation->appendLog("Verifying operating system...");

        $result = $this->sshService->execute('cat /etc/os-release 2>/dev/null', 30);
        if (!$result['success']) {
            throw new RuntimeException('Could not detect operating system. Only Ubuntu/Debian is supported.');
        }

        $output = strtolower($result['output']);
        if (!str_contains($output, 'ubuntu') && !str_contains($output, 'debian')) {
            throw new RuntimeException('Unsupported operating system. Only Ubuntu/Debian is supported.');
        }

        $installation->appendLog("Operating system verified (Ubuntu/Debian).");
    }

    private function installMySQL(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog("Updating package lists...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog("Installing MySQL server...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server', 600);

        $installation->appendLog("Enabling and starting MySQL service...");
        $this->runCommand($installation, 'sudo systemctl enable mysql && sudo systemctl start mysql', 60);

        $installation->appendLog("Configuring MySQL root user authentication...");
        $escapedPassword = str_replace("'", "'\\''", $password);
        // MySQL 8.0 on Ubuntu/Debian: use debian-sys-maint credentials to connect,
        // which are auto-generated during installation and stored in /etc/mysql/debian.cnf
        $alterCmd = "sudo mysql --defaults-file=/etc/mysql/debian.cnf --execute=\"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '{$escapedPassword}'; FLUSH PRIVILEGES;\"";
        $this->runCommand($installation, $alterCmd, 30);

        $installation->appendLog("Verifying MySQL service is active...");
        $result = $this->sshService->execute('sudo systemctl is-active mysql', 15);
        if (!$result['success'] || trim($result['output']) !== 'active') {
            throw new RuntimeException('MySQL service is not running after installation.');
        }

        // Detect installed version
        $versionResult = $this->sshService->execute('mysql --version 2>/dev/null', 15);
        if ($versionResult['success']) {
            $installation->update(['version_installed' => trim($versionResult['output'])]);
        }

        $installation->appendLog("MySQL is running and configured.");
    }

    private function installPostgreSQL(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog("Updating package lists...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog("Installing PostgreSQL...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y postgresql postgresql-contrib', 600);

        $installation->appendLog("Enabling and starting PostgreSQL service...");
        $this->runCommand($installation, 'sudo systemctl enable postgresql && sudo systemctl start postgresql', 60);

        $installation->appendLog("Setting postgres user password...");
        $escapedPassword = str_replace("'", "'\\''", $password);
        $alterCmd = "sudo -u postgres psql -c \"ALTER USER postgres WITH PASSWORD '{$escapedPassword}';\"";
        $this->runCommand($installation, $alterCmd, 30);

        $installation->appendLog("Configuring pg_hba.conf for password authentication...");
        // Find pg_hba.conf location
        $findResult = $this->sshService->execute("sudo -u postgres psql -t -c 'SHOW hba_file;'", 15);
        $hbaFile = trim($findResult['output']);
        if (empty($hbaFile)) {
            // Fallback: find it
            $findResult = $this->sshService->execute('sudo find /etc/postgresql -name pg_hba.conf 2>/dev/null | head -1', 15);
            $hbaFile = trim($findResult['output']);
        }

        if (!empty($hbaFile)) {
            // Replace peer with md5 for local connections
            $sedCmd = "sudo sed -i 's/local\\s\\+all\\s\\+all\\s\\+peer/local   all             all                                     md5/' {$hbaFile}";
            $this->runCommand($installation, $sedCmd, 15);

            $installation->appendLog("Reloading PostgreSQL configuration...");
            $this->runCommand($installation, 'sudo systemctl reload postgresql', 30);
        } else {
            $installation->appendLog("WARNING: Could not find pg_hba.conf. Password authentication may not work for local connections.");
        }

        $installation->appendLog("Verifying PostgreSQL service is active...");
        $result = $this->sshService->execute('sudo systemctl is-active postgresql', 15);
        if (!$result['success'] || trim($result['output']) !== 'active') {
            throw new RuntimeException('PostgreSQL service is not running after installation.');
        }

        // Detect installed version
        $versionResult = $this->sshService->execute('psql --version 2>/dev/null', 15);
        if ($versionResult['success']) {
            $installation->update(['version_installed' => trim($versionResult['output'])]);
        }

        $installation->appendLog("PostgreSQL is running and configured.");
    }

    private function installPm2(DatabaseInstallation $installation): void
    {
        $nvmPrefix = $this->nvmPrefix();

        // Check if nvm is already installed
        $installation->appendLog("Checking if nvm is installed...");
        $nvmCheck = $this->sshService->execute($nvmPrefix . 'command -v nvm 2>/dev/null', 15);

        if (!$nvmCheck['success'] || empty(trim($nvmCheck['output']))) {
            // Install nvm
            $installation->appendLog("nvm not found. Installing nvm...");
            $this->runCommand(
                $installation,
                'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash',
                120
            );
            $installation->appendLog("nvm installed successfully.");
        } else {
            $installation->appendLog("nvm is already installed.");
        }

        // Check if node is available via nvm
        $installation->appendLog("Checking if Node.js is available...");
        $nodeCheck = $this->sshService->execute($nvmPrefix . 'node --version 2>/dev/null', 15);

        if (!$nodeCheck['success'] || empty(trim($nodeCheck['output']))) {
            $installation->appendLog("Node.js not found. Installing Node.js LTS via nvm...");
            $this->runCommand($installation, $nvmPrefix . 'nvm install --lts', 120);
            $installation->appendLog("Node.js LTS installed successfully.");
        } else {
            $installation->appendLog("Node.js found: " . trim($nodeCheck['output']));
        }

        $installation->appendLog("Installing pm2 globally via npm...");
        $this->runCommand($installation, $nvmPrefix . 'npm install -g pm2', 300);

        $installation->appendLog("Configuring pm2 startup service...");
        $startupResult = $this->sshService->execute(
            $nvmPrefix . 'sudo env PATH=$PATH:$(dirname $(which node)) pm2 startup systemd -u $(whoami) --hp $HOME 2>&1',
            60
        );
        if (!empty(trim($startupResult['output']))) {
            $installation->appendLog($startupResult['output']);
        }

        $installation->appendLog("Verifying pm2 installation...");
        $versionResult = $this->sshService->execute($nvmPrefix . 'pm2 --version 2>/dev/null', 15);
        if (!$versionResult['success'] || empty(trim($versionResult['output']))) {
            throw new RuntimeException('pm2 installation verification failed.');
        }

        $version = trim($versionResult['output']);
        $installation->update(['version_installed' => "pm2 v{$version}"]);
        $installation->appendLog("pm2 v{$version} installed and configured.");
    }

    private function installPhp(DatabaseInstallation $installation): void
    {
        // 1. Add Ondrej's PPA (latest PHP versions)
        $installation->appendLog("Adding PHP repository...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y software-properties-common', 120);
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php', 60);

        // 2. Update and install PHP packages
        $installation->appendLog("Updating package lists...");
        $this->runCommand($installation, 'sudo DEBIAN_FRONTEND=noninteractive apt-get update -y', 120);

        $installation->appendLog("Installing PHP and extensions...");
        $packages = 'php php-fpm php-cli php-mysql php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath php-gd php-intl php-redis';
        $this->runCommand($installation, "sudo DEBIAN_FRONTEND=noninteractive apt-get install -y {$packages}", 600);

        // 3. Detect installed PHP version and enable FPM service
        $installation->appendLog("Detecting PHP version...");
        $versionResult = $this->sshService->execute('php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;"', 15);
        $phpVersion = trim($versionResult['output']);
        if (empty($phpVersion)) {
            $phpVersion = '8.3'; // Fallback
        }

        $installation->appendLog("Enabling and starting php{$phpVersion}-fpm service...");
        $this->runCommand($installation, "sudo systemctl enable php{$phpVersion}-fpm && sudo systemctl start php{$phpVersion}-fpm", 60);

        // 4. Install Composer
        $installation->appendLog("Installing Composer...");
        $this->runCommand($installation, 'curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php', 60);
        $this->runCommand($installation, 'sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer', 60);
        $this->runCommand($installation, 'rm /tmp/composer-setup.php', 15);

        // 5. Verify installations
        $installation->appendLog("Verifying PHP installation...");
        $phpVersionResult = $this->sshService->execute('php --version 2>/dev/null | head -1', 15);
        if (!$phpVersionResult['success'] || empty(trim($phpVersionResult['output']))) {
            throw new RuntimeException('PHP installation verification failed.');
        }

        $installation->appendLog("Verifying Composer installation...");
        $composerVersionResult = $this->sshService->execute('composer --version 2>/dev/null | head -1', 15);
        if (!$composerVersionResult['success'] || empty(trim($composerVersionResult['output']))) {
            throw new RuntimeException('Composer installation verification failed.');
        }

        $installation->appendLog("Verifying php{$phpVersion}-fpm service is active...");
        $fpmResult = $this->sshService->execute("sudo systemctl is-active php{$phpVersion}-fpm", 15);
        if (!$fpmResult['success'] || trim($fpmResult['output']) !== 'active') {
            throw new RuntimeException("PHP-FPM service is not running after installation.");
        }

        // Store version info
        $phpVersionShort = trim($phpVersionResult['output']);
        $composerVersion = trim($composerVersionResult['output']);
        $installation->update(['version_installed' => "{$phpVersionShort} + {$composerVersion}"]);
        $installation->appendLog("PHP and Composer installed successfully.");
    }

    private function nvmPrefix(): string
    {
        return 'export NVM_DIR="$HOME/.nvm" && [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"; [ -s "/usr/local/nvm/nvm.sh" ] && \. "/usr/local/nvm/nvm.sh" 2>/dev/null; ';
    }

    private function runCommand(DatabaseInstallation $installation, string $command, int $timeout = 300): array
    {
        $result = $this->sshService->execute($command, $timeout);

        if (!empty(trim($result['output']))) {
            $installation->appendLog($result['output']);
        }

        if (!$result['success']) {
            throw new RuntimeException("Command failed (exit code {$result['exit_code']}): {$command}");
        }

        return $result;
    }

    private function createDatabaseRecord(DatabaseInstallation $installation, string $password): void
    {
        $installation->appendLog("Creating database connection record...");

        $isMySQL = $installation->engine === 'mysql';

        Database::create([
            'server_id' => $installation->server_id,
            'name' => $isMySQL ? 'MySQL' : 'PostgreSQL',
            'type' => $installation->engine,
            'host' => 'localhost',
            'port' => $isMySQL ? 3306 : 5432,
            'admin_user' => $isMySQL ? 'root' : 'postgres',
            'admin_password' => $password,
            'status' => 'active',
        ]);

        $installation->appendLog("Database connection record created.");
    }
}
