<?php

namespace App\Services;

use App\Models\Database;
use App\Models\Server;
use RuntimeException;

class DatabaseService
{
    private ?SSHService $ssh = null;

    public function __construct(
        private MySQLService $mysqlService,
        private PostgreSQLService $postgresqlService,
        private SSHService $sshService
    ) {}

    /**
     * Detect installed database servers on a server.
     */
    public function detectDatabaseServers(Server $server): array
    {
        $detected = [];

        try {
            $this->connect($server);

            // Check for MySQL/MariaDB
            $mysqlCheck = $this->ssh->execute('which mysql 2>/dev/null || command -v mysql 2>/dev/null');
            if ($mysqlCheck['success'] && !empty(trim($mysqlCheck['output']))) {
                $versionResult = $this->ssh->execute('mysql --version 2>/dev/null');
                $detected['mysql'] = [
                    'installed' => true,
                    'path' => trim($mysqlCheck['output']),
                    'version' => trim($versionResult['output'] ?? ''),
                    'default_port' => 3306,
                ];
            }

            // Check for PostgreSQL
            $pgsqlCheck = $this->ssh->execute('which psql 2>/dev/null || command -v psql 2>/dev/null');
            if ($pgsqlCheck['success'] && !empty(trim($pgsqlCheck['output']))) {
                $versionResult = $this->ssh->execute('psql --version 2>/dev/null');
                $detected['postgresql'] = [
                    'installed' => true,
                    'path' => trim($pgsqlCheck['output']),
                    'version' => trim($versionResult['output'] ?? ''),
                    'default_port' => 5432,
                ];
            }

            $this->disconnect();
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }

        return $detected;
    }

    /**
     * Test database connection.
     */
    public function testConnection(Database $database): array
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->testConnection($this->ssh, $database);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * List databases on the server.
     */
    public function listRemoteDatabases(Database $database): array
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $databases = $driver->listDatabases($this->ssh, $database);
            $this->disconnect();
            return $databases;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Create a new database.
     */
    public function createDatabase(Database $database, string $name, ?string $charset = null, ?string $collation = null): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->createDatabase($this->ssh, $database, $name, $charset, $collation);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Drop a database.
     */
    public function dropDatabase(Database $database, string $name): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->dropDatabase($this->ssh, $database, $name);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * List users on the database server.
     */
    public function listRemoteUsers(Database $database): array
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $users = $driver->listUsers($this->ssh, $database);
            $this->disconnect();
            return $users;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Create a new database user.
     */
    public function createUser(Database $database, string $username, string $password, string $host): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->createUser($this->ssh, $database, $username, $password, $host);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Drop a database user.
     */
    public function dropUser(Database $database, string $username, string $host): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->dropUser($this->ssh, $database, $username, $host);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Grant privileges to a user.
     */
    public function grantPrivileges(Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->grantPrivileges($this->ssh, $database, $username, $host, $dbName, $privileges);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Revoke privileges from a user.
     */
    public function revokePrivileges(Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $result = $driver->revokePrivileges($this->ssh, $database, $username, $host, $dbName, $privileges);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Get user privileges.
     */
    public function getUserPrivileges(Database $database, string $username, string $host): array
    {
        try {
            $this->connect($database->server);
            $driver = $this->getDriver($database);
            $privileges = $driver->getUserPrivileges($this->ssh, $database, $username, $host);
            $this->disconnect();
            return $privileges;
        } catch (\Exception $e) {
            $this->disconnect();
            throw $e;
        }
    }

    /**
     * Get the appropriate driver for the database type.
     */
    protected function getDriver(Database $database): DatabaseDriverInterface
    {
        return match ($database->type) {
            'mysql' => $this->mysqlService,
            'postgresql' => $this->postgresqlService,
            default => throw new RuntimeException("Unsupported database type: {$database->type}"),
        };
    }

    /**
     * Connect to the server via SSH.
     */
    protected function connect(Server $server): void
    {
        $this->ssh = $this->sshService;
        $this->ssh->connect($server);
    }

    /**
     * Disconnect from the server.
     */
    protected function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }
}
