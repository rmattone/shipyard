<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\Database;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Restores an uploaded dump into a database on a managed server.
 *
 * Steps run as discrete SSH calls rather than one bundled remote script, so
 * each one can append to the run log while it happens. A bundled script only
 * returns output at the end, which would leave the user watching a dead panel
 * for minutes.
 */
class BackupRestoreService
{
    private const SAFETY_DUMP_DIR = '/var/backups/shipyard';

    private const SAFETY_DUMPS_KEPT = 3;

    public function __construct(
        private SSHService $ssh,
        private MySQLService $mysqlService,
        private PostgreSQLService $postgresqlService,
    ) {}

    public function restoreFromUpload(BackupRun $run, bool $overwrite): void
    {
        $database = $run->database;
        $server = $database->server;
        $driver = $this->driverFor($database);
        $target = $run->database_name;
        $gzipped = $run->format === BackupRun::FORMAT_SQL_GZ;
        $remotePath = '/var/tmp/shipyard-restore-'.$run->id.($gzipped ? '.sql.gz' : '.sql');

        $run->markAsRunning();
        $run->appendLog("Restoring {$run->original_filename} into {$target} on {$server->name}.");

        // Tracked explicitly rather than inferred afterwards, so failed_step
        // stays truthful. Inferring it from safety_dump_path would label every
        // failure on the restore-to-a-new-name path as a dump failure, since
        // that path never takes a safety dump.
        $step = 'upload';

        try {
            $this->ssh->connect($server);

            $run->appendLog('Uploading the dump to the server...');
            $this->push($run, $remotePath);
            $run->appendLog('Dump uploaded to '.$remotePath.'.');

            $attributes = ['owner' => null, 'charset' => null, 'collation' => null];

            if ($overwrite) {
                $attributes = $driver->describeDatabase($this->ssh, $database, $target);
                $run->appendLog('Existing database attributes: '.json_encode($attributes));

                $step = 'dump';
                $safetyPath = $this->safetyDump($run, $driver, $target);
                $run->update(['safety_dump_path' => $safetyPath]);

                $step = 'restore';
                $run->appendLog("Dropping {$target}...");
                $driver->dropDatabase($this->ssh, $database, $target);
            }

            $step = 'restore';
            $run->appendLog("Creating {$target}...");
            $driver->createDatabase(
                $this->ssh, $database, $target, $attributes['charset'], $attributes['collation']
            );
            $driver->applyDatabaseAttributes($this->ssh, $database, $target, $attributes);

            $run->appendLog('Loading the dump. This is the slow part.');
            $result = $this->ssh->execute(
                $driver->buildRestoreCommand($database, $target, $remotePath, $gzipped),
                1500
            );

            if (! $result['success']) {
                throw new RuntimeException($result['output'] ?: 'the load command failed without output');
            }

            if (! empty(trim($result['output']))) {
                $run->appendLog($result['output']);
            }

            $this->verify($run, $database, $target);
            $this->pruneSafetyDumps($target);

            $run->appendLog('Restore completed successfully.');
            $run->markAsSuccess();
        } catch (Throwable $e) {
            $run->appendLog('ERROR: '.$e->getMessage());

            if ($run->safety_dump_path) {
                $run->appendLog(
                    'The target database may be partially loaded. The pre-restore dump is at '
                    ."{$run->safety_dump_path} on the server."
                );
            }

            $run->markAsFailed($step);
        } finally {
            $this->cleanup($run, $remotePath);
            $this->ssh->disconnect();
        }
    }

    private function push(BackupRun $run, string $remotePath): void
    {
        $localPath = Storage::disk('local')->path($run->upload_path);

        // Create the file with owner-only permissions before writing to it:
        // SFTP put reuses the existing inode and keeps its mode, and a dump
        // is as sensitive as the data it contains.
        $quoted = escapeshellarg($remotePath);
        $this->ssh->execute("touch {$quoted} && chmod 600 {$quoted}");

        $this->ssh->connectSftp($run->database->server);

        if (! $this->ssh->upload($localPath, $remotePath)) {
            throw new RuntimeException('Failed to upload the dump to the server.');
        }

        $this->ssh->connect($run->database->server);
    }

    private function safetyDump(BackupRun $run, DatabaseDriverInterface $driver, string $target): string
    {
        $path = self::SAFETY_DUMP_DIR."/{$target}-".now()->format('Ymd-His').'.sql.gz';

        $run->appendLog("Taking a safety dump to {$path}...");

        $this->ssh->execute('sudo mkdir -p '.escapeshellarg(self::SAFETY_DUMP_DIR)
            .' && sudo chmod 700 '.escapeshellarg(self::SAFETY_DUMP_DIR));

        $result = $this->ssh->execute(
            $driver->buildDumpCommand($run->database, $target, $path),
            900
        );

        if (! $result['success']) {
            throw new RuntimeException('Safety dump failed, refusing to continue: '.$result['output']);
        }

        $run->appendLog('Safety dump written.');

        return $path;
    }

    /**
     * Assert the restore actually produced something. A zero exit code is not
     * proof: an empty or truncated dump piped into psql or mysql succeeds
     * having created nothing, and because the target was already dropped by
     * then, trusting the exit code would report a silently emptied database as
     * a successful restore.
     */
    private function verify(BackupRun $run, Database $database, string $target): void
    {
        $driver = $this->driverFor($database);

        $sql = $database->isPostgreSQL()
            ? "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'"
            : sprintf(
                "SELECT count(*) FROM information_schema.tables WHERE table_schema = '%s'",
                str_replace("'", "''", $target)
            );

        $result = $this->ssh->execute($driver->buildRestoreVerifyCommand($database, $target, $sql));
        $output = trim($result['output'] ?? '');

        if (! $result['success'] || ! preg_match('/\d+/', $output, $matches)) {
            throw new RuntimeException('Could not verify the restored database: '.($output ?: 'no output'));
        }

        $tables = (int) $matches[0];

        if ($tables === 0) {
            throw new RuntimeException(
                'The dump loaded without error but produced no tables, so the target is now empty. '
                .'The dump was most likely empty or truncated.'
            );
        }

        $run->appendLog("Verified: {$tables} tables in the restored database.");
    }

    private function pruneSafetyDumps(string $target): void
    {
        $pattern = escapeshellarg(self::SAFETY_DUMP_DIR."/{$target}-*.sql.gz");

        // Keep the newest N and delete the rest. Failure here is not fatal:
        // tidiness must never fail a successful restore, so a dead connection
        // or a permission error here is swallowed rather than left to
        // propagate into the outer catch and mark an otherwise-successful
        // restore as failed.
        try {
            $this->ssh->execute(
                "sudo ls -1t {$pattern} 2>/dev/null | tail -n +".(self::SAFETY_DUMPS_KEPT + 1)
                .' | xargs -r sudo rm -f'
            );
        } catch (Throwable $e) {
            // Not fatal; see above.
        }
    }

    private function cleanup(BackupRun $run, string $remotePath): void
    {
        try {
            $this->ssh->execute('rm -f '.escapeshellarg($remotePath));
        } catch (Throwable $e) {
            // The server may already be unreachable; the host-side delete below
            // is the one that actually matters for disk usage.
        }

        if ($run->upload_path) {
            Storage::disk('local')->delete($run->upload_path);
        }
    }

    private function driverFor($database): DatabaseDriverInterface
    {
        return match ($database->type) {
            'mysql' => $this->mysqlService,
            'postgresql' => $this->postgresqlService,
            default => throw new RuntimeException("Unsupported database type: {$database->type}"),
        };
    }
}
