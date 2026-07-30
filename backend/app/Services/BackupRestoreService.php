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
     * Headroom for every step that is NOT given its own explicit timeout and
     * so falls back to SSHService::execute()'s 300s per-command default: the
     * touch/chmod before upload (push()), describeDatabase, the safety-dump
     * directory's mkdir/chmod (safetyDump()), PostgreSQL's separate
     * pg_terminate_backend before the actual drop, dropDatabase itself,
     * createDatabase, applyDatabaseAttributes, the post-restore table-count
     * verification, pruneSafetyDumps, and the final cleanup rm -f. That is up
     * to ten SSH round trips sharing this one budget, not the seven an
     * earlier version of this comment counted.
     *
     * None of them are individually slow in the common case, but none of
     * them hit their own 300s ceiling either if they are merely slow rather
     * than hung (DROP DATABASE waiting on a lock, pg_terminate_backend
     * against many open connections, an ls -1t over a poorly-pruned dump
     * directory), so a merely-slow command still counts fully against this
     * shared total instead of failing on its own and being caught by the
     * service's own try/catch. Budgeted at roughly 60s per round trip across
     * up to ten of them, rather than the flat 300s this constant used to
     * carry regardless of how many steps it actually had to cover.
     */
    public const OVERHEAD_TIMEOUT = 600;

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
            $run->appendUnknownStateNote();
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
        $localPath = Storage::disk(BackupRun::UPLOAD_DISK)->path($run->upload_path);

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

        // The directory needs sudo to create under /var, but the dump itself is
        // written by a plain shell redirect running as the SSH user, so that
        // user must own the directory or the redirect fails with "Permission
        // denied" before pg_dump produces a byte. Creating it root-owned 700
        // and leaving the write unelevated is the bug this replaces. 700 on a
        // user-owned directory still keeps the dumps private, and it means no
        // file operation here (write, list, prune) needs sudo afterwards.
        $dir = escapeshellarg(self::SAFETY_DUMP_DIR);
        $this->ssh->execute(
            "sudo mkdir -p {$dir}"
            .' && sudo chown "$(id -un)":"$(id -gn)" '.$dir
            ." && sudo chmod 700 {$dir}"
        );

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
            // No sudo: safetyDump() chowns the directory to the connecting user,
            // so that user owns every dump in it.
            $this->ssh->execute(
                "ls -1t {$pattern} 2>/dev/null | tail -n +".(self::SAFETY_DUMPS_KEPT + 1)
                .' | xargs -r rm -f'
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
            Storage::disk(BackupRun::UPLOAD_DISK)->delete($run->upload_path);
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
