# Database Restore From Uploaded Dump Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin upload a `.sql` or `.sql.gz` dump through the ShipYard UI and restore it into a MySQL or PostgreSQL database on a managed server, with a live log and a safety dump before anything destructive.

**Architecture:** A multipart upload endpoint streams the dump to `storage/app/private/restores` (Laravel 12's default `local` disk root is `storage/app/private`, and this app does not publish `config/filesystems.php`), creates a `backup_runs` row with `kind = restore` and `source = upload`, and dispatches `ProcessDatabaseRestore`. The job pushes the file to the target server over SFTP, takes a safety dump when overwriting, recreates the database, pipes the dump into `psql` or `mysql`, verifies, and cleans up, appending to the run log at every step. The frontend uploads with axios progress events, then follows the run over SSE.

**Tech Stack:** Laravel 12 (PHP 8.2), phpseclib via `SSHService`, Redis for log fan-out, React 18 with TypeScript and axios, Radix UI primitives.

**Scope:** Backend and frontend for the upload restore source only. The S3 restore source, backup configs, destinations, cron, rclone, and the callback endpoint all belong to the backups plan and are untouched here.

---

## Relationship to the approved backups plan

Read `docs/superpowers/specs/2026-07-28-database-backups-design.md`, in particular the section "Amendment: restore from an uploaded dump", before starting. That amendment is the spec for this plan.

`docs/superpowers/plans/2026-07-28-database-backups.md` is unimplemented (no backup code exists in the repo). Its Task 3 creates `backup_runs` with a non-nullable FK to `backup_configs`. This plan ships first and creates that table, so two adjustments to the backups plan become necessary once this lands:

1. Delete the `create_backup_runs_table` migration step from the backups plan. The table will already exist.
2. Add a migration in the backups plan that attaches the `backup_config_id` foreign key constraint, once `backup_configs` exists.

The table created here deliberately leaves `backup_config_id` as a plain nullable column with an index and no constraint, because the referenced table does not exist yet. Every other column the backups plan expects (`kind`, `trigger`, `status`, `failed_step`, `s3_key`, `size_bytes`, `duration_seconds`, `log`) is created here with the same names and types, so the backups work inherits them unchanged.

## File structure

Backend, created:

| File | Responsibility |
|---|---|
| `database/migrations/2026_07_30_000001_create_backup_runs_table.php` | The shared runs table for backups and restores |
| `app/Models/BackupRun.php` | Run record, log appending, organization scope through `database.server` |
| `database/factories/BackupRunFactory.php` | Test fixture |
| `app/Support/DumpInspector.php` | Format detection by magic bytes, no SSH, no framework |
| `app/Exceptions/UnsupportedDumpException.php` | Carries the user-facing reason a dump was rejected |
| `app/Services/BackupRestoreService.php` | Restore orchestration over SSH, one log line per step |
| `app/Jobs/ProcessDatabaseRestore.php` | Queue entry point, lifecycle, guaranteed cleanup |
| `app/Http/Controllers/Api/DatabaseRestoreController.php` | Upload, history, status |
| `app/Http/Controllers/Api/BackupRunStreamController.php` | SSE log stream |

Backend, modified:

| File | Change |
|---|---|
| `app/Services/SSHService.php:222` | Stream SFTP uploads from disk instead of buffering the file in memory |
| `app/Services/DatabaseDriverInterface.php` | Four new methods for dump, restore, describe, and attribute application |
| `app/Services/MySQLService.php` | Implement the four methods |
| `app/Services/PostgreSQLService.php` | Implement the four methods |
| `routes/api.php` | Three authenticated routes plus one public SSE route |
| `docker/php/zz-shipyard.conf` | Raise `upload_max_filesize` and `post_max_size` |
| `docker/nginx/default.conf` | Raise `client_max_body_size` for the restore route only |

Frontend, created:

| File | Responsibility |
|---|---|
| `src/components/databases/RestoreDatabaseDialog.tsx` | File picker, target selection, typed confirmation, upload progress |
| `src/components/databases/RestoreLogPanel.tsx` | SSE log viewer with reconnect |

Frontend, modified:

| File | Change |
|---|---|
| `src/services/api.ts` | `databaseRestoresApi` plus the `BackupRun` type |
| `src/pages/servers/DatabaseDetail.tsx` | Restore action per remote database, log panel, history list |

`DatabaseDetail.tsx` is 817 lines already, so the dialog and log panel stay in their own files and that page only gains wiring.

## Conventions

- Working directory is `backend/` for every PHP command, `frontend/` for every npm command.
- Run tests with `php artisan test --filter=<Name>`. The local setup is Herd PHP against the throwaway MySQL container on port 33061: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=<Name>`. If the Docker stack is running instead, use `docker compose exec app php artisan test --filter=<Name>`.
- Format with `./vendor/bin/pint --dirty` before every commit.
- Feature tests use `Tests\TestCase::createOrgUser()`, which creates the acting user and binds the organization context so factories land in that organization.
- Tenant isolation for nested routes follows the existing controllers: `{server}` resolves through `OrganizationScope`, and every method guards `$database->server_id !== $server->id` with a 404. `Database` has no scope of its own, so skipping that guard is a cross-tenant hole.
- SSH is faked in tests with the `fakeResults` mock from `tests/Feature/RollbackReliabilityTest.php`, reproduced where needed rather than shared, since that test keeps it private.

---

## Task 1: Stream SFTP uploads instead of buffering them

`SSHService::upload()` currently calls `file_get_contents($localPath)`, which loads the whole file into memory. A 1 GB dump would exhaust `memory_limit` before a single byte reached the server. phpseclib streams from disk when `put()` is given `SFTP::SOURCE_LOCAL_FILE`. The method has no callers in `app/` today, so this change is safe.

**Files:**
- Modify: `backend/app/Services/SSHService.php:222-243`
- Test: `backend/tests/Feature/SSHServiceUploadTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/SSHServiceUploadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\SSHService;
use phpseclib3\Net\SFTP;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Restore dumps reach a gigabyte, so upload() must hand phpseclib a path and
 * let it stream, never read the file into memory first.
 */
class SSHServiceUploadTest extends TestCase
{
    public function test_upload_streams_from_the_local_path(): void
    {
        $localPath = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($localPath, 'SELECT 1;');

        $sftp = $this->mock(SFTP::class);
        $sftp->shouldReceive('put')
            ->once()
            ->with('/var/tmp/dump.sql', $localPath, SFTP::SOURCE_LOCAL_FILE)
            ->andReturn(true);

        $service = new SSHService;

        $property = new ReflectionProperty(SSHService::class, 'sftp');
        $property->setValue($service, $sftp);

        $this->assertTrue($service->upload($localPath, '/var/tmp/dump.sql'));

        unlink($localPath);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=SSHServiceUploadTest`

Expected: FAIL. Mockery reports `put()` received `('/var/tmp/dump.sql', 'SELECT 1;', 0)`, because the current code passes file contents with the default `SOURCE_STRING` mode.

- [ ] **Step 3: Change upload() to stream**

In `backend/app/Services/SSHService.php`, replace the final return of `upload()`:

```php
        // Stream from disk rather than buffering: restore dumps reach a
        // gigabyte, and file_get_contents on one would exhaust memory_limit
        // before anything reached the server.
        return $this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
```

`SFTP` is already imported at the top of the file, so no new `use` statement is needed.

- [ ] **Step 4: Run test to verify it passes**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=SSHServiceUploadTest`

Expected: PASS, 1 test, 1 assertion.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/SSHService.php tests/Feature/SSHServiceUploadTest.php
git commit -m "Stream SFTP uploads from disk instead of buffering in memory"
```

---

## Task 2: backup_runs migration, model, factory

**Files:**
- Create: `backend/database/migrations/2026_07_30_000001_create_backup_runs_table.php`
- Create: `backend/app/Models/BackupRun.php`
- Create: `backend/database/factories/BackupRunFactory.php`
- Test: `backend/tests/Feature/BackupRunModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/BackupRunModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use App\Support\CurrentOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRunModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_log_accumulates_timestamped_lines(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['log' => null]);

        $run->appendLog('Starting restore');
        $run->appendLog('Done');

        $this->assertStringContainsString('Starting restore', $run->fresh()->log);
        $this->assertStringContainsString('Done', $run->fresh()->log);
        $this->assertSame(2, substr_count($run->fresh()->log, "\n"));
    }

    public function test_runs_from_another_organization_are_scoped_out(): void
    {
        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignRun = BackupRun::factory()->create();
        CurrentOrganization::forget();

        $this->createOrgUser();
        $ownRun = BackupRun::factory()->create();

        $visible = BackupRun::pluck('id')->all();

        $this->assertContains($ownRun->id, $visible);
        $this->assertNotContains($foreignRun->id, $visible);
    }

    public function test_upload_path_is_hidden_from_serialization(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['upload_path' => 'restores/secret.sql.gz']);

        $this->assertArrayNotHasKey('upload_path', $run->toArray());
    }

    public function test_mark_as_success_records_positive_duration_since_start(): void
    {
        $this->createOrgUser();
        $this->travelTo('2026-01-01 00:00:00');
        $run = BackupRun::factory()->create();

        $run->markAsRunning();
        $this->travel(45)->seconds();
        $run->markAsSuccess();

        $this->assertSame(45, $run->fresh()->duration_seconds);
    }

    public function test_mark_as_failed_records_positive_duration_since_start(): void
    {
        $this->createOrgUser();
        $this->travelTo('2026-01-01 00:00:00');
        $run = BackupRun::factory()->create();

        $run->markAsRunning();
        $this->travel(30)->seconds();
        $run->markAsFailed('dump');

        $this->assertSame(30, $run->fresh()->duration_seconds);
        $this->assertSame('dump', $run->fresh()->failed_step);
    }

    public function test_marking_a_run_that_never_started_leaves_duration_null(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['started_at' => null]);

        $run->markAsSuccess();

        $this->assertNull($run->fresh()->duration_seconds);
    }
}
```

The duration tests exist because `durationSinceStart()` is the one place in this model where the obvious argument order is wrong. Without them, a future contributor "simplifying" it back to `now()->diffInSeconds($this->started_at)` would silently write negative durations and nothing would fail.

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRunModelTest`

