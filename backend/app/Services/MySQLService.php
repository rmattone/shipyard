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

        if (! $result['success']) {
            throw new RuntimeException('Failed to list databases: '.$result['output']);
        }

        $databases = array_filter(
            array_map('trim', explode("\n", trim($result['output']))),
            fn ($db) => ! empty($db) && $db !== 'Database' && ! in_array($db, $this->systemDatabases)
        );

        return array_values($databases);
    }

    public function createDatabase(SSHService $ssh, Database $database, string $name, ?string $charset = null, ?string $collation = null): bool
    {
        $charset = $charset ?? $database->charset ?? 'utf8mb4';
        $collation = $collation ?? $database->collation ?? 'utf8mb4_unicode_ci';

        $sql = sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
            $this->escapeName($name),
            $charset,
            $collation
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to create database: '.$result['output']);
        }

        return true;
    }

    public function dropDatabase(SSHService $ssh, Database $database, string $name): bool
    {
        if (in_array(strtolower($name), $this->systemDatabases)) {
            throw new RuntimeException('Cannot drop system database');
        }

        $sql = sprintf('DROP DATABASE IF EXISTS `%s`', $this->escapeName($name));
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to drop database: '.$result['output']);
        }

        return true;
    }

    public function listUsers(SSHService $ssh, Database $database): array
    {
        $sql = "SELECT User, Host FROM mysql.user WHERE User NOT IN ('mysql.sys', 'mysql.session', 'mysql.infoschema', 'root', 'debian-sys-maint')";
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to list users: '.$result['output']);
        }

        $lines = array_filter(explode("\n", trim($result['output'])));
        $users = [];

        foreach ($lines as $line) {
            // mysql -N batch output is tab-separated (no header); splitting
            // on arbitrary whitespace truncated usernames containing spaces
            $parts = explode("\t", trim($line, "\r\n"));
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

        if (! $result['success']) {
            throw new RuntimeException('Failed to create user: '.$result['output']);
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

        if (! $result['success']) {
            throw new RuntimeException('Failed to drop user: '.$result['output']);
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

        $command = $this->buildCommand($database, $sql.'; FLUSH PRIVILEGES');
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to grant privileges: '.$result['output']);
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

        $command = $this->buildCommand($database, $sql.'; FLUSH PRIVILEGES');
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to revoke privileges: '.$result['output']);
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

        if (! $result['success']) {
            throw new RuntimeException('Failed to get user privileges: '.$result['output']);
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

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => 'Connection failed: '.$result['output'],
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
        // Escape characters that bash interprets inside double quotes:
        // " (quote delimiter), ` (command substitution), $ (variable expansion), \ (escape char)
        $escapedSql = addcslashes($sql, '"`$\\');

        // The password goes through MYSQL_PWD as a properly quoted shell
        // argument: the old -p'...' with addcslashes wrote \' inside single
        // quotes, which bash does not unescape, breaking any password
        // containing a quote. The env var also avoids mysql's password-on-
        // command-line warning on stderr, which lets us capture stderr
        // (2>&1) so failures carry their reason instead of an empty string.
        return sprintf(
            'MYSQL_PWD=%s mysql -h %s -P %d -u %s -N -e "%s" 2>&1',
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            $escapedSql
        );
    }

    public function buildRestoreCommand(Database $database, string $dbName, string $dumpPath, bool $gzipped): string
    {
        $reader = $gzipped
            ? 'gunzip -c '.escapeshellarg($dumpPath)
            : 'cat '.escapeshellarg($dumpPath);

        // The mysql client aborts on the first error unless --force is given,
        // which is the behaviour a restore needs.
        $pipeline = sprintf(
            '%s | MYSQL_PWD=%s mysql -h %s -P %d -u %s %s 2>&1',
            $reader,
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName)
        );

        return $this->withPipefail($pipeline);
    }

    public function buildDumpCommand(Database $database, string $dbName, string $outputPath): string
    {
        $pipeline = sprintf(
            'MYSQL_PWD=%s mysqldump -h %s -P %d -u %s --single-transaction --routines --triggers %s | gzip > %s',
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName),
            escapeshellarg($outputPath)
        );

        return $this->withPipefail($pipeline);
    }

    public function describeDatabase(SSHService $ssh, Database $database, string $dbName): array
    {
        $sql = sprintf(
            'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA '
            ."WHERE SCHEMA_NAME = '%s'",
            $this->escapeString($dbName)
        );

        $result = $ssh->execute($this->buildCommand($database, $sql));
        $parts = preg_split('/\s+/', trim($result['output'])) ?: [];

        return [
            'owner' => null,
            'charset' => $parts[0] ?? null,
            'collation' => $parts[1] ?? null,
        ];
    }

    public function applyDatabaseAttributes(SSHService $ssh, Database $database, string $dbName, array $attributes): void
    {
        // MySQL has no database owner. Charset and collation are applied by
        // createDatabase(), so there is nothing left to do here.
    }

    public function buildRestoreVerifyCommand(Database $database, string $dbName, string $sql): string
    {
        // The verification query names its own schema, so there is no need to
        // select a default database the way the PostgreSQL driver does.
        return $this->buildCommand($database, $sql);
    }

    /**
     * Wrap a piped command so a failure upstream of the last stage (e.g. a
     * failing mysqldump piped into a succeeding gzip) is not masked by the
     * shell reporting only the last command's exit status. bash is used
     * explicitly because `set -o pipefail` is not POSIX and phpseclib's
     * exec() does not guarantee bash as the default shell.
     */
    protected function withPipefail(string $pipeline): string
    {
        return 'bash -c '.escapeshellarg('set -o pipefail; '.$pipeline);
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
