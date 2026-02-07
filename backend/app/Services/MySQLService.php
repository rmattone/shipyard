<?php

namespace App\Services;

use App\Models\Database;
use RuntimeException;

class MySQLService implements DatabaseDriverInterface
{
    protected array $systemDatabases = [
        'information_schema',
        'mysql',
        'performance_schema',
        'sys',
    ];

    public function getSystemDatabases(): array
    {
        return $this->systemDatabases;
    }

    public function listDatabases(SSHService $ssh, Database $database): array
    {
        $command = $this->buildCommand($database, 'SHOW DATABASES');
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to list databases: ' . $result['output']);
        }

        $databases = array_filter(
            array_map('trim', explode("\n", trim($result['output']))),
            fn($db) => !empty($db) && $db !== 'Database' && !in_array($db, $this->systemDatabases)
        );

        return array_values($databases);
    }

    public function createDatabase(SSHService $ssh, Database $database, string $name, ?string $charset = null, ?string $collation = null): bool
    {
        $charset = $charset ?? $database->charset ?? 'utf8mb4';
        $collation = $collation ?? $database->collation ?? 'utf8mb4_unicode_ci';

        $sql = sprintf(
            "CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s",
            $this->escapeName($name),
            $charset,
            $collation
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to create database: ' . $result['output']);
        }

        return true;
    }

    public function dropDatabase(SSHService $ssh, Database $database, string $name): bool
    {
        if (in_array(strtolower($name), $this->systemDatabases)) {
            throw new RuntimeException('Cannot drop system database');
        }

        $sql = sprintf("DROP DATABASE IF EXISTS `%s`", $this->escapeName($name));
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to drop database: ' . $result['output']);
        }

        return true;
    }

    public function listUsers(SSHService $ssh, Database $database): array
    {
        $sql = "SELECT User, Host FROM mysql.user WHERE User NOT IN ('mysql.sys', 'mysql.session', 'mysql.infoschema', 'root', 'debian-sys-maint')";
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to list users: ' . $result['output']);
        }

        $lines = array_filter(explode("\n", trim($result['output'])));
        $users = [];

        foreach ($lines as $line) {
            if (str_contains($line, 'User') && str_contains($line, 'Host')) {
                continue; // Skip header
            }

            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) === 2) {
                $users[] = [
                    'username' => $parts[0],
                    'host' => $parts[1],
                ];
            }
        }

        return $users;
    }

    public function createUser(SSHService $ssh, Database $database, string $username, string $password, string $host): bool
    {
        $sql = sprintf(
            "CREATE USER '%s'@'%s' IDENTIFIED BY '%s'",
            $this->escapeString($username),
            $this->escapeString($host),
            $this->escapeString($password)
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to create user: ' . $result['output']);
        }

        return true;
    }

    public function dropUser(SSHService $ssh, Database $database, string $username, string $host): bool
    {
        $sql = sprintf(
            "DROP USER IF EXISTS '%s'@'%s'",
            $this->escapeString($username),
            $this->escapeString($host)
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to drop user: ' . $result['output']);
        }

        return true;
    }

    public function grantPrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        $privilegeString = $this->formatPrivileges($privileges);
        $dbSpec = $dbName === '*' ? '*.*' : sprintf('`%s`.*', $this->escapeName($dbName));

        $sql = sprintf(
            "GRANT %s ON %s TO '%s'@'%s'",
            $privilegeString,
            $dbSpec,
            $this->escapeString($username),
            $this->escapeString($host)
        );

        $command = $this->buildCommand($database, $sql . '; FLUSH PRIVILEGES');
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to grant privileges: ' . $result['output']);
        }

        return true;
    }

    public function revokePrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        $privilegeString = $this->formatPrivileges($privileges);
        $dbSpec = $dbName === '*' ? '*.*' : sprintf('`%s`.*', $this->escapeName($dbName));

        $sql = sprintf(
            "REVOKE %s ON %s FROM '%s'@'%s'",
            $privilegeString,
            $dbSpec,
            $this->escapeString($username),
            $this->escapeString($host)
        );

        $command = $this->buildCommand($database, $sql . '; FLUSH PRIVILEGES');
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to revoke privileges: ' . $result['output']);
        }

        return true;
    }

    public function getUserPrivileges(SSHService $ssh, Database $database, string $username, string $host): array
    {
        $sql = sprintf(
            "SHOW GRANTS FOR '%s'@'%s'",
            $this->escapeString($username),
            $this->escapeString($host)
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (!$result['success']) {
            throw new RuntimeException('Failed to get user privileges: ' . $result['output']);
        }

        $grants = array_filter(explode("\n", trim($result['output'])));
        $privileges = [];

        foreach ($grants as $grant) {
            if (preg_match('/GRANT (.+?) ON (.+?) TO/', $grant, $matches)) {
                $privs = array_map('trim', explode(',', $matches[1]));
                $database_pattern = trim($matches[2], '`');
                $privileges[] = [
                    'privileges' => $privs,
                    'database' => $database_pattern,
                ];
            }
        }

        return $privileges;
    }

    public function testConnection(SSHService $ssh, Database $database): array
    {
        try {
            $command = $this->buildCommand($database, 'SELECT VERSION() AS version');
            $result = $ssh->execute($command);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => 'Connection failed: ' . $result['output'],
                ];
            }

            $lines = array_filter(explode("\n", trim($result['output'])));
            $version = end($lines);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'version' => $version,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function buildCommand(Database $database, string $sql): string
    {
        $password = addcslashes($database->admin_password, "'\\");

        return sprintf(
            "mysql -h %s -P %d -u %s -p'%s' -N -e \"%s\" 2>/dev/null",
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            $password,
            addcslashes($sql, '"')
        );
    }

    protected function escapeName(string $name): string
    {
        return str_replace('`', '``', $name);
    }

    protected function escapeString(string $value): string
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], $value);
    }

    protected function formatPrivileges(array $privileges): string
    {
        if (in_array('ALL', array_map('strtoupper', $privileges))) {
            return 'ALL PRIVILEGES';
        }

        return implode(', ', array_map('strtoupper', $privileges));
    }
}