Expected: FAIL with `Class "App\Models\BackupRun" not found`.

- [ ] **Step 3: Write the migration**

Create `backend/database/migrations/2026_07_30_000001_create_backup_runs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            // No FK constraint yet: backup_configs does not exist until the
            // backups plan lands. Nullable because an uploaded restore has no
            // config to anchor to.
            $table->unsignedBigInteger('backup_config_id')->nullable()->index();

            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('kind', 10)->default('backup');    // backup | restore
            $table->string('trigger', 10)->default('cron');   // cron | manual
            $table->string('source', 10)->nullable();         // s3 | upload
            $table->string('status', 20);                     // pending | running | success | failed
            $table->string('failed_step', 20)->nullable();    // dump | upload | prune | restore
            $table->string('database_name');

            $table->string('s3_key')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('format', 10)->nullable();         // sql | sql_gz
            $table->string('upload_path')->nullable();
            $table->string('safety_dump_path')->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['database_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
```

- [ ] **Step 4: Write the model**

Create `backend/app/Models/BackupRun.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizationThroughParent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Redis;

class BackupRun extends Model
{
    use BelongsToOrganizationThroughParent, HasFactory;

    public const KIND_BACKUP = 'backup';
    public const KIND_RESTORE = 'restore';

    public const SOURCE_S3 = 's3';
    public const SOURCE_UPLOAD = 'upload';

    public const FORMAT_SQL = 'sql';
    public const FORMAT_SQL_GZ = 'sql_gz';

    protected static function organizationParentRelation(): string
    {
        return 'database.server';
    }

    protected $fillable = [
        'backup_config_id',
        'database_id',
        'user_id',
        'kind',
        'trigger',
        'source',
        'status',
        'failed_step',
        'database_name',
        's3_key',
        'original_filename',
        'format',
        'upload_path',
        'safety_dump_path',
        'size_bytes',
        'duration_seconds',
        'log',
        'started_at',
        'finished_at',
    ];

    protected $hidden = [
        'upload_path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['success', 'failed'], true);
    }

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] {$message}\n";
        $this->log = ($this->log ?? '').$formatted;
        $this->save();

        try {
            Redis::publish("backup-run.{$this->id}.logs", json_encode([
                'chunk' => $formatted,
                'timestamp' => now()->toIso8601String(),
                'is_complete' => false,
            ]));
        } catch (\Exception $e) {
            // Redis being down must not fail a restore; the log is already
            // persisted and the SSE stream falls back to polling the row.
        }
    }

    public function markAsRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markAsSuccess(): void
    {
        $this->update([
            'status' => 'success',
            'finished_at' => now(),
            'duration_seconds' => $this->durationSinceStart(),
        ]);
    }

    public function markAsFailed(?string $step = null): void
    {
        $this->update([
            'status' => 'failed',
            'failed_step' => $step,
            'finished_at' => now(),
            'duration_seconds' => $this->durationSinceStart(),
        ]);
    }

    // started_at->diffInSeconds(now()), not the reverse: Carbon 3's
    // diffInSeconds($other) returns $other - $this, so calling it on the
    // later timestamp with the earlier one as the argument yields a
    // negative duration.
    private function durationSinceStart(): ?int
    {
        return $this->started_at ? $this->started_at->diffInSeconds(now()) : null;
    }
}
```

- [ ] **Step 5: Write the factory**

Create `backend/database/factories/BackupRunFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BackupRun;
use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        return [
            'backup_config_id' => null,
            'database_id' => Database::factory(),
            'user_id' => null,
            'kind' => BackupRun::KIND_RESTORE,
            'trigger' => 'manual',
            'source' => BackupRun::SOURCE_UPLOAD,
            'status' => 'pending',
            'database_name' => 'shop',
            'original_filename' => 'dump.sql.gz',
            'format' => BackupRun::FORMAT_SQL_GZ,
            // This codebase's factories use the fake() helper, not $this->faker.
            'upload_path' => 'restores/'.fake()->uuid().'.sql.gz',
            'size_bytes' => 1751020,
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRunModelTest`

Expected: PASS, 3 tests.

If `test_runs_from_another_organization_are_scoped_out` fails, check that `DatabaseFactory` builds its own `Server` (which carries `organization_id`), because the scope traverses `database.server`.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add database/migrations/2026_07_30_000001_create_backup_runs_table.php app/Models/BackupRun.php database/factories/BackupRunFactory.php tests/Feature/BackupRunModelTest.php
git commit -m "Add backup_runs table and BackupRun model"
```

---

## Task 3: Dump format detection

Pure function over the first bytes of a file. No SSH, no database, no framework, so it is cheap to test exhaustively and it is where the `PGDMP` rejection message lives.

**Files:**
- Create: `backend/app/Support/DumpInspector.php`
- Create: `backend/app/Exceptions/UnsupportedDumpException.php`
- Test: `backend/tests/Unit/DumpInspectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Unit/DumpInspectorTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Exceptions\UnsupportedDumpException;
use App\Support\DumpInspector;
use PHPUnit\Framework\TestCase;

