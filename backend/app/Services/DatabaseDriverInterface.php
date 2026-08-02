<?php

namespace App\Services;

use App\Models\Database;

interface DatabaseDriverInterface
{
    /**
     * List all databases on the server.
     */
    public function listDatabases(SSHService $ssh, Database $database): array;

    /**
     * Create a new database.
     */
    public function createDatabase(SSHService $ssh, Database $database, string $name, ?string $charset = null, ?string $collation = null): bool;

    /**
     * Drop a database.
     */
    public function dropDatabase(SSHService $ssh, Database $database, string $name): bool;

    /**
     * List all users on the database server.
     */
    public function listUsers(SSHService $ssh, Database $database): array;

    /**
     * Create a new database user.
     */
    public function createUser(SSHService $ssh, Database $database, string $username, string $password, string $host): bool;

    /**
     * Drop a database user.
     */
    public function dropUser(SSHService $ssh, Database $database, string $username, string $host): bool;

    /**
     * Grant privileges to a user.
     */
    public function grantPrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool;

    /**
     * Revoke privileges from a user.
     */
    public function revokePrivileges(SSHService $ssh, Database $database, string $username, string $host, string $dbName, array $privileges): bool;

    /**
     * Get user privileges.
     */
    public function getUserPrivileges(SSHService $ssh, Database $database, string $username, string $host): array;

    /**
     * Test the database connection.
     */
    public function testConnection(SSHService $ssh, Database $database): array;

    /**
     * Get system databases that should be excluded from listings.
     */
    public function getSystemDatabases(): array;

    /**
     * Shell command that loads a dump file on the server into $dbName.
     * Must abort on the first SQL error rather than continuing.
     */
    public function buildRestoreCommand(Database $database, string $dbName, string $dumpPath, bool $gzipped): string;

    /**
     * Shell command that writes a gzipped dump of $dbName to $outputPath.
     */
    public function buildDumpCommand(Database $database, string $dbName, string $outputPath): string;

    /**
     * Current attributes of an existing database, so a recreate can match it.
     *
     * @return array{owner: ?string, charset: ?string, collation: ?string}
     */
    public function describeDatabase(SSHService $ssh, Database $database, string $dbName): array;

    /**
     * Apply attributes that createDatabase() cannot take, such as ownership.
     *
     * @param  array{owner: ?string, charset: ?string, collation: ?string}  $attributes
     */
    public function applyDatabaseAttributes(SSHService $ssh, Database $database, string $dbName, array $attributes): void;

    /**
     * Shell command that counts the tables in $dbName and returns a bare
     * value, used for post-restore verification. The dialect-specific query
     * (pg_tables vs information_schema.tables) and its escaping live here,
     * not in the caller, matching every other engine-specific detail in this
     * interface.
     */
    public function buildTableCountCommand(Database $database, string $dbName): string;
}
