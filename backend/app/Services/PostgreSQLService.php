<?php

namespace App\Services;

use App\Models\Database;
use App\Services\Concerns\WrapsShellPipeline;
use RuntimeException;

class PostgreSQLService implements DatabaseDriverInterface
{
    use WrapsShellPipeline;

    protected array $systemDatabases = [
        'postgres',
        'template0',
        'template1',
    ];

    public function getSystemDatabases(): array
    {
        return $this->systemDatabases;
    }

    public function listDatabases(SSHService $ssh, Database $database): array
    {
        $sql = 'SELECT datname FROM pg_database WHERE datistemplate = false';
        $command = $this->buildCommand($database, $sql, true);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to list databases: '.$result['output']);
        }

        $databases = array_filter(
            array_map('trim', explode("\n", trim($result['output']))),
            fn ($db) => ! empty($db) && ! in_array($db, $this->systemDatabases)
        );

        return array_values($databases);
    }

    public function createDatabase(SSHService $ssh, Database $database, string $name, ?string $charset = null, ?string $collation = null): bool
    {
        $encoding = $charset ?? $database->charset ?? 'UTF8';
        $lcCollate = $collation ?? $database->collation ?? 'en_US.UTF-8';

        $sql = sprintf(
            "CREATE DATABASE \"%s\" ENCODING '%s' LC_COLLATE '%s' LC_CTYPE '%s' TEMPLATE template0",
            $this->escapeName($name),
            $encoding,
            $lcCollate,
            $lcCollate
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success'] && ! str_contains($result['output'], 'already exists')) {
            throw new RuntimeException('Failed to create database: '.$result['output']);
        }

        return true;
    }

    public function dropDatabase(SSHService $ssh, Database $database, string $name): bool
    {
        if (in_array(strtolower($name), $this->systemDatabases)) {
            throw new RuntimeException('Cannot drop system database');
        }

        // First, terminate active connections to the database
        $terminateSql = sprintf(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '%s' AND pid <> pg_backend_pid()",
            $this->escapeString($name)
        );
        $ssh->execute($this->buildCommand($database, $terminateSql));

        $sql = sprintf('DROP DATABASE IF EXISTS "%s"', $this->escapeName($name));
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to drop database: '.$result['output']);
        }

        return true;
    }

    public function listUsers(SSHService $ssh, Database $database): array
    {
        $sql = "SELECT usename FROM pg_catalog.pg_user WHERE usename NOT IN ('postgres')";
        $command = $this->buildCommand($database, $sql, true);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to list users: '.$result['output']);
        }

        $lines = array_filter(array_map('trim', explode("\n", trim($result['output']))));
        $users = [];

        foreach ($lines as $line) {
            if (! empty($line)) {
                $users[] = [
                    'username' => $line,
                    'host' => '*',
                ];
            }
        }

        return $users;
    }

    public function createUser(SSHService $ssh, Database $database, string $username, string $password, string $host): bool
    {
        $sql = sprintf(
            "CREATE USER \"%s\" WITH PASSWORD '%s'",
            $this->escapeName($username),
            $this->escapeString($password)
        );

        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success'] && ! str_contains($result['output'], 'already exists')) {
            throw new RuntimeException('Failed to create user: '.$result['output']);
        }

        return true;
    }

    public function dropUser(SSHService $ssh, Database $database, string $username, string $host): bool
    {
        $sql = sprintf('DROP USER IF EXISTS "%s"', $this->escapeName($username));
        $command = $this->buildCommand($database, $sql);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            throw new RuntimeException('Failed to drop user: '.$result['output']);
        }

        return true;
    }

    public function grantPrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        $commands = [];

        if (in_array('ALL', array_map('strtoupper', $privileges))) {
            $commands[] = sprintf(
                'GRANT ALL PRIVILEGES ON DATABASE "%s" TO "%s"',
                $this->escapeName($dbName),
                $this->escapeName($username)
            );
            // PostgreSQL 15+ revoked public CREATE on schema public by default
            $commands[] = sprintf(
                'GRANT ALL ON SCHEMA public TO "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO "%s"',
                $this->escapeName($username)
            );
            // Laravel apps fail on their first INSERT without sequence
            // privileges (permission denied for sequence xxx_id_seq)
            $commands[] = sprintf(
                'GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO "%s"',
                $this->escapeName($username)
            );
            // Tables/sequences created later (migrations) must be covered too
            $commands[] = sprintf(
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON SEQUENCES TO "%s"',
                $this->escapeName($username)
            );
        } else {
            foreach ($privileges as $privilege) {
                $privilege = strtoupper($privilege);
                if (in_array($privilege, ['CONNECT', 'CREATE', 'TEMPORARY', 'TEMP'])) {
                    $commands[] = sprintf(
                        'GRANT %s ON DATABASE "%s" TO "%s"',
                        $privilege,
                        $this->escapeName($dbName),
                        $this->escapeName($username)
                    );
                    // PostgreSQL 15+: database-level CREATE only allows creating schemas,
                    // not objects within existing schemas — schema-level grant is also needed
                    if ($privilege === 'CREATE') {
                        $commands[] = sprintf(
                            'GRANT CREATE ON SCHEMA public TO "%s"',
                            $this->escapeName($username)
                        );
                    }
                } else {
                    $commands[] = sprintf(
                        'GRANT %s ON ALL TABLES IN SCHEMA public TO "%s"',
                        $privilege,
                        $this->escapeName($username)
                    );
                }
            }
        }

        foreach ($commands as $sql) {
            $command = $this->buildCommand($database, $sql, false, $dbName);
            $result = $ssh->execute($command);

            if (! $result['success']) {
                throw new RuntimeException('Failed to grant privileges: '.$result['output']);
            }
        }

        return true;
    }

    public function revokePrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool
    {
        $commands = [];

        if (in_array('ALL', array_map('strtoupper', $privileges))) {
            $commands[] = sprintf(
                'REVOKE ALL PRIVILEGES ON DATABASE "%s" FROM "%s"',
                $this->escapeName($dbName),
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'REVOKE ALL ON SCHEMA public FROM "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL PRIVILEGES ON TABLES FROM "%s"',
                $this->escapeName($username)
            );
            $commands[] = sprintf(
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL PRIVILEGES ON SEQUENCES FROM "%s"',
                $this->escapeName($username)
            );
        } else {
            foreach ($privileges as $privilege) {
                $privilege = strtoupper($privilege);
                if (in_array($privilege, ['CONNECT', 'CREATE', 'TEMPORARY', 'TEMP'])) {
                    $commands[] = sprintf(
                        'REVOKE %s ON DATABASE "%s" FROM "%s"',
                        $privilege,
                        $this->escapeName($dbName),
                        $this->escapeName($username)
                    );
                    if ($privilege === 'CREATE') {
                        $commands[] = sprintf(
                            'REVOKE CREATE ON SCHEMA public FROM "%s"',
                            $this->escapeName($username)
                        );
                    }
                } else {
                    $commands[] = sprintf(
                        'REVOKE %s ON ALL TABLES IN SCHEMA public FROM "%s"',
                        $privilege,
                        $this->escapeName($username)
                    );
                }
            }
        }

        foreach ($commands as $sql) {
            $command = $this->buildCommand($database, $sql, false, $dbName);
            $result = $ssh->execute($command);

            if (! $result['success']) {
                throw new RuntimeException('Failed to revoke privileges: '.$result['output']);
            }
        }

        return true;
    }

    public function getUserPrivileges(SSHService $ssh, Database $database, string $username, string $host): array
    {
        $sql = sprintf(
            "SELECT datname, array_agg(privilege_type) as privileges FROM (
                SELECT d.datname, p.privilege_type
                FROM pg_database d
                CROSS JOIN (
                    SELECT 'CONNECT' as privilege_type UNION ALL
                    SELECT 'CREATE' UNION ALL
                    SELECT 'TEMPORARY'
                ) p
                WHERE has_database_privilege('%s', d.datname, p.privilege_type)
                AND d.datname NOT IN ('template0', 'template1')
            ) sub
            GROUP BY datname",
            $this->escapeString($username)
        );

        $command = $this->buildCommand($database, $sql, true);
        $result = $ssh->execute($command);

        if (! $result['success']) {
            return [];
        }

        $privileges = [];
        $lines = array_filter(explode("\n", trim($result['output'])));

        foreach ($lines as $line) {
            $parts = explode('|', $line);
            if (count($parts) === 2) {
                $dbName = trim($parts[0]);
                $privs = trim($parts[1], '{}');
                $privileges[] = [
                    'database' => $dbName,
                    'privileges' => array_map('trim', explode(',', $privs)),
                ];
            }
        }

        return $privileges;
    }

    public function testConnection(SSHService $ssh, Database $database): array
    {
        try {
            $sql = 'SELECT version()';
            $command = $this->buildCommand($database, $sql, true);
            $result = $ssh->execute($command);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => 'Connection failed: '.$result['output'],
                ];
            }

            return [
                'success' => true,
                'message' => 'Connection successful',
                'version' => trim($result['output']),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function buildCommand(Database $database, string $sql, bool $tupleOnly = false, ?string $dbName = null): string
    {
        $targetDb = $dbName ?? 'postgres';
        $options = $tupleOnly ? '-t -A' : '';

        // Escape characters that bash interprets inside double quotes:
        // " (quote delimiter), ` (command substitution), $ (variable expansion), \ (escape char)
        $escapedSql = addcslashes($sql, '"`$\\');

        // escapeshellarg instead of addcslashes: \' inside bash single quotes
        // is not unescaped, so passwords containing a quote used to produce a
        // malformed command.
        return sprintf(
            'PGPASSWORD=%s psql -h %s -p %d -U %s -d %s %s -c "%s"',
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($targetDb),
            $options,
            $escapedSql
        );
    }

    public function buildRestoreCommand(Database $database, string $dbName, string $dumpPath, bool $gzipped): string
    {
        $reader = $gzipped
            ? 'gunzip -c '.escapeshellarg($dumpPath)
            : 'cat '.escapeshellarg($dumpPath);

        // ON_ERROR_STOP=1 is what turns a broken dump into a failed restore
        // instead of a silently half-loaded database. The trailing 2>&1
        // that used to sit here is now redundant: withPipefail() wraps the
        // whole pipeline in a `{ ...; } 2>&1` group that covers it.
        $pipeline = sprintf(
            '%s | PGPASSWORD=%s psql -h %s -p %d -U %s -d %s -v ON_ERROR_STOP=1 -q',
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
            'PGPASSWORD=%s pg_dump -h %s -p %d -U %s %s | gzip > %s',
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
            'SELECT pg_get_userbyid(datdba), pg_encoding_to_char(encoding), datcollate '
            ."FROM pg_database WHERE datname = '%s'",
            $this->escapeString($dbName)
        );

        $result = $ssh->execute($this->buildCommand($database, $sql, true));

        if (! $result['success']) {
            throw new RuntimeException('Failed to describe database: '.$result['output']);
        }

        // A blank/missing field parses to '' via explode()/trim(), not the
        // documented ?string null, so normalize it before returning.
        $parts = array_map(
            fn ($part) => $part === '' ? null : $part,
            array_map('trim', explode('|', trim($result['output'])))
        );

        return [
            'owner' => $parts[0] ?? null,
            'charset' => $parts[1] ?? null,
            'collation' => $parts[2] ?? null,
        ];
    }

    public function applyDatabaseAttributes(SSHService $ssh, Database $database, string $dbName, array $attributes): void
    {
        if (empty($attributes['owner'])) {
            return;
        }

        $sql = sprintf(
            'ALTER DATABASE "%s" OWNER TO "%s"',
            $this->escapeName($dbName),
            $this->escapeName($attributes['owner'])
        );

        $result = $ssh->execute($this->buildCommand($database, $sql));

        if (! $result['success']) {
            throw new RuntimeException('Failed to set database owner: '.$result['output']);
        }
    }

    public function buildTableCountCommand(Database $database, string $dbName): string
    {
        $sql = "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'";

        // buildCommand's existing signature is
        // (Database, string $sql, bool $tupleOnly = false, ?string $dbName = null)
        // at PostgreSQLService.php:357, so this asks for bare values against the
        // restored database rather than the default postgres database.
        return $this->buildCommand($database, $sql, true, $dbName);
    }

    protected function escapeName(string $name): string
    {
        return str_replace('"', '""', $name);
    }

    protected function escapeString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