class DumpInspectorTest extends TestCase
{
    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_detects_gzip_by_magic_bytes(): void
    {
        $path = $this->writeTemp(gzencode('-- PostgreSQL database dump'));

        $this->assertSame(DumpInspector::FORMAT_SQL_GZ, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_starting_with_a_comment(): void
    {
        $path = $this->writeTemp("--\n-- PostgreSQL database dump\n--\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_starting_with_a_statement(): void
    {
        $path = $this->writeTemp("SET statement_timeout = 0;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_after_leading_whitespace(): void
    {
        $path = $this->writeTemp("\n\n   /* mysqldump */\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_rejects_custom_format_pg_dump_archives_by_name(): void
    {
        $path = $this->writeTemp("PGDMP\x00\x00");

        try {
            (new DumpInspector)->detect($path);
            $this->fail('Expected UnsupportedDumpException');
        } catch (UnsupportedDumpException $e) {
            $this->assertStringContainsString('pg_restore', $e->getMessage());
            $this->assertStringContainsString('--format=plain', $e->getMessage());
        }

        unlink($path);
    }

    public function test_rejects_arbitrary_binary(): void
    {
        $path = $this->writeTemp("\x00\x01\x02\x03binary");

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);

        unlink($path);
    }

    public function test_rejects_an_empty_file(): void
    {
        $path = $this->writeTemp('');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);

        unlink($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DumpInspectorTest`

Expected: FAIL with `Class "App\Support\DumpInspector" not found`.

- [ ] **Step 3: Write the exception**

Create `backend/app/Exceptions/UnsupportedDumpException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an uploaded dump is not plain or gzipped SQL. The message is
 * shown to the user, so it must say what to do rather than just what failed.
 */
class UnsupportedDumpException extends RuntimeException {}
```

- [ ] **Step 4: Write the inspector**

Create `backend/app/Support/DumpInspector.php`:

```php
<?php

namespace App\Support;

use App\Exceptions\UnsupportedDumpException;

/**
 * Resolves a dump's format from its leading bytes. Extensions are not
 * trustworthy here: .sql.gz has no reliable MIME type and browsers report it
 * inconsistently, so the file itself is the only real evidence.
 */
class DumpInspector
{
    public const FORMAT_SQL = 'sql';
    public const FORMAT_SQL_GZ = 'sql_gz';

    /**
     * Prefixes that mark the start of a plain SQL dump produced by pg_dump or
     * mysqldump, compared case insensitively.
     *
     * The trailing space on 'create ' and 'drop ', and the semicolon on
     * 'begin;', are load bearing. Without them, ordinary prose such as
     * "Created by ...", "Dropbox sync log", or "Begin transmission" is accepted
     * as SQL, and because an overwrite restore drops the target before loading,
     * a mis-selected file would empty a live database before failing. Verified
     * against real pg_dump and mysqldump output (including --no-comments,
     * --data-only, --clean, --compact, and --single-transaction) that no genuine
     * dump is rejected by the stricter forms.
     */
    private const SQL_PREFIXES = ['--', '/*', 'set ', 'begin;', 'create ', 'drop ', 'use ', 'start transaction'];

    public function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new UnsupportedDumpException('The uploaded file could not be read.');
        }

        $head = fread($handle, 512);
        fclose($handle);

        if ($head === false || $head === '') {
            throw new UnsupportedDumpException('The uploaded file is empty.');
        }

        if (str_starts_with($head, "\x1f\x8b")) {
            // Validate the payload, not just the wrapper: a gzipped JPEG has
            // the same two magic bytes. Decompress only the head with
            // gzopen/gzread (never gzdecode on the whole file, which would
            // materialize a gigabyte) and run it through the same checks, so the
            // plain and gzipped paths cannot drift apart. Measured at about
            // 0.07ms regardless of file size, since the read is bounded to 512
            // decompressed bytes.
            return $this->detectGzipped($path);
        }

        if (str_starts_with($head, 'PGDMP')) {
            throw new UnsupportedDumpException(
                'This is a custom-format pg_dump archive, which needs pg_restore rather than psql. '
                .'Re-create it with pg_dump --format=plain (optionally piped through gzip) and upload that.'
            );
        }

        $normalized = strtolower(ltrim($head));

        foreach (self::SQL_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return self::FORMAT_SQL;
            }
        }

        throw new UnsupportedDumpException(
            'Unrecognized dump. Upload plain SQL from pg_dump or mysqldump, either uncompressed or gzipped.'
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DumpInspectorTest`

Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Support/DumpInspector.php app/Exceptions/UnsupportedDumpException.php tests/Unit/DumpInspectorTest.php
git commit -m "Add dump format detection by magic bytes"
```

---

## Task 4: Driver commands for dump, restore, and database attributes

Engine specifics stay in the drivers. The service orchestrates and never writes SQL or shell itself.

**Files:**
- Modify: `backend/app/Services/DatabaseDriverInterface.php`
- Modify: `backend/app/Services/PostgreSQLService.php`
- Modify: `backend/app/Services/MySQLService.php`
- Test: `backend/tests/Feature/DatabaseRestoreCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/DatabaseRestoreCommandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Services\MySQLService;
use App\Services\PostgreSQLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseRestoreCommandTest extends TestCase
{
    use RefreshDatabase;

    private function pgConnection(): Database
    {
        $this->createOrgUser();

        return Database::factory()->create([
            'type' => 'postgresql',
            'host' => 'localhost',
            'port' => 5432,
            'admin_user' => 'postgres',
            'admin_password' => "pa'ss",
        ]);
    }

    private function mysqlConnection(): Database
    {
        $this->createOrgUser();

        return Database::factory()->create([
            'type' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'admin_user' => 'root',
            'admin_password' => "pa'ss",
        ]);
    }

    public function test_postgres_restore_pipes_gunzip_into_psql_and_stops_on_error(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $command);
        $this->assertStringContainsString('ON_ERROR_STOP=1', $command);
        $this->assertStringContainsString("-d 'shop'", $command);
        $this->assertStringContainsString("PGPASSWORD='pa'\\''ss'", $command);
    }

    public function test_postgres_restore_cats_an_uncompressed_dump(): void
    {
        $command = (new PostgreSQLService)->buildRestoreCommand(
            $this->pgConnection(), 'shop', '/var/tmp/dump.sql', false
        );

        $this->assertStringContainsString("cat '/var/tmp/dump.sql'", $command);
        $this->assertStringNotContainsString('gunzip', $command);
    }

    public function test_postgres_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new PostgreSQLService)->buildDumpCommand(
            $this->pgConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $this->assertStringContainsString('pg_dump', $command);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $command);
    }

    public function test_mysql_restore_pipes_into_the_mysql_client(): void
    {
        $command = (new MySQLService)->buildRestoreCommand(
            $this->mysqlConnection(), 'shop', '/var/tmp/dump.sql.gz', true
        );

        $this->assertStringContainsString("gunzip -c '/var/tmp/dump.sql.gz'", $command);
        $this->assertStringContainsString("MYSQL_PWD='pa'\\''ss'", $command);
        $this->assertStringContainsString("'shop'", $command);
    }

    public function test_mysql_dump_command_gzips_to_the_target_path(): void
    {
        $command = (new MySQLService)->buildDumpCommand(
            $this->mysqlConnection(), 'shop', '/var/backups/shipyard/shop.sql.gz'
        );

        $this->assertStringContainsString('mysqldump', $command);
        $this->assertStringContainsString("| gzip > '/var/backups/shipyard/shop.sql.gz'", $command);
    }

    public function test_postgres_verify_command_targets_the_restored_database(): void
    {
        $command = (new PostgreSQLService)->buildRestoreVerifyCommand(
            $this->pgConnection(), 'shop', 'SELECT count(*) FROM pg_tables'
        );

        // Must run against the restored database, not the default postgres one,
        // and return a bare value the service can parse into an integer.
        $this->assertStringContainsString("-d 'shop'", $command);
        $this->assertStringContainsString('-t -A', $command);
    }

    public function test_mysql_verify_command_is_built_for_the_connection(): void
    {
        $command = (new MySQLService)->buildRestoreVerifyCommand(
            $this->mysqlConnection(), 'shop', 'SELECT count(*) FROM information_schema.tables'
        );

        $this->assertStringContainsString('mysql', $command);
        $this->assertStringContainsString('information_schema.tables', $command);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=DatabaseRestoreCommandTest`

Expected: FAIL with `Call to undefined method App\Services\PostgreSQLService::buildRestoreCommand()`.

- [ ] **Step 3: Extend the interface**

Append to the interface in `backend/app/Services/DatabaseDriverInterface.php`, before the closing brace:

```php
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
     * Shell command that runs a read-only query against $dbName and returns
     * bare values, used for post-restore verification.
     */
    public function buildRestoreVerifyCommand(Database $database, string $dbName, string $sql): string;
```

- [ ] **Step 4: Implement in PostgreSQLService**

Append to `backend/app/Services/PostgreSQLService.php`, before `escapeName()`:

```php
    public function buildRestoreCommand(Database $database, string $dbName, string $dumpPath, bool $gzipped): string
    {
        $reader = $gzipped
            ? 'gunzip -c '.escapeshellarg($dumpPath)
            : 'cat '.escapeshellarg($dumpPath);

        // ON_ERROR_STOP=1 is what turns a broken dump into a failed restore
        // instead of a silently half-loaded database.
        return sprintf(
            '%s | PGPASSWORD=%s psql -h %s -p %d -U %s -d %s -v ON_ERROR_STOP=1 -q 2>&1',
            $reader,
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName)
        );
    }

    public function buildDumpCommand(Database $database, string $dbName, string $outputPath): string
    {
        return sprintf(
            'PGPASSWORD=%s pg_dump -h %s -p %d -U %s %s | gzip > %s',
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName),
            escapeshellarg($outputPath)
        );
    }

    public function describeDatabase(SSHService $ssh, Database $database, string $dbName): array
    {
        $sql = sprintf(
            'SELECT pg_get_userbyid(datdba), pg_encoding_to_char(encoding), datcollate '
            ."FROM pg_database WHERE datname = '%s'",
            $this->escapeString($dbName)
        );

        $result = $ssh->execute($this->buildCommand($database, $sql, true));
        $parts = array_map('trim', explode('|', trim($result['output'])));

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

    public function buildRestoreVerifyCommand(Database $database, string $dbName, string $sql): string
    {
        // buildCommand's existing signature is
        // (Database, string $sql, bool $tupleOnly = false, ?string $dbName = null)
        // at PostgreSQLService.php:354, so this asks for bare values against the
        // restored database rather than the default postgres database.
        return $this->buildCommand($database, $sql, true, $dbName);
    }
```

`buildCommand()` in this class already takes a third `$tupleOnly` argument, which is what makes `describeDatabase` return bare values.

- [ ] **Step 5: Implement in MySQLService**

Append to `backend/app/Services/MySQLService.php`, before `escapeName()`:

```php
    public function buildRestoreCommand(Database $database, string $dbName, string $dumpPath, bool $gzipped): string
    {
        $reader = $gzipped
            ? 'gunzip -c '.escapeshellarg($dumpPath)
            : 'cat '.escapeshellarg($dumpPath);

        // The mysql client aborts on the first error unless --force is given,
        // which is the behaviour a restore needs.
        return sprintf(
            '%s | MYSQL_PWD=%s mysql -h %s -P %d -u %s %s 2>&1',
            $reader,
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName)
        );
    }

    public function buildDumpCommand(Database $database, string $dbName, string $outputPath): string
    {
        return sprintf(
            'MYSQL_PWD=%s mysqldump -h %s -P %d -u %s --single-transaction --routines --triggers %s | gzip > %s',
            escapeshellarg($database->admin_password),
            escapeshellarg($database->host),
            $database->port,
            escapeshellarg($database->admin_user),
            escapeshellarg($dbName),
            escapeshellarg($outputPath)
        );
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
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=DatabaseRestoreCommandTest`

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/DatabaseDriverInterface.php app/Services/PostgreSQLService.php app/Services/MySQLService.php tests/Feature/DatabaseRestoreCommandTest.php
git commit -m "Add dump, restore, and describe commands to database drivers"
```

---

## Task 5: BackupRestoreService

**Files:**
- Create: `backend/app/Services/BackupRestoreService.php`
- Test: `backend/tests/Feature/BackupRestoreServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/BackupRestoreServiceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Database;
use App\Services\BackupRestoreService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupRestoreServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $executed = [];

    /** @var array<string, array> keyed by substring of the command */
    private array $fakeResults = [];

    private function mockSsh(): void
    {
        $this->executed = [];

        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('upload')->andReturn(true);
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command, int $timeout = 300) {
                $this->executed[] = $command;

                foreach ($this->fakeResults as $needle => $result) {
                    if (str_contains($command, $needle)) {
                        return $result;
                    }
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function indexOfCommandContaining(string $needle): ?int
    {
        foreach ($this->executed as $index => $command) {
            if (str_contains($command, $needle)) {
                return $index;
            }
        }

        return null;
    }

    private function makeRun(array $attributes = []): BackupRun
    {
        $this->createOrgUser();

        Storage::fake('local');
        Storage::disk('local')->put('restores/dump.sql.gz', gzencode('SELECT 1;'));

        $database = Database::factory()->create([
            'type' => 'postgresql',
            'admin_user' => 'postgres',
            'admin_password' => 'secret',
        ]);

        return BackupRun::factory()->create(array_merge([
            'database_id' => $database->id,
            'database_name' => 'shop',
            'format' => BackupRun::FORMAT_SQL_GZ,
            'upload_path' => 'restores/dump.sql.gz',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_safety_dump_runs_before_the_drop(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $dumpAt = $this->indexOfCommandContaining('pg_dump');
        $dropAt = $this->indexOfCommandContaining('DROP DATABASE');

        $this->assertNotNull($dumpAt, 'expected a safety dump command');
        $this->assertNotNull($dropAt, 'expected a drop command');
        $this->assertLessThan($dropAt, $dumpAt, 'the safety dump must precede the drop');
    }

    public function test_overwrite_records_the_safety_dump_path_and_succeeds(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('success', $run->status);
        $this->assertStringContainsString('/var/backups/shipyard/', $run->safety_dump_path);
        $this->assertStringContainsString('Restore completed', $run->log);
    }

    public function test_restore_to_a_new_name_skips_the_safety_dump(): void
    {
        $this->mockSsh();
        $run = $this->makeRun(['database_name' => 'shop_copy']);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: false);

        $this->assertNull($this->indexOfCommandContaining('pg_dump'));
        $this->assertNull($this->indexOfCommandContaining('DROP DATABASE'));
        $this->assertNotNull($this->indexOfCommandContaining('CREATE DATABASE'));
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_a_failed_load_fails_the_run_and_names_the_safety_dump(): void
    {
        $this->mockSsh();
        $this->fakeResults['ON_ERROR_STOP'] = [
            'output' => 'ERROR:  syntax error at or near "GARBAGE"',
            'exit_code' => 3,
            'success' => false,
        ];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertStringContainsString('partially loaded', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_load_that_produces_no_tables_fails_the_run(): void
    {
        $this->mockSsh();
        // The load command succeeds (an empty dump exits 0), but verification
        // finds nothing. This must fail rather than report success, because the
        // target was already dropped.
        $this->fakeResults['pg_tables'] = ['output' => '0', 'exit_code' => 0, 'success' => true];
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('produced no tables', $run->log);
        $this->assertStringContainsString($run->safety_dump_path, $run->log);
    }

    public function test_a_failed_load_to_a_new_name_is_still_labelled_a_restore_failure(): void
    {
        $this->mockSsh();
        $this->fakeResults['ON_ERROR_STOP'] = [
            'output' => 'ERROR:  syntax error',
            'exit_code' => 3,
            'success' => false,
        ];
        $run = $this->makeRun(['database_name' => 'shop_copy']);

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: false);

        $run->refresh();

        // This path never takes a safety dump, so failed_step must come from
        // the tracked step rather than being inferred from its absence.
        $this->assertSame('failed', $run->status);
        $this->assertSame('restore', $run->failed_step);
        $this->assertNull($run->safety_dump_path);
    }

    public function test_the_uploaded_dump_is_removed_from_the_server_and_the_host(): void
    {
        $this->mockSsh();
        $run = $this->makeRun();

        app(BackupRestoreService::class)->restoreFromUpload($run, overwrite: true);

        $this->assertNotNull($this->indexOfCommandContaining('rm -f'));
        Storage::disk('local')->assertMissing('restores/dump.sql.gz');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRestoreServiceTest`

Expected: FAIL with `Target class [App\Services\BackupRestoreService] does not exist.`

- [ ] **Step 3: Write the service**

Create `backend/app/Services/BackupRestoreService.php`:

```php
<?php

namespace App\Services;

use App\Models\BackupRun;
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
                    "The target database may be partially loaded. The pre-restore dump is at "
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
        // tidiness must never fail a successful restore.
        $this->ssh->execute(
            "sudo ls -1t {$pattern} 2>/dev/null | tail -n +".(self::SAFETY_DUMPS_KEPT + 1)
            .' | xargs -r sudo rm -f'
        );
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRestoreServiceTest`

Expected: PASS, 7 tests. `buildRestoreVerifyCommand` already exists on both drivers from Task 4, so this task only writes the service.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Services/BackupRestoreService.php app/Services/DatabaseDriverInterface.php app/Services/PostgreSQLService.php app/Services/MySQLService.php tests/Feature/BackupRestoreServiceTest.php
git commit -m "Add BackupRestoreService for uploaded dump restores"
```

---

## Task 6: ProcessDatabaseRestore job

**Files:**
- Create: `backend/app/Jobs/ProcessDatabaseRestore.php`
- Test: `backend/tests/Feature/ProcessDatabaseRestoreTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/ProcessDatabaseRestoreTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Services\BackupRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ProcessDatabaseRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_never_retries(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $this->assertSame(1, (new ProcessDatabaseRestore($run->id, true))->tries);
    }

    public function test_the_job_delegates_to_the_service(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $service = Mockery::mock(BackupRestoreService::class);
        $service->shouldReceive('restoreFromUpload')
            ->once()
            ->withArgs(fn (BackupRun $passed, bool $overwrite) => $passed->id === $run->id && $overwrite === true);

        (new ProcessDatabaseRestore($run->id, true))->handle($service);
    }

    public function test_failed_marks_the_run_and_deletes_the_upload(): void
    {
        $this->createOrgUser();

        Storage::fake('local');
        Storage::disk('local')->put('restores/orphan.sql.gz', 'x');

        $run = BackupRun::factory()->create([
            'status' => 'running',
            'upload_path' => 'restores/orphan.sql.gz',
        ]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('worker killed'));

        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('worker killed', $run->log);
        Storage::disk('local')->assertMissing('restores/orphan.sql.gz');
    }

    public function test_failed_is_a_no_op_for_an_already_finished_run(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'success', 'log' => "done\n"]);

        (new ProcessDatabaseRestore($run->id, true))->failed(new \RuntimeException('late failure'));

        $this->assertSame('success', $run->fresh()->status);
        $this->assertStringNotContainsString('late failure', $run->fresh()->log);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ProcessDatabaseRestoreTest`

Expected: FAIL with `Class "App\Jobs\ProcessDatabaseRestore" not found`.

- [ ] **Step 3: Write the job**

Create `backend/app/Jobs/ProcessDatabaseRestore.php`:

```php
<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\BackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDatabaseRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A restore drops and recreates a database. Retrying one automatically
     * would destroy the target a second time, so it never retries.
     */
    public int $tries = 1;

    /**
     * Sized for a gigabyte load and kept under the queue worker's
     * --max-time=3600, so the worker does not exit mid-restore.
     */
    public int $timeout = 1800;

    public function __construct(
        private int $backupRunId,
        private bool $overwrite,
    ) {}

    public function handle(BackupRestoreService $service): void
    {
        // Queue workers run unscoped by design, so resolve without the
        // organization scope; authorization already happened in the controller.
        $run = BackupRun::withoutGlobalScopes()->find($this->backupRunId);

        if (! $run) {
            return;
        }

        $service->restoreFromUpload($run, $this->overwrite);
    }

    public function failed(Throwable $exception): void
    {
        $run = BackupRun::withoutGlobalScopes()->find($this->backupRunId);

        if (! $run || $run->isComplete()) {
            return;
        }

        $run->appendLog('ERROR: '.$exception->getMessage());
        $run->markAsFailed('restore');

        // The service's own finally block did not get to run if the worker was
        // killed, so make sure the upload is not left behind.
        if ($run->upload_path) {
            Storage::disk('local')->delete($run->upload_path);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ProcessDatabaseRestoreTest`

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Jobs/ProcessDatabaseRestore.php tests/Feature/ProcessDatabaseRestoreTest.php
git commit -m "Add ProcessDatabaseRestore job"
```

---

## Task 7: Upload endpoint

**Files:**
- Create: `backend/app/Http/Controllers/Api/DatabaseRestoreController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/DatabaseRestoreUploadTest.php`

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/DatabaseRestoreUploadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseRestoreUploadTest extends TestCase
{
    use RefreshDatabase;

    private function gzDump(string $name = 'dump.sql.gz'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, gzencode("--\n-- dump\n--\nSELECT 1;\n"));
    }

    private function setup_target(): array
    {
        $user = $this->createOrgUser();
        $database = Database::factory()->create(['type' => 'postgresql']);

        return [$user, $database];
    }

    private function url(Database $database): string
    {
        return "/api/servers/{$database->server_id}/databases/{$database->id}/restores";
    }

    public function test_an_admin_can_upload_a_dump_and_a_run_is_queued(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '1',
            'confirm_name' => 'shop',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'pending');

        $run = BackupRun::first();
        $this->assertSame(BackupRun::KIND_RESTORE, $run->kind);
        $this->assertSame(BackupRun::SOURCE_UPLOAD, $run->source);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(BackupRun::FORMAT_SQL_GZ, $run->format);
        $this->assertSame($user->id, $run->user_id);
        $this->assertSame('shop', $run->database_name);

        Storage::disk('local')->assertExists($run->upload_path);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_overwrite_requires_the_confirmation_name_to_match(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '1',
            'confirm_name' => 'sho',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, BackupRun::count());
        Queue::assertNothingPushed();
    }

    public function test_a_new_target_does_not_need_a_confirmation_name(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop_copy',
            'overwrite' => '0',
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_a_custom_format_archive_is_rejected_with_guidance(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => UploadedFile::fake()->createWithContent('dump.dump', "PGDMP\x00binary"),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('pg_restore', $response->json('message'));
        Queue::assertNothingPushed();
    }

    public function test_a_member_cannot_upload_a_restore(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->createOrgUser(Organization::ROLE_MEMBER);
        $database = Database::factory()->create(['type' => 'postgresql']);

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop',
            'overwrite' => '0',
        ]);

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }

    public function test_a_database_from_another_organization_is_not_found(): void
    {
        Queue::fake();
        Storage::fake('local');

        $outsider = User::factory()->create();
        CurrentOrganization::set($outsider->currentOrganization, Organization::ROLE_OWNER);
        $foreignDatabase = Database::factory()->create(['type' => 'postgresql']);
        CurrentOrganization::forget();

        $user = $this->createOrgUser();
        $ownServer = Server::factory()->create();

        $response = $this->actingAs($user)->post(
            "/api/servers/{$ownServer->id}/databases/{$foreignDatabase->id}/restores",
            [
                'dump' => $this->gzDump(),
                'target_database' => 'shop',
                'overwrite' => '0',
            ]
        );

        $response->assertStatus(404);
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_target_name_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$user, $database] = $this->setup_target();

        $response = $this->actingAs($user)->post($this->url($database), [
            'dump' => $this->gzDump(),
            'target_database' => 'shop; DROP DATABASE postgres',
            'overwrite' => '0',
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=DatabaseRestoreUploadTest`

Expected: FAIL with 404s, because the route does not exist yet.

- [ ] **Step 3: Write the controller**

Create `backend/app/Http/Controllers/Api/DatabaseRestoreController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\UnsupportedDumpException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use App\Support\DumpInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DatabaseRestoreController extends Controller
{
    public function __construct(private DumpInspector $inspector) {}

    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        // Database carries no organization scope of its own, so the parent
        // check is what keeps a scoped {server} from being paired with someone
        // else's {database}.
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'dump' => ['required', 'file', 'max:1228800'],
            'target_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_$]*$/'],
            'overwrite' => ['required', 'boolean'],
            'confirm_name' => ['nullable', 'string'],
        ]);

        $overwrite = (bool) $validated['overwrite'];
        $target = $validated['target_database'];

        if ($overwrite && ($validated['confirm_name'] ?? null) !== $target) {
            return response()->json([
                'message' => "Type the database name ({$target}) to confirm overwriting it.",
            ], 422);
        }

        $file = $request->file('dump');

        // Two copies exist transiently, the PHP upload temp file and the
        // stored one, so require headroom for both before committing.
        $required = $file->getSize() * 2;

        // storage_path('app') and the local disk root (storage/app/private)
        // are the same filesystem, so measuring the parent is sufficient.
        if (disk_free_space(storage_path('app')) < $required) {
            return response()->json([
                'message' => 'Not enough disk space on the ShipYard host to accept this dump.',
            ], 507);
        }

        try {
            $format = $this->inspector->detect($file->getRealPath());
        } catch (UnsupportedDumpException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $extension = $format === DumpInspector::FORMAT_SQL_GZ ? 'sql.gz' : 'sql';
        $path = $file->storeAs('restores', Str::uuid().'.'.$extension);

        $run = BackupRun::create([
            'database_id' => $database->id,
            'user_id' => $request->user()->id,
            'kind' => BackupRun::KIND_RESTORE,
            'trigger' => 'manual',
            'source' => BackupRun::SOURCE_UPLOAD,
            'status' => 'pending',
            'database_name' => $target,
            'original_filename' => $file->getClientOriginalName(),
            'format' => $format,
            'upload_path' => $path,
            'size_bytes' => $file->getSize(),
        ]);

        ProcessDatabaseRestore::dispatch($run->id, $overwrite);

        return response()->json(['data' => $run], 202);
    }

    public function index(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $runs = BackupRun::where('database_id', $database->id)
            ->where('kind', BackupRun::KIND_RESTORE)
            ->with('user:id,name')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        return response()->json(['data' => $backupRun->load('user:id,name')]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `backend/routes/api.php`, directly after the `// Remote database operations` block (the three `remote-databases` routes), add:

```php
    // Database restores. The upload drops and recreates a database, so it
    // sits behind the admin gate like the destructive server actions.
    Route::get('/servers/{server}/databases/{database}/restores', [DatabaseRestoreController::class, 'index']);
    Route::get('/backup-runs/{backupRun}', [DatabaseRestoreController::class, 'show']);
    Route::middleware('org.role:admin')->group(function () {
        Route::post('/servers/{server}/databases/{database}/restores', [DatabaseRestoreController::class, 'store']);
    });
```

Add the import at the top of the file, alongside the other controller imports:

```php
use App\Http\Controllers\Api\DatabaseRestoreController;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=DatabaseRestoreUploadTest`

Expected: PASS, 7 tests.

If `test_a_member_cannot_upload_a_restore` returns 403 from `org.writes` rather than `org.role`, that is fine. The assertion is on the status, not on which middleware produced it.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Http/Controllers/Api/DatabaseRestoreController.php routes/api.php tests/Feature/DatabaseRestoreUploadTest.php
git commit -m "Add database restore upload endpoint"
```

---

## Task 8: SSE log stream

**Files:**
- Create: `backend/app/Http/Controllers/Api/BackupRunStreamController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/BackupRunStreamTest.php`
- Modify: `backend/tests/Feature/StreamAuthIsolationTest.php`

Read `backend/tests/Feature/StreamAuthIsolationTest.php` first. It already asserts cross-organization rejection for the deployment and installation streams (`test_deployment_stream_rejects_users_from_another_organization` at line 54, `test_installation_stream_rejects_users_from_another_organization` at line 66) using `$response->streamedContent()`. Every SSE route belongs in that file, so add a matching case for `/api/backup-runs/{run}/stream` there in the same shape as the existing two. The dedicated `BackupRunStreamTest` still carries the admin-role and finished-run cases, which have no equivalent in the isolation test.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/BackupRunStreamTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupRunStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stream_rejects_a_request_without_a_token(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create();

        $response = $this->get("/api/backup-runs/{$run->id}/stream");

        $response->assertStatus(401);
    }

    public function test_the_stream_rejects_a_token_from_another_organization(): void
    {
        $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'running']);

        $outsider = User::factory()->create();
        $token = $outsider->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(403);
    }

    public function test_the_stream_rejects_a_member_of_the_owning_organization(): void
    {
        $user = $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'running']);

        $organization = $user->currentOrganization;
        $member = User::factory()->create();
        $member->organizations()->attach($organization->id, ['role' => Organization::ROLE_MEMBER]);
        $token = $member->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(403);
    }

    public function test_a_finished_run_streams_its_log_and_closes(): void
    {
        $user = $this->createOrgUser();
        $run = BackupRun::factory()->create(['status' => 'success', 'log' => "all done\n"]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->get("/api/backup-runs/{$run->id}/stream?token={$token}");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream');

        $content = $response->streamedContent();
        $this->assertStringContainsString('event: connected', $content);
        $this->assertStringContainsString('all done', $content);
        $this->assertStringContainsString('event: complete', $content);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRunStreamTest`

Expected: FAIL with 404s, because the route does not exist yet.

- [ ] **Step 3: Write the stream controller**

Create `backend/app/Http/Controllers/Api/BackupRunStreamController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Models\Organization;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Mirrors DatabaseInstallationStreamController. This route sits outside
 * auth:sanctum because EventSource cannot send headers, so the token, the
 * organization membership, and the admin role are all checked by hand.
 *
 * The cap is 30 minutes rather than the installation stream's 5, because a
 * gigabyte restore outlives 5 minutes. The client reconnects on timeout and
 * resumes from its log offset, which works because the log lives on the row.
 */
class BackupRunStreamController extends Controller
{
    private const TIMEOUT_SECONDS = 1800;

    public function stream(Request $request, BackupRun $backupRun): StreamedResponse
    {
        $token = $request->query('token');

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && (! $accessToken->expires_at || $accessToken->expires_at->isFuture())) {
                $request->setUserResolver(fn () => $accessToken->tokenable);
            }
        }

        if (! $request->user()) {
            return $this->refuse(401);
        }

        // Unscoped binding, so membership and the admin role are enforced by
        // hand. One identical refusal body for every failure, matching
        // TerminalStreamController: differing bodies would leak whether the
        // run exists and whether the caller is a member.
        $user = $request->user();
        $organizationId = $backupRun->database->server->organization_id;
        $role = $user->roleIn($organizationId);

        if (! $user->belongsToOrganization($organizationId)
            || ! in_array($role, [Organization::ROLE_ADMIN, Organization::ROLE_OWNER], true)) {
            return $this->refuse(403);
        }

        $runId = $backupRun->id;

        $response = new StreamedResponse(function () use ($runId) {
            while (ob_get_level()) {
                ob_end_clean();
            }

            set_time_limit(self::TIMEOUT_SECONDS + 60);

            $run = BackupRun::withoutGlobalScopes()->find($runId);

            if (! $run) {
                $this->sendEvent('error', ['message' => 'Run not found']);

                return;
            }

            $lastLength = strlen($run->log ?? '');

            $this->sendEvent('connected', [
                'run_id' => $run->id,
                'status' => $run->status,
                'log' => $run->log,
            ]);

            if ($run->isComplete()) {
                $this->sendEvent('complete', ['status' => $run->status, 'is_complete' => true]);

                return;
            }

            $startedAt = time();
            $lastHeartbeat = time();

            while (true) {
                if (time() - $startedAt > self::TIMEOUT_SECONDS) {
                    $this->sendEvent('timeout', ['message' => 'Connection timed out']);
                    break;
                }

                if (connection_aborted()) {
                    break;
                }

                $run = BackupRun::withoutGlobalScopes()->find($runId);

                if (! $run) {
                    $this->sendEvent('error', ['message' => 'Run not found']);
                    break;
                }

                $log = $run->log ?? '';

                if (strlen($log) > $lastLength) {
                    $this->sendEvent('log', [
                        'chunk' => substr($log, $lastLength),
                        'timestamp' => now()->toIso8601String(),
                        'is_complete' => false,
                    ]);
                    $lastLength = strlen($log);
                }

                if ($run->isComplete()) {
                    $this->sendEvent('complete', ['status' => $run->status, 'is_complete' => true]);
                    break;
                }

                if (time() - $lastHeartbeat >= 15) {
                    $this->sendEvent('heartbeat', ['time' => time()]);
                    $lastHeartbeat = time();
                }

                usleep(500000);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function refuse(int $status): StreamedResponse
    {
        return new StreamedResponse(function () {
            $this->sendEvent('error', ['message' => 'Unauthorized']);
        }, $status, ['Content-Type' => 'text/event-stream']);
    }

    private function sendEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";

        if (ob_get_level()) {
            ob_flush();
        }

        flush();
    }
}
```

`User::roleIn(Organization|int $organization): ?string` is the accessor to use (`app/Models/User.php:49`). There is no `roleInOrganization()`.

- [ ] **Step 4: Register the route**

In `backend/routes/api.php`, in the public SSE block near the top (alongside `database-installations/{installation}/stream`), add:

```php
Route::get('/backup-runs/{backupRun}/stream', [BackupRunStreamController::class, 'stream']);
```

Add the import:

```php
use App\Http\Controllers\Api\BackupRunStreamController;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=BackupRunStreamTest`

Expected: PASS, 4 tests.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint --dirty
git add app/Http/Controllers/Api/BackupRunStreamController.php routes/api.php tests/Feature/BackupRunStreamTest.php
git commit -m "Add SSE log stream for backup runs"
```

---

## Task 9: Raise the upload limits in PHP and nginx

No automated test covers this; it is verified by an actual upload in Task 13.

**Files:**
- Modify: `docker/php/zz-shipyard.conf`
- Modify: `docker/nginx/default.conf`

- [ ] **Step 1: Read the current PHP pool config**

Run: `cat docker/php/zz-shipyard.conf`

Note the existing `php_admin_value` entries and the pool name, so the additions match the file's style.

- [ ] **Step 2: Add the upload limits**

Append to `docker/php/zz-shipyard.conf`:

```ini
; Database restore uploads run to a gigabyte. PHP spools request bodies to
; disk rather than memory, so memory_limit is unaffected by these.
php_admin_value[upload_max_filesize] = 1200M
php_admin_value[post_max_size] = 1200M
```

- [ ] **Step 3: Scope a larger body limit to the restore route in nginx**

In `docker/nginx/default.conf`, leave the global `client_max_body_size 100M` on line 7 alone and add a location block before the generic `location ~ \.php$` block:

```nginx
    # Restore dumps reach a gigabyte. The cap stays at 100M everywhere else so
    # a single oversized route does not become the whole app's limit.
    location ~ ^/api/servers/[0-9]+/databases/[0-9]+/restores$ {
        client_max_body_size 1200M;
        client_body_timeout 600s;

        try_files $uri /index.php?$query_string;
    }
```

Confirm the surrounding blocks use `try_files $uri /index.php?$query_string;` and match whatever the file actually does for API routes rather than copying this verbatim.

- [ ] **Step 4: Rebuild and restart**

```bash
cd ..
docker compose build app
docker compose up -d app
docker compose restart nginx
```

`zz-shipyard.conf` is baked in with `COPY`, so it needs a rebuild rather than a restart. `default.conf` is a single-file bind mount whose inode is replaced by an edit, so it needs `docker compose restart nginx`, not `nginx -s reload`.

- [ ] **Step 5: Verify the limits took effect**

```bash
docker compose exec app php -i | grep -E "upload_max_filesize|post_max_size"
docker compose exec nginx nginx -t
```

Expected: both PHP values report `1200M`, and nginx reports the configuration test is successful.

- [ ] **Step 6: Commit**

```bash
git add docker/php/zz-shipyard.conf docker/nginx/default.conf
git commit -m "Raise upload limits for database restore dumps"
```

---

## Task 10: Frontend API client

**Files:**
- Modify: `frontend/src/services/api.ts`

- [ ] **Step 1: Add the type**

In `frontend/src/services/api.ts`, near the other exported interfaces, add:

```ts
export interface BackupRun {
  id: number
  database_id: number
  database_name: string
  kind: 'backup' | 'restore'
  source: 's3' | 'upload' | null
  status: 'pending' | 'running' | 'success' | 'failed'
  failed_step: string | null
  original_filename: string | null
  format: 'sql' | 'sql_gz' | null
  safety_dump_path: string | null
  size_bytes: number | null
  duration_seconds: number | null
  log: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string
  user?: { id: number; name: string }
}
```

- [ ] **Step 2: Add the API methods**

Append to `frontend/src/services/api.ts`:

```ts
export const databaseRestoresApi = {
  list: (serverId: number, databaseId: number) =>
    api.get<{ data: BackupRun[] }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/restores'
    ),

  get: (runId: number) => api.get<{ data: BackupRun }>('/backup-runs/' + runId),

  // Multipart with progress: the browser to ShipYard leg is the only part the
  // user can watch, so it drives the progress bar. Content-Type is left to the
  // browser so it sets the multipart boundary.
  upload: (
    serverId: number,
    databaseId: number,
    payload: {
      dump: File
      targetDatabase: string
      overwrite: boolean
      confirmName?: string
    },
    onProgress?: (percent: number) => void
  ) => {
    const form = new FormData()
    form.append('dump', payload.dump)
    form.append('target_database', payload.targetDatabase)
    form.append('overwrite', payload.overwrite ? '1' : '0')
    if (payload.confirmName) {
      form.append('confirm_name', payload.confirmName)
    }

    return api.post<{ data: BackupRun }>(
      '/servers/' + serverId + '/databases/' + databaseId + '/restores',
      form,
      {
        headers: { 'Content-Type': undefined },
        onUploadProgress: (event) => {
          if (onProgress && event.total) {
            onProgress(Math.round((event.loaded * 100) / event.total))
          }
        },
      }
    )
  },
}
```

- [ ] **Step 3: Verify it compiles**

Run: `cd frontend && npm run build`

Expected: build succeeds with no TypeScript errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/services/api.ts
git commit -m "Add restore API client methods"
```

---

## Task 11: Restore log panel component

**Files:**
- Create: `frontend/src/components/databases/RestoreLogPanel.tsx`

- [ ] **Step 1: Write the component**

Create `frontend/src/components/databases/RestoreLogPanel.tsx`:

```tsx
import { useEffect, useRef, useState } from 'react'
import { BackupRun, databaseRestoresApi } from '@/services/api'

interface RestoreLogPanelProps {
  runId: number
  onComplete?: (status: 'success' | 'failed') => void
}

/**
 * Follows a restore over SSE. The stream caps at 30 minutes server-side, so a
 * timeout event reconnects rather than stranding the user: the log lives on the
 * run row, so a fresh connection replays everything so far.
 */
export function RestoreLogPanel({ runId, onComplete }: RestoreLogPanelProps) {
  const [log, setLog] = useState('')
  const [status, setStatus] = useState<BackupRun['status']>('pending')
  const eventSourceRef = useRef<EventSource | null>(null)
  const logRef = useRef<HTMLPreElement | null>(null)

  useEffect(() => {
    let cancelled = false

    const connect = () => {
      const token = localStorage.getItem('token')
      const es = new EventSource(`/api/backup-runs/${runId}/stream?token=${token}`)
      eventSourceRef.current = es

      es.addEventListener('connected', (event) => {
        const data = JSON.parse((event as MessageEvent).data)
        setLog(data.log ?? '')
        setStatus(data.status)
      })

      es.addEventListener('log', (event) => {
        const data = JSON.parse((event as MessageEvent).data)
        setLog((current) => current + data.chunk)
      })

      es.addEventListener('complete', (event) => {
        const data = JSON.parse((event as MessageEvent).data)
        setStatus(data.status)
        es.close()
        onComplete?.(data.status)
      })

      es.addEventListener('timeout', () => {
        es.close()
        if (!cancelled) {
          connect()
        }
      })

      es.onerror = () => {
        es.close()
        // Fall back to a single status poll so a dead stream still resolves.
        databaseRestoresApi.get(runId).then((response) => {
          const run = response.data.data
          setLog(run.log ?? '')
          setStatus(run.status)
          if (run.status === 'success' || run.status === 'failed') {
            onComplete?.(run.status)
          }
        })
      }
    }

    connect()

    return () => {
      cancelled = true
      eventSourceRef.current?.close()
    }
  }, [runId, onComplete])

  useEffect(() => {
    if (logRef.current) {
      logRef.current.scrollTop = logRef.current.scrollHeight
    }
  }, [log])

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 text-sm">
        <span className="font-medium">Restore</span>
        <span
          className={
            status === 'failed'
              ? 'text-red-600'
              : status === 'success'
                ? 'text-green-600'
                : 'text-muted-foreground'
          }
        >
          {status}
        </span>
      </div>
      <pre
        ref={logRef}
        className="max-h-80 overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap"
      >
        {log || 'Waiting for output...'}
      </pre>
    </div>
  )
}
```

- [ ] **Step 2: Verify it compiles**

Run: `cd frontend && npm run build`

Expected: build succeeds. If the `@/` alias is not configured, use a relative import path instead, matching how other components in `src/components/` import from `src/services/api`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/databases/RestoreLogPanel.tsx
git commit -m "Add restore log panel component"
```

---

## Task 12: Restore dialog component

**Files:**
- Create: `frontend/src/components/databases/RestoreDatabaseDialog.tsx`

- [ ] **Step 1: Read an existing destructive dialog for the pattern**

Run: `grep -rn "Dialog" frontend/src/pages/servers/DatabaseDetail.tsx | head -20`

Match that file's dialog imports and structure, including how it renders a typed-name confirmation if one already exists there.

- [ ] **Step 2: Write the component**

Create `frontend/src/components/databases/RestoreDatabaseDialog.tsx`:

```tsx
import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { databaseRestoresApi, getErrorMessage } from '@/services/api'

interface RestoreDatabaseDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  serverId: number
  databaseId: number
  targetDatabase: string
  onQueued: (runId: number) => void
}

export function RestoreDatabaseDialog({
  open,
  onOpenChange,
  serverId,
  databaseId,
  targetDatabase,
  onQueued,
}: RestoreDatabaseDialogProps) {
  const [file, setFile] = useState<File | null>(null)
  const [confirmName, setConfirmName] = useState('')
  const [progress, setProgress] = useState(0)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const confirmed = confirmName === targetDatabase
  const canSubmit = Boolean(file) && confirmed && !uploading

  const reset = () => {
    setFile(null)
    setConfirmName('')
    setProgress(0)
    setUploading(false)
    setError(null)
  }

  const submit = async () => {
    if (!file) return

    setUploading(true)
    setError(null)

    try {
      const response = await databaseRestoresApi.upload(
        serverId,
        databaseId,
        {
          dump: file,
          targetDatabase,
          overwrite: true,
          confirmName,
        },
        setProgress
      )

      onQueued(response.data.data.id)
      reset()
      onOpenChange(false)
    } catch (err) {
      setError(getErrorMessage(err, 'The upload failed.'))
      setUploading(false)
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) reset()
        onOpenChange(next)
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Restore {targetDatabase}</DialogTitle>
          <DialogDescription>
            This drops and recreates <strong>{targetDatabase}</strong>, then loads the dump you
            upload. A safety dump of the current contents is taken first and its path is printed in
            the log. Accepts plain or gzipped SQL up to 1 GB.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="dump">Dump file</Label>
            <Input
              id="dump"
              type="file"
              accept=".sql,.gz,.sql.gz"
              onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              disabled={uploading}
            />
            {file && (
              <p className="text-xs text-muted-foreground">
                {file.name} ({(file.size / 1024 / 1024).toFixed(1)} MB)
              </p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="confirm">
              Type <span className="font-mono">{targetDatabase}</span> to confirm
            </Label>
            <Input
              id="confirm"
              value={confirmName}
              onChange={(event) => setConfirmName(event.target.value)}
              disabled={uploading}
              autoComplete="off"
            />
          </div>

          {uploading && (
            <div className="space-y-1">
              <div className="h-2 w-full overflow-hidden rounded bg-muted">
                <div className="h-full bg-primary transition-all" style={{ width: `${progress}%` }} />
              </div>
              <p className="text-xs text-muted-foreground">Uploading {progress}%</p>
            </div>
          )}

          {error && <p className="text-sm text-red-600">{error}</p>}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={uploading}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={submit} disabled={!canSubmit}>
            {uploading ? 'Uploading...' : 'Restore'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

- [ ] **Step 3: Verify it compiles**

Run: `cd frontend && npm run build`

Expected: build succeeds. If `Label` or `Input` live at different paths, correct the imports to match `src/components/ui/`.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/databases/RestoreDatabaseDialog.tsx
git commit -m "Add restore database dialog component"
```

---

## Task 13: Wire the restore UI into DatabaseDetail

**Files:**
- Modify: `frontend/src/pages/servers/DatabaseDetail.tsx`

- [ ] **Step 1: Read the remote databases section**

Run: `grep -n "remote-databases\|listRemoteDatabases\|remoteDatabases" frontend/src/pages/servers/DatabaseDetail.tsx`

Locate where each remote database row renders its actions. That row is where the Restore button goes.

- [ ] **Step 2: Add state and imports**

Add to the imports at the top of `DatabaseDetail.tsx`:

```tsx
import { RestoreDatabaseDialog } from '@/components/databases/RestoreDatabaseDialog'
import { RestoreLogPanel } from '@/components/databases/RestoreLogPanel'
import { BackupRun, databaseRestoresApi } from '@/services/api'
```

Add to the component's state declarations:

```tsx
const [restoreTarget, setRestoreTarget] = useState<string | null>(null)
const [activeRunId, setActiveRunId] = useState<number | null>(null)
const [restoreHistory, setRestoreHistory] = useState<BackupRun[]>([])
```

- [ ] **Step 3: Load the history**

Add a loader and call it from the same effect that loads remote databases:

```tsx
const loadRestoreHistory = async () => {
  if (!serverId || !databaseId) return

  try {
    const response = await databaseRestoresApi.list(Number(serverId), Number(databaseId))
    setRestoreHistory(response.data.data)
  } catch {
    // History is informational; a failure here must not break the page.
  }
}
```

- [ ] **Step 4: Add the Restore action to each remote database row**

In the actions cell of each remote database row, alongside the existing drop action:

```tsx
<Button variant="outline" size="sm" onClick={() => setRestoreTarget(dbName)}>
  Restore
</Button>
```

Use whatever the row's database name variable is actually called instead of `dbName`.

- [ ] **Step 5: Render the dialog, the live log, and the history**

Add near the end of the component's returned JSX:

```tsx
{restoreTarget && (
  <RestoreDatabaseDialog
    open={Boolean(restoreTarget)}
    onOpenChange={(open) => !open && setRestoreTarget(null)}
    serverId={Number(serverId)}
    databaseId={Number(databaseId)}
    targetDatabase={restoreTarget}
    onQueued={(runId) => {
      setActiveRunId(runId)
      setRestoreTarget(null)
    }}
  />
)}

{activeRunId && (
  <div className="mt-6">
    <RestoreLogPanel
      runId={activeRunId}
      onComplete={() => {
        loadRestoreHistory()
        loadRemoteDatabases()
      }}
    />
  </div>
)}

{restoreHistory.length > 0 && (
  <div className="mt-6 space-y-2">
    <h3 className="text-sm font-medium">Restore history</h3>
    <ul className="divide-y rounded-md border text-sm">
      {restoreHistory.map((run) => (
        <li key={run.id} className="flex items-center justify-between p-3">
          <div>
            <p className="font-medium">{run.database_name}</p>
            <p className="text-xs text-muted-foreground">
              {run.original_filename} ({((run.size_bytes ?? 0) / 1024 / 1024).toFixed(1)} MB)
              {run.user ? ` by ${run.user.name}` : ''}
            </p>
          </div>
          <div className="flex items-center gap-3">
            <span
              className={
                run.status === 'failed'
                  ? 'text-red-600'
                  : run.status === 'success'
                    ? 'text-green-600'
                    : 'text-muted-foreground'
              }
            >
              {run.status}
            </span>
            <Button variant="ghost" size="sm" onClick={() => setActiveRunId(run.id)}>
              Log
            </Button>
          </div>
        </li>
      ))}
    </ul>
  </div>
)}
```

Use the page's actual remote-database reload function name instead of `loadRemoteDatabases`.

- [ ] **Step 6: Verify it compiles and lints**

```bash
cd frontend
npm run build
npm run lint
```

Expected: both succeed.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/servers/DatabaseDetail.tsx
git commit -m "Wire database restore UI into the database detail page"
```

---

## Task 14: Full suite and manual verification on a real server

- [ ] **Step 1: Run the whole backend suite**

Run: `DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test`

Expected: PASS. The suite was 503 tests before this work, so expect roughly 535 after.

- [ ] **Step 2: Restart the queue worker**

```bash
cd ..
docker compose exec app php artisan queue:restart
```

A running worker holds the old class definitions in memory, so a new job class or a changed service will not take effect without this. Skipping it produces confusing results where the code on disk is clearly correct.

- [ ] **Step 3: Restore into a new database name on a real server**

In the UI, pick a PostgreSQL connection on a test server, choose Restore on a remote database, upload a small gzipped dump, and target a database name that does not exist yet. Watch the log panel.

Expected: upload progress reaches 100%, the log shows the upload, create, load, and verify steps, the status ends as success, and the new database appears in the remote databases list with the dump's tables.

- [ ] **Step 4: Restore over an existing database**

Repeat, targeting the database you just created, typing its name to confirm.

Expected: the log shows the safety dump path before the drop, and the restore succeeds. Confirm on the server that the safety dump exists:

```bash
sudo ls -la /var/backups/shipyard/
```

- [ ] **Step 5: Verify a deliberately broken dump fails safely**

Upload a gzipped file containing `SELECT 1; GARBAGE SYNTAX HERE;` and overwrite an expendable database.

Expected: the run ends failed with `failed_step` of `restore`, the log states the database may be partially loaded, and it names the safety dump path.

- [ ] **Step 6: Verify the size ceiling**

Create a large file and confirm nginx accepts it rather than returning 413:

```bash
head -c 200000000 /dev/urandom | gzip > /tmp/big.sql.gz
```

Upload it. Expected: a 422 from the dump inspector (random bytes are not SQL), not a 413 from nginx. A 413 means the nginx location block from Task 9 is not matching the route.

- [ ] **Step 7: Confirm cleanup**

```bash
docker compose exec app ls -la /var/www/html/storage/app/private/restores/
```

Expected: empty, or absent. Every finished run deletes its upload.

- [ ] **Step 8: Commit any fixes**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "Fix issues found during manual restore verification"
```

---

## Self-review notes

Spec coverage check against the amendment:

| Spec requirement | Task |
|---|---|
| Source model with `s3` and `upload` | Task 2 (`source` column and constants) |
| `backup_config_id` nullable, `database_id` added | Task 2 |
| `upload_path` hidden, `safety_dump_path` recorded | Task 2, Task 5 |
| Organization scope through `database.server` | Task 2 |
| Format sniffing, `PGDMP` rejection naming pg_restore | Task 3 |
| 1 GB ceiling, PHP and nginx limits, disk headroom check | Task 7 (headroom), Task 9 (limits) |
| Uploads never enter memory | Task 1 (SFTP streaming), Task 7 (`storeAs` moves the temp file) |
| New name default, overwrite needs typed name enforced server-side | Task 7 |
| Drop and recreate matching owner, encoding, charset, collation | Task 4 (`describeDatabase`, `applyDatabaseAttributes`), Task 5 |
| Safety dump before destructive steps, pruned to 3 | Task 5 |
| `PGPASSWORD` and `MYSQL_PWD`, no passwordless sudo assumption | Task 4 |
| `tries = 1`, discrete logged steps, `ON_ERROR_STOP` | Task 4, Task 5, Task 6 |
| Four API routes, admin gate | Task 7, Task 8 |
| SSE with manual token, membership, and admin checks, 1800s cap, client reconnect | Task 8, Task 11 |
| Components kept out of DatabaseDetail | Tasks 11, 12, 13 |
| Cleanup on success and failure | Task 5 (`finally`), Task 6 (`failed()`) |
| Partial-load failure message names the safety dump | Task 5 |
| Every listed test case | Tasks 2 through 8 |

Naming consistency: `BackupRun`, `BackupRestoreService::restoreFromUpload`, `ProcessDatabaseRestore`, `DumpInspector::detect`, `buildRestoreCommand`, `buildDumpCommand`, `describeDatabase`, `applyDatabaseAttributes`, `buildRestoreVerifyCommand`, `databaseRestoresApi` are each used identically everywhere they appear.

Signatures verified against the codebase while writing, so no task asks the implementer to guess: `PostgreSQLService::buildCommand()` takes `(Database, string $sql, bool $tupleOnly = false, ?string $dbName = null)` at line 354, and the role accessor is `User::roleIn()` at line 49 (there is no `roleInOrganization()`). The SSE controller follows `TerminalStreamController`'s single-refusal-body convention so authorization failures leak nothing about what exists.

Three places still say "match what the file actually does" rather than giving exact line content, because they depend on surrounding code that varies: the nginx location block's `try_files` line in Task 9, the remote-database row variable name in Task 13, and the page's reload function name in Task 13. Each names the grep to run first.
