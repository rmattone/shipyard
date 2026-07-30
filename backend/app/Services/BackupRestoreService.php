<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
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

    /**
     * Per-command timeouts, public so ProcessDatabaseRestore's own $timeout
     * can be derived from them instead of carrying an independently chosen
     * number that can drift out of sync with what this service actually
     * takes. On the overwrite path these two run sequentially (safety dump,
     * then load), so the job timeout must cover their sum, not either one
     * alone.
     */
    public const SAFETY_DUMP_TIMEOUT = 900;

    public const LOAD_TIMEOUT = 1500;

    /**
     * Headroom for the remaining steps, none of which are given an explicit
     * timeout and so fall back to SSHService::execute()'s 300s default:
     * describe, drop, create, apply attributes, verify, prune, cleanup.
     */
    public const OVERHEAD_TIMEOUT = 300;

    /**
     * The job timeout must cover the whole sequence, not any single command.
     */
    public const MAX_RESTORE_SECONDS = self::SAFETY_DUMP_TIMEOUT + self::LOAD_TIMEOUT + self::OVERHEAD_TIMEOUT;

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

        if (! $run->claim()) {
            // Lost the race: some other delivery of this job already moved the
            // run past pending (most likely still running it right now). This
            // must be a silent no-op, not a second destructive restore. The
            // log column is a read-modify-write that the winner is actively
            // appending to, so it is deliberately left untouched here; the
            // logger is the only record of the duplicate delivery.
            Log::warning("BackupRun {$run->id}: claim lost, refusing to start a second restore.");

            return;
        }

        $run->appendLog("Restoring {$run->original_filename} into {$target} on {$server->name}.");

        // Tracked explicitly rather than inferred afterwards, so failed_step
        // stays truthful. Inferring it from safety_dump_path would label every
        // failure on the restore-to-a-new-name path as a dump failure, since
        // that path never takes a safety dump.
        $step = 'upload';

        try {
            $this->ssh->connect($server);

            $run->appendLog('Uploading the dump to the server...');
            $this->push($run, $server, $remotePath);
            $run->appendLog('Dump uploaded to '.$remotePath.'.');

            $attributes = ['owner' => null, 'charset' => null, 'collation' => null];

            if ($overwrite) {
                $step = 'describe';
                $attributes = $driver->describeDatabase($this->ssh, $database, $target);
                $run->appendLog('Existing database attributes: '.json_encode($attributes));

                $step = 'dump';
                $safetyPath = $this->safetyDump($run, $driver, $target);
                $run->update(['safety_dump_path' => $safetyPath]);

                // Load bearing: labels a dropDatabase() failure below as a
                // restore failure rather than leaving it attributed to 'dump'.
                $step = 'restore';
                $run->appendLog("Dropping {$target}...");
                $driver->dropDatabase($this->ssh, $database, $target);
            }

            // Load bearing even though it looks redundant with the assignment
            // above: when $overwrite is false, the whole block above never
            // runs, so this is the only place $step gets set to 'restore' on
            // that path. Collapsing the two into one assignment would leave
            // a non-overwrite failure here mislabelled with whatever $step
            // was last set to ('upload').
            $step = 'restore';
            $run->appendLog("Creating {$target}...");
            $driver->createDatabase(
                $this->ssh, $database, $target, $attributes['charset'], $attributes['collation']
            );
            $driver->applyDatabaseAttributes($this->ssh, $database, $target, $attributes);

            $run->appendLog('Loading the dump. This is the slow part.');
            $result = $this->ssh->execute(
                $driver->buildRestoreCommand($database, $target, $remotePath, $gzipped),
                self::LOAD_TIMEOUT
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
                // Deliberately neutral rather than "may be partially loaded":
                // that phrasing is only true for a failure during the load
                // itself. If dropDatabase() succeeded but createDatabase()
                // then failed, the target does not exist at all; if
                // applyDatabaseAttributes() failed, it exists and is empty.
                // "Unknown state" is honest on every path that reaches here,
                // and the safety dump path is what actually matters for
                // recovery regardless of which of those it was.
                $run->appendLog(
                    'The target database is now in an unknown state. The pre-restore dump is at '
                    ."{$run->safety_dump_path} on the server."
                );
            }

            $run->markAsFailed($step);
        } finally {
            $this->cleanup($run, $remotePath);

            try {
                $this->ssh->disconnect();
            } catch (Throwable $e) {
                // The run row is already correctly marked and logged above;
                // a disconnect failure on an already-dead connection must not
                // surface as an unhandled exception out of the job.
            }
        }
    }

    private function push(BackupRun $run, Server $server, string $remotePath): void
    {
        $localPath = Storage::disk('local')->path($run->upload_path);

        // Create the file with owner-only permissions before writing to it:
        // SFTP put reuses the existing inode and keeps its mode, and a dump
        // is as sensitive as the data it contains.
        $quoted = escapeshellarg($remotePath);
        $this->ssh->execute("touch {$quoted} && chmod 600 {$quoted}");

        $this->ssh->connectSftp($server);

        if (! $this->ssh->upload($localPath, $remotePath)) {
            throw new RuntimeException('Failed to upload the dump to the server.');
        }

        $this->ssh->connect($server);
    }

    private function safetyDump(BackupRun $run, DatabaseDriverInterface $driver, string $target): string
    {
        $path = self::SAFETY_DUMP_DIR."/{$target}-".now()->format('Ymd-His').'.sql.gz';

        $run->appendLog("Taking a safety dump to {$path}...");

        $this->ssh->execute('sudo mkdir -p '.escapeshellarg(self::SAFETY_DUMP_DIR)
            .' && sudo chmod 700 '.escapeshellarg(self::SAFETY_DUMP_DIR));

        $result = $this->ssh->execute(
            $driver->buildDumpCommand($run->database, $target, $path),
            self::SAFETY_DUMP_TIMEOUT
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
     *
     * The dialect-specific query lives on the driver (buildTableCountCommand),
     * not here: this method only executes it and parses the number.
     */
    private function verify(BackupRun $run, Database $database, string $target): void
    {
        $driver = $this->driverFor($database);

        $result = $this->ssh->execute($driver->buildTableCountCommand($database, $target));
        $output = trim($result['output'] ?? '');

        // Anchored at the start only, not both ends: MySQLService::buildCommand()
        // appends 2>&1, so a client warning (e.g. a version banner containing
        // digits) can trail the count in $output. Noise after the number is
        // harmless because the real count is still what's parsed; noise
        // BEFORE it is the dangerous case, since it would let something like a
        // version number be misread as the table count and mask a genuinely
        // empty restore as a success. Start-anchoring rejects that loudly
        // instead of silently parsing the wrong digits.
        if (! $result['success'] || ! preg_match('/^\d+/', $output, $matches)) {
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
