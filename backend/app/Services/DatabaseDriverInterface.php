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
}
