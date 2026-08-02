# Database Backups with Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scheduled and manual MySQL/PostgreSQL backups to S3-compatible storage, running self-contained on target servers via cron, with restore, retention pruning, and failure notifications.

**Architecture:** ShipYard renders a bash backup script and an rclone config onto the target server and registers a cron line in the existing managed crontab block. The script dumps, uploads with rclone, prunes to keep-last-N, and reports to a callback endpoint. Restores and manual runs are ShipYard-orchestrated over SSH. See the approved spec at `docs/superpowers/specs/2026-07-28-database-backups-design.md`.

**Tech Stack:** Laravel 12 (PHP 8.2), phpseclib via `SSHService`, rclone on target servers, `dragonmantank/cron-expression` (already a Laravel dependency) for overdue math.

**Scope:** Backend only (API, jobs, server-side machinery). The frontend UI (destinations settings page, backups tab on DatabaseDetail, restore modal) is a separate follow-up plan.

**Working directory:** `backend/`. Run tests with `php artisan test --filter=<Name>` (local Herd setup with the testing MySQL on port 33061; use `docker compose exec app php artisan test` if the Docker stack is running instead). Format with `./vendor/bin/pint --dirty` before each commit.

**Conventions used below:**
- Every model/table follows existing patterns: `ScheduledTask` for install lifecycle, `NotificationChannel` for encrypted org-scoped config, `CrontabService` for the managed cron block.
- `CurrentOrganization` is `App\Support\CurrentOrganization`.
- Feature tests use `Tests\TestCase::createOrgUser()` which binds the org context so factories land in the acting user's organization.

---

## Task 1: BackupDestination migration, model, factory

**Files:**
- Create: `database/migrations/2026_07_29_000001_create_backup_destinations_table.php`
- Create: `app/Models/BackupDestination.php`
- Create: `database/factories/BackupDestinationFactory.php`
- Test: `tests/Feature/BackupDestinationModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupDestinationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_at_rest_and_hidden_in_serialization(): void
    {
        $this->createOrgUser();

        $destination = BackupDestination::factory()->create([
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'super-secret-value',
        ]);

        $raw = DB::table('backup_destinations')->where('id', $destination->id)->first();
        $this->assertStringNotContainsString('AKIAEXAMPLE', $raw->access_key);
        $this->assertStringNotContainsString('super-secret-value', $raw->secret_key);

        $serialized = $destination->toArray();
        $this->assertArrayNotHasKey('access_key', $serialized);
        $this->assertArrayNotHasKey('secret_key', $serialized);

        $this->assertSame('AKIAEXAMPLE', $destination->access_key);
    }

    public function test_destination_lands_in_current_organization(): void
    {
        $user = $this->createOrgUser();

        $destination = BackupDestination::factory()->create();

        $this->assertSame($user->currentOrganization->id, $destination->organization_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupDestinationModelTest`
Expected: FAIL with `Class "App\Models\BackupDestination" not found`

- [ ] **Step 3: Write migration, model, factory**

Migration `database/migrations/2026_07_29_000001_create_backup_destinations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('endpoint')->nullable(); // null = AWS S3 default addressing
            $table->string('region', 64)->nullable();
            $table->string('bucket');
            $table->string('path_prefix')->nullable();
            $table->text('access_key');
            $table->text('secret_key');
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_destinations');
    }
};
```

Model `app/Models/BackupDestination.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupDestination extends Model
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'name',
        'endpoint',
        'region',
        'bucket',
        'path_prefix',
        'access_key',
        'secret_key',
    ];

    protected $hidden = [
        'access_key',
        'secret_key',
    ];

    protected function casts(): array
    {
        return [
            'access_key' => 'encrypted',
            'secret_key' => 'encrypted',
        ];
    }

    public function backupConfigs(): HasMany
    {
        return $this->hasMany(BackupConfig::class);
    }

    /**
     * The rclone remote name used inside the conf file on servers.
     */
    public function remoteName(): string
    {
        return "shipyard-dest-{$this->id}";
    }
}
```

Factory `database/factories/BackupDestinationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupDestinationFactory extends Factory
{
    protected $model = BackupDestination::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'endpoint' => 'https://minio.example.com',
            'region' => 'us-east-1',
            'bucket' => fake()->slug(2),
            'path_prefix' => 'backups',
            'access_key' => fake()->lexify('AKIA????????????'),
            'secret_key' => fake()->sha256(),
        ];
    }
}
```

Note: `BackupConfig` does not exist yet; the `backupConfigs()` relation resolves lazily so the model still loads. It gets created in Task 3.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupDestinationModelTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup destination model with encrypted S3 credentials"
```

---

## Task 2: BackupDestinationController, routes, cross-org test

**Files:**
- Create: `app/Http/Controllers/Api/BackupDestinationController.php`
- Modify: `routes/api.php` (inside the `auth:sanctum, org.context, org.writes` group, next to the notification-channels block)
- Test: `tests/Feature/BackupDestinationApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupDestinationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_lifecycle(): void
    {
        $user = $this->createOrgUser();

        $create = $this->actingAs($user)->postJson('/api/backup-destinations', [
            'name' => 'R2 main',
            'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'shipyard-backups',
            'path_prefix' => 'prod',
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'topsecret',
        ]);

        $create->assertCreated()
            ->assertJsonPath('name', 'R2 main')
            ->assertJsonMissingPath('access_key')
            ->assertJsonMissingPath('secret_key');

        $id = $create->json('id');

        $this->actingAs($user)->getJson('/api/backup-destinations')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($user)->putJson("/api/backup-destinations/{$id}", [
            'name' => 'R2 renamed',
            'bucket' => 'shipyard-backups',
        ])->assertOk()->assertJsonPath('name', 'R2 renamed');

        // Update without credential fields keeps the stored credentials.
        $this->assertSame('AKIAEXAMPLE', BackupDestination::findOrFail($id)->access_key);

        $this->actingAs($user)->deleteJson("/api/backup-destinations/{$id}")->assertOk();
        $this->assertDatabaseMissing('backup_destinations', ['id' => $id]);
    }

    public function test_destinations_are_isolated_between_organizations(): void
    {
        $this->createOrgUser();
        $foreign = BackupDestination::factory()->create();

        CurrentOrganization::forget();
        $otherUser = User::factory()->create();
        CurrentOrganization::set($otherUser->currentOrganization, Organization::ROLE_OWNER);

        $this->actingAs($otherUser)->getJson('/api/backup-destinations')
            ->assertOk()
            ->assertJsonCount(0);

        $this->actingAs($otherUser)->getJson("/api/backup-destinations/{$foreign->id}")
            ->assertNotFound();

        $this->actingAs($otherUser)->deleteJson("/api/backup-destinations/{$foreign->id}")
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupDestinationApiTest`
Expected: FAIL with 404s (route not defined)

- [ ] **Step 3: Write controller and routes**

Controller `app/Http/Controllers/Api/BackupDestinationController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupDestinationController extends Controller
{
    public function index(): JsonResponse
    {
        // withCount('backupConfigs') gets added in Task 8, once the
        // backup_configs table exists.
        return response()->json(
            BackupDestination::query()->orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'bucket' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.\-]+$/'],
            'path_prefix' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9\/_\-]+$/'],
            'access_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
        ]);

        $destination = BackupDestination::create($validated);

        return response()->json($destination, 201);
    }

    public function show(BackupDestination $backupDestination): JsonResponse
    {
        return response()->json($backupDestination);
    }

    public function update(Request $request, BackupDestination $backupDestination): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'bucket' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.\-]+$/'],
            'path_prefix' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9\/_\-]+$/'],
            // Credentials are optional on update: blank means keep current.
            'access_key' => ['nullable', 'string', 'max:255'],
            'secret_key' => ['nullable', 'string', 'max:255'],
        ]);

        $backupDestination->update(array_filter($validated, fn ($v) => $v !== null));

        return response()->json($backupDestination->fresh());
    }

    public function destroy(BackupDestination $backupDestination): JsonResponse
    {
        // A "409 while configs exist" guard gets added in Task 8, once the
        // backup_configs table exists (the FK is restrictOnDelete anyway).
        $backupDestination->delete();

        return response()->json(['message' => 'Destination deleted.']);
    }
}
```

Routes in `routes/api.php`, right after the notification-channels lines:

```php
    // Backup destinations (S3-compatible storage)
    Route::apiResource('backup-destinations', BackupDestinationController::class);
```

Add the import at the top: `use App\Http\Controllers\Api\BackupDestinationController;`

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupDestinationApiTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup destination CRUD API"
```

---

## Task 3: BackupConfig and BackupRun migrations, models, factories

**Files:**
- Create: `database/migrations/2026_07_29_000002_create_backup_configs_table.php`
- Create: `database/migrations/2026_07_29_000003_create_backup_runs_table.php`
- Create: `app/Models/BackupConfig.php`
- Create: `app/Models/BackupRun.php`
- Create: `database/factories/BackupConfigFactory.php`
- Create: `database/factories/BackupRunFactory.php`
- Test: `tests/Feature/BackupConfigModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BackupConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupConfigModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_expression_paths_and_home_dir(): void
    {
        $this->createOrgUser();

        $config = BackupConfig::factory()->create([
            'frequency' => 'nightly',
            'user' => 'shipyard',
        ]);

        $this->assertSame('0 0 * * *', $config->cron_expression);
        $this->assertSame('/home/shipyard', $config->homeDir());
        $this->assertSame(
            "/home/shipyard/.shipyard/backups/backup-{$config->id}.sh",
            $config->scriptPath()
        );

        $root = BackupConfig::factory()->create(['user' => 'root', 'frequency' => 'custom', 'minute' => '*/30', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*']);
        $this->assertSame('/root', $root->homeDir());
        $this->assertSame('*/30 * * * *', $root->cron_expression);
    }

    public function test_secret_token_is_generated_and_hidden(): void
    {
        $this->createOrgUser();

        $config = BackupConfig::factory()->create();

        $this->assertNotEmpty($config->secret_token);
        $this->assertSame(48, strlen($config->secret_token));
        $this->assertArrayNotHasKey('secret_token', $config->toArray());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupConfigModelTest`
Expected: FAIL with `Class "App\Models\BackupConfig" not found`

- [ ] **Step 3: Write migrations, models, factories**

Migration `database/migrations/2026_07_29_000002_create_backup_configs_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('backup_destination_id')->constrained()->restrictOnDelete();
            $table->string('database_name', 64);
            $table->string('user', 32)->default('root');
            $table->string('frequency', 20);
            $table->string('minute', 30)->nullable();
            $table->string('hour', 30)->nullable();
            $table->string('day', 30)->nullable();
            $table->string('month', 30)->nullable();
            $table->string('weekday', 30)->nullable();
            $table->unsignedInteger('keep_last')->default(7);
            $table->string('secret_token', 64);
            $table->boolean('enabled')->default(true);
            $table->string('status', 20)->default('installing');
            $table->longText('log')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['database_id', 'database_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_configs');
    }
};
```

Migration `database/migrations/2026_07_29_000003_create_backup_runs_table.php`:

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
            $table->foreignId('backup_config_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('backup');   // backup | restore
            $table->string('trigger', 10)->default('cron');  // cron | manual
            $table->string('status', 20);                    // running | success | failed
            $table->string('failed_step', 20)->nullable();   // dump | upload | prune | restore
            $table->string('s3_key')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->longText('log')->nullable();
            $table->timestamps();

            $table->index(['backup_config_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
```

Model `app/Models/BackupConfig.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupConfig extends Model
{
    use HasFactory;

    // Same presets as ScheduledTask, minus reboot (meaningless for backups).
    public const FREQUENCY_EXPRESSIONS = [
        'minutely' => '* * * * *',
        'hourly' => '0 * * * *',
        'nightly' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
    ];

    protected $appends = ['cron_expression'];

    protected $hidden = ['secret_token'];

    protected $fillable = [
        'database_id',
        'backup_destination_id',
        'database_name',
        'user',
        'frequency',
        'minute',
        'hour',
        'day',
        'month',
        'weekday',
        'keep_last',
        'secret_token',
        'enabled',
        'status',
        'log',
        'last_success_at',
        'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'keep_last' => 'integer',
            'last_success_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BackupConfig $config) {
            if (blank($config->secret_token)) {
                $config->secret_token = Str::random(48);
            }
        });
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function getCronExpressionAttribute(): string
    {
        if ($this->frequency === 'custom') {
            return "{$this->minute} {$this->hour} {$this->day} {$this->month} {$this->weekday}";
        }

        return self::FREQUENCY_EXPRESSIONS[$this->frequency];
    }

    public function homeDir(): string
    {
        return $this->user === 'root' ? '/root' : "/home/{$this->user}";
    }

    public function backupDir(): string
    {
        return "{$this->homeDir()}/.shipyard/backups";
    }

    public function scriptPath(): string
    {
        return "{$this->backupDir()}/backup-{$this->id}.sh";
    }

    public function logPath(): string
    {
        return "{$this->backupDir()}/backup-{$this->id}.log";
    }

    public function rcloneConfPath(): string
    {
        return "{$this->backupDir()}/rclone-{$this->backup_destination_id}.conf";
    }

    public function isInstalled(): bool
    {
        return $this->status === 'installed';
    }

    public function isRemoving(): bool
    {
        return $this->status === 'removing';
    }

    public function markAsInstalled(): void
    {
        $this->update(['status' => 'installed']);
    }

    public function markAsRemoving(): void
    {
        $this->update(['status' => 'removing']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function appendLog(string $message): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $this->log = ($this->log ?? '')."[{$timestamp}] {$message}\n";
        $this->save();
    }
}
```

Model `app/Models/BackupRun.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'backup_config_id',
        'kind',
        'trigger',
        'status',
        'failed_step',
        's3_key',
        'size_bytes',
        'duration_seconds',
        'log',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(BackupConfig::class, 'backup_config_id');
    }
}
```

Factory `database/factories/BackupConfigFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BackupConfig;
use App\Models\BackupDestination;
use App\Models\Database;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupConfigFactory extends Factory
{
    protected $model = BackupConfig::class;

    public function definition(): array
    {
        return [
            'database_id' => Database::factory(),
            'backup_destination_id' => BackupDestination::factory(),
            'database_name' => fake()->regexify('[a-z]{6}_[a-z]{4}'),
            'user' => 'shipyard',
            'frequency' => 'nightly',
            'keep_last' => 7,
            'enabled' => true,
            'status' => 'installed',
        ];
    }

    public function installing(): static
    {
        return $this->state(['status' => 'installing']);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed']);
    }
}
```

Factory `database/factories/BackupRunFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\BackupConfig;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class BackupRunFactory extends Factory
{
    protected $model = BackupRun::class;

    public function definition(): array
    {
        return [
            'backup_config_id' => BackupConfig::factory(),
            'kind' => 'backup',
            'trigger' => 'cron',
            'status' => 'success',
            's3_key' => '20260729-030000.sql.gz',
            'size_bytes' => 1024,
            'duration_seconds' => 12,
        ];
    }
}
```

If `Database::factory()` does not exist yet (check `database/factories/DatabaseFactory.php`), create it:

```php
<?php

namespace Database\Factories;

use App\Models\Database;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatabaseFactory extends Factory
{
    protected $model = Database::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => fake()->words(2, true),
            'type' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'admin_user' => 'root',
            'admin_password' => fake()->password(16),
            'status' => 'active',
        ];
    }

    public function postgresql(): static
    {
        return $this->state(['type' => 'postgresql', 'port' => 5432, 'admin_user' => 'postgres']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupConfigModelTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup config and backup run models"
```

---

## Task 4: BackupScriptService (pure rendering)

**Files:**
- Create: `app/Services/BackupScriptService.php`
- Test: `tests/Feature/BackupScriptServiceTest.php`

This service renders three strings and performs no I/O: the rclone conf, the backup script, and the remote path. Everything user-influenced is either regex-validated at the controller (database_name, cron fields) or shell-escaped here.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BackupConfig;
use App\Models\BackupDestination;
use App\Models\Database;
use App\Services\BackupScriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupScriptServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): BackupScriptService
    {
        return app(BackupScriptService::class);
    }

    public function test_rclone_conf_contains_credentials_and_endpoint(): void
    {
        $this->createOrgUser();
        $destination = BackupDestination::factory()->create([
            'endpoint' => 'https://minio.example.com',
            'region' => 'us-east-1',
            'access_key' => 'AKIAEXAMPLE',
            'secret_key' => 'sekret',
        ]);

        $conf = $this->service()->renderRcloneConf($destination);

        $this->assertStringContainsString("[shipyard-dest-{$destination->id}]", $conf);
        $this->assertStringContainsString('type = s3', $conf);
        $this->assertStringContainsString('access_key_id = AKIAEXAMPLE', $conf);
        $this->assertStringContainsString('secret_access_key = sekret', $conf);
        $this->assertStringContainsString('endpoint = https://minio.example.com', $conf);
        $this->assertStringContainsString('force_path_style = true', $conf);
    }

    public function test_rclone_conf_omits_endpoint_and_path_style_for_aws(): void
    {
        $this->createOrgUser();
        $destination = BackupDestination::factory()->create(['endpoint' => null, 'region' => 'eu-west-1']);

        $conf = $this->service()->renderRcloneConf($destination);

        $this->assertStringNotContainsString('endpoint =', $conf);
        $this->assertStringNotContainsString('force_path_style', $conf);
        $this->assertStringContainsString('provider = AWS', $conf);
        $this->assertStringContainsString('region = eu-west-1', $conf);
    }

    public function test_remote_path_includes_prefix_server_and_database(): void
    {
        $this->createOrgUser();
        $destination = BackupDestination::factory()->create(['bucket' => 'bk', 'path_prefix' => '/prod/']);
        $config = BackupConfig::factory()->create([
            'backup_destination_id' => $destination->id,
            'database_name' => 'shop',
        ]);

        $serverId = $config->database->server_id;

        $this->assertSame(
            "shipyard-dest-{$destination->id}:bk/prod/server-{$serverId}/shop",
            $this->service()->remotePath($config)
        );
    }

    public function test_mysql_backup_script_dump_upload_prune_and_report(): void
    {
        $this->createOrgUser();
        $database = Database::factory()->create([
            'admin_user' => 'root',
            'admin_password' => "p'ss%word",
            'host' => '127.0.0.1',
            'port' => 3306,
        ]);
        $config = BackupConfig::factory()->create([
            'database_id' => $database->id,
            'database_name' => 'shop',
            'keep_last' => 5,
        ]);

        $script = $this->service()->renderBackupScript($config);

        $this->assertStringContainsString('mysqldump --defaults-extra-file=', $script);
        $this->assertStringContainsString('--single-transaction', $script);
        $this->assertStringContainsString("'p'\''ss%word'", $script);
        $this->assertStringContainsString('rclone --config "$RCLONE_CONF" copyto', $script);
        $this->assertStringContainsString('KEEP_LAST=5', $script);
        $this->assertStringContainsString("/api/backup-configs/{$config->id}/report", $script);
        $this->assertStringContainsString($config->secret_token, $script);
        $this->assertStringContainsString('trap', $script);
        $this->assertStringContainsString('[shipyard-backup] OK key=', $script);
    }

    public function test_postgres_backup_script_uses_pg_dump(): void
    {
        $this->createOrgUser();
        $database = Database::factory()->postgresql()->create(['admin_password' => 'pgpass']);
        $config = BackupConfig::factory()->create([
            'database_id' => $database->id,
            'database_name' => 'shop',
        ]);

        $script = $this->service()->renderBackupScript($config);

        $this->assertStringContainsString('pg_dump', $script);
        $this->assertStringContainsString("PGPASSWORD='pgpass'", $script);
        $this->assertStringContainsString('--no-owner', $script);
        $this->assertStringNotContainsString('mysqldump', $script);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupScriptServiceTest`
Expected: FAIL with `Class "App\Services\BackupScriptService" not found`

- [ ] **Step 3: Write the service**

`app/Services/BackupScriptService.php`:

```php
<?php

namespace App\Services;

use App\Models\BackupConfig;
use App\Models\BackupDestination;

/**
 * Renders the artifacts installed on target servers for self-contained
 * backups: the rclone conf, the backup script, and the remote path.
 * Pure string building, no I/O. Values interpolated into shell context
 * are escaped here; database_name and cron fields are regex-validated
 * at the controller and never contain shell metacharacters.
 */
class BackupScriptService
{
    public function renderRcloneConf(BackupDestination $destination): string
    {
        $lines = [
            "[{$destination->remoteName()}]",
            'type = s3',
            'env_auth = false',
            "access_key_id = {$destination->access_key}",
            "secret_access_key = {$destination->secret_key}",
        ];

        if (filled($destination->endpoint)) {
            $lines[] = 'provider = Other';
            $lines[] = "endpoint = {$destination->endpoint}";
            $lines[] = 'force_path_style = true';
        } else {
            $lines[] = 'provider = AWS';
        }

        if (filled($destination->region)) {
            $lines[] = "region = {$destination->region}";
        }

        return implode("\n", $lines)."\n";
    }

    public function remotePath(BackupConfig $config): string
    {
        $destination = $config->destination;
        $prefix = trim((string) $destination->path_prefix, '/');
        $serverId = $config->database->server_id;

        $path = $destination->bucket;
        if ($prefix !== '') {
            $path .= "/{$prefix}";
        }

        return "{$destination->remoteName()}:{$path}/server-{$serverId}/{$config->database_name}";
    }

    public function renderBackupScript(BackupConfig $config): string
    {
        $remote = $this->remotePath($config);
        $callbackUrl = rtrim(config('app.url'), '/')."/api/backup-configs/{$config->id}/report";
        $dumpCommand = $this->dumpCommand($config);
        $confPath = $config->rcloneConfPath();

        return <<<BASH
#!/usr/bin/env bash
set -uo pipefail

RCLONE_CONF='{$confPath}'
REMOTE='{$remote}'
KEEP_LAST={$config->keep_last}
CALLBACK_URL='{$callbackUrl}'
SECRET='{$config->secret_token}'
TRIGGER="\${SHIPYARD_TRIGGER:-cron}"
TMP_DIR="\$HOME/.shipyard/backups/tmp"

TIMESTAMP=\$(date -u +%Y%m%d-%H%M%S)
S3_KEY="\$TIMESTAMP.sql.gz"
FILE="\$TMP_DIR/backup-{$config->id}-\$TIMESTAMP.sql.gz"
STEP=dump
STARTED_AT=\$(date +%s)
PRUNE_FAILED=false

trap 'rm -f "\$FILE"' EXIT

report() {
  if [ "\$TRIGGER" != "cron" ]; then return 0; fi
  local status=\$1
  local duration=\$(( \$(date +%s) - STARTED_AT ))
  local size=0
  if [ -f "\$FILE" ]; then size=\$(stat -c%s "\$FILE" 2>/dev/null || echo 0); fi
  curl -fsS -m 15 -X POST "\$CALLBACK_URL" \
    -H 'Content-Type: application/json' \
    -d "{\\"secret\\":\\"\$SECRET\\",\\"status\\":\\"\$status\\",\\"step\\":\\"\$STEP\\",\\"size_bytes\\":\$size,\\"duration_seconds\\":\$duration,\\"s3_key\\":\\"\$S3_KEY\\",\\"prune_failed\\":\$PRUNE_FAILED}" \
    >/dev/null || true
}

fail() {
  echo "[shipyard-backup] FAILED at step \$STEP"
  report failed
  exit 1
}

mkdir -p "\$TMP_DIR"

echo "[shipyard-backup] config {$config->id}: dumping at \$TIMESTAMP"
{$dumpCommand} || fail

STEP=upload
rclone --config "\$RCLONE_CONF" copyto "\$FILE" "\$REMOTE/\$S3_KEY" || fail

STEP=prune
OBJECTS=\$(rclone --config "\$RCLONE_CONF" lsf "\$REMOTE" | sort | head -n -"\$KEEP_LAST") || PRUNE_FAILED=true
if [ "\$PRUNE_FAILED" = false ] && [ -n "\$OBJECTS" ]; then
  while IFS= read -r OBJ; do
    rclone --config "\$RCLONE_CONF" deletefile "\$REMOTE/\$OBJ" || PRUNE_FAILED=true
  done <<< "\$OBJECTS"
fi
if [ "\$PRUNE_FAILED" = true ]; then
  echo "[shipyard-backup] WARNING: prune failed; the backup itself succeeded"
fi

STEP=done
SIZE=\$(stat -c%s "\$FILE" 2>/dev/null || echo 0)
echo "[shipyard-backup] OK key=\$S3_KEY size=\$SIZE"
report success
BASH;
    }

    /**
     * The dump pipeline for the config's engine. Credentials are shell
     * escaped; the database name is regex-validated ([A-Za-z0-9_]+).
     */
    private function dumpCommand(BackupConfig $config): string
    {
        $database = $config->database;
        $host = escapeshellarg($database->host);
        $port = escapeshellarg((string) $database->port);
        $user = escapeshellarg($database->admin_user);
        $password = escapeshellarg($database->admin_password);
        $name = $config->database_name;

        if ($database->isPostgreSQL()) {
            return "PGPASSWORD={$password} pg_dump -h {$host} -p {$port} -U {$user} --no-owner --no-acl {$name} | gzip > \"\$FILE\"";
        }

        return 'mysqldump --defaults-extra-file=<(printf \'[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n\' '
            ."{$host} {$port} {$user} {$password}) "
            ."--single-transaction --routines --triggers --no-tablespaces {$name} | gzip > \"\$FILE\"";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupScriptServiceTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup script and rclone conf rendering"
```

---

## Task 5: CrontabService includes backup configs in the managed block

**Files:**
- Modify: `app/Services/CrontabService.php`
- Test: `tests/Feature/CrontabServiceTest.php` (add tests to the existing file)

The managed crontab block is the single convergence point for everything ShipYard schedules on a server. Backup cron lines join it so both task and backup writes share the same serialization lock.

- [ ] **Step 1: Write the failing tests (append to `tests/Feature/CrontabServiceTest.php`)**

```php
    public function test_managed_block_includes_enabled_backup_configs_for_same_server_and_user(): void
    {
        $server = \App\Models\Server::factory()->create();
        $database = \App\Models\Database::factory()->create(['server_id' => $server->id]);

        $included = \App\Models\BackupConfig::factory()->create([
            'database_id' => $database->id,
            'user' => 'www-data',
            'frequency' => 'nightly',
        ]);
        \App\Models\BackupConfig::factory()->create([
            'database_id' => $database->id,
            'user' => 'www-data',
            'enabled' => false,
        ]);
        \App\Models\BackupConfig::factory()->create([
            'database_id' => $database->id,
            'user' => 'www-data',
            'status' => 'removing',
        ]);
        \App\Models\BackupConfig::factory()->create([
            'database_id' => $database->id,
            'user' => 'root',
        ]);

        $block = $this->service()->buildManagedBlock($server, 'www-data');

        $this->assertStringContainsString("# ShipYard backup {$included->id}", $block);
        $this->assertStringContainsString(
            "0 0 * * * bash \"\$HOME/.shipyard/backups/backup-{$included->id}.sh\" >> \"\$HOME/.shipyard/backups/backup-{$included->id}.log\" 2>&1",
            $block
        );
        $this->assertSame(1, substr_count($block, '# ShipYard backup '));
    }

    public function test_managed_block_with_only_backups_still_has_markers(): void
    {
        $server = \App\Models\Server::factory()->create();
        $database = \App\Models\Database::factory()->create(['server_id' => $server->id]);
        \App\Models\BackupConfig::factory()->create(['database_id' => $database->id, 'user' => 'shipyard']);

        $block = $this->service()->buildManagedBlock($server, 'shipyard');

        $this->assertStringStartsWith('# BEGIN SHIPYARD MANAGED TASKS - DO NOT EDIT', $block);
        $this->assertStringEndsWith('# END SHIPYARD MANAGED TASKS', $block);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CrontabServiceTest`
Expected: the two new tests FAIL (no backup lines in block); existing tests PASS

- [ ] **Step 3: Modify `CrontabService`**

Add the import at the top of `app/Services/CrontabService.php`:

```php
use App\Models\BackupConfig;
```

Replace `buildManagedBlock` with:

```php
    public function buildManagedBlock(Server $server, string $user): string
    {
        $tasks = ScheduledTask::query()
            ->where('server_id', $server->id)
            ->where('user', $user)
            ->whereIn('status', ['installing', 'installed'])
            ->orderBy('id')
            ->get();

        $backups = BackupConfig::query()
            ->whereHas('database', fn ($q) => $q->where('server_id', $server->id))
            ->where('user', $user)
            ->where('enabled', true)
            ->whereIn('status', ['installing', 'installed'])
            ->orderBy('id')
            ->get();

        if ($tasks->isEmpty() && $backups->isEmpty()) {
            return '';
        }

        $lines = [self::BLOCK_BEGIN];

        foreach ($tasks as $task) {
            $lines[] = "# ShipYard task {$task->id}";
            $lines[] = $this->renderCronLine($task);
        }

        foreach ($backups as $backup) {
            $lines[] = "# ShipYard backup {$backup->id}";
            $lines[] = $this->renderBackupCronLine($backup);
        }

        $lines[] = self::BLOCK_END;

        return implode("\n", $lines);
    }

    public function renderBackupCronLine(BackupConfig $config): string
    {
        // $HOME expands at run time in the crontab command (sh -c), so the
        // same line works for root and home-directory users alike.
        return "{$config->cron_expression} bash \"\$HOME/.shipyard/backups/backup-{$config->id}.sh\""
            ." >> \"\$HOME/.shipyard/backups/backup-{$config->id}.log\" 2>&1";
    }
```

Also extract a public sync entry point for backup jobs. Add below `removeTask`:

```php
    /**
     * Re-syncs the managed block for a server+user. Backup install and
     * removal jobs call this after placing or removing their artifacts;
     * callers must hold the same WithoutOverlapping crontab lock the
     * scheduled-task jobs use.
     */
    public function syncManagedBlock(Server $server, string $user): void
    {
        $this->syncCrontab($server, $user);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CrontabServiceTest`
Expected: PASS (all, including the pre-existing tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: include backup configs in the managed crontab block"
```

---

## Task 6: BackupConfigService (install and remove artifacts over SSH)

**Files:**
- Create: `app/Services/BackupConfigService.php`
- Test: `tests/Feature/BackupConfigServiceTest.php`

- [ ] **Step 1: Write the failing test**

The SSH mock pattern is copied from `CrontabServiceTest` (capture uploaded scripts and executed commands).

```php
<?php

namespace Tests\Feature;

use App\Models\BackupConfig;
use App\Models\Database;
use App\Models\Server;
use App\Services\BackupConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    private function mockSsh(bool $succeeds = true): void
    {
        $this->uploadedScripts = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($succeeds) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($succeeds) {
                if (str_starts_with($command, 'bash ')) {
                    return ['output' => '', 'exit_code' => $succeeds ? 0 : 1, 'success' => $succeeds];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function makeConfig(): BackupConfig
    {
        $this->createOrgUser();
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);

        return BackupConfig::factory()->installing()->create([
            'database_id' => $database->id,
            'user' => 'shipyard',
        ]);
    }

    public function test_install_ships_rclone_setup_files_and_syncs_crontab(): void
    {
        $config = $this->makeConfig();
        $this->mockSsh();

        app(BackupConfigService::class)->install($config);

        $all = implode("\n===\n", $this->uploadedScripts);

        $this->assertStringContainsString('command -v rclone', $all);
        $this->assertStringContainsString('apt-get install -y -qq rclone', $all);
        $this->assertStringContainsString("backup-{$config->id}.sh", $all);
        $this->assertStringContainsString("rclone-{$config->backup_destination_id}.conf", $all);
        $this->assertStringContainsString('chmod 0700', $all);
        $this->assertStringContainsString('chmod 0600', $all);
        $this->assertStringContainsString("chown 'shipyard':", $all);
        // Crontab sync ran too (managed block markers shipped in a script).
        $this->assertStringContainsString('BEGIN SHIPYARD MANAGED TASKS', $all);
        $this->assertStringContainsString("# ShipYard backup {$config->id}", $all);
    }

    public function test_remove_deletes_script_and_conf_when_last_config_for_destination(): void
    {
        $config = $this->makeConfig();
        $config->markAsRemoving();
        $this->mockSsh();

        app(BackupConfigService::class)->remove($config);

        $all = implode("\n===\n", $this->uploadedScripts);

        $this->assertStringContainsString("rm -f '{$config->scriptPath()}'", $all);
        $this->assertStringContainsString("rm -f '{$config->logPath()}'", $all);
        $this->assertStringContainsString("rm -f '{$config->rcloneConfPath()}'", $all);
    }

    public function test_remove_keeps_conf_when_destination_still_used_on_server(): void
    {
        $config = $this->makeConfig();
        BackupConfig::factory()->create([
            'database_id' => $config->database_id,
            'backup_destination_id' => $config->backup_destination_id,
            'user' => 'shipyard',
        ]);
        $config->markAsRemoving();
        $this->mockSsh();

        app(BackupConfigService::class)->remove($config);

        $all = implode("\n===\n", $this->uploadedScripts);

        $this->assertStringContainsString("rm -f '{$config->scriptPath()}'", $all);
        $this->assertStringNotContainsString("rm -f '{$config->rcloneConfPath()}'", $all);
    }

    public function test_install_failure_throws(): void
    {
        $config = $this->makeConfig();
        $this->mockSsh(succeeds: false);

        $this->expectException(\RuntimeException::class);

        app(BackupConfigService::class)->install($config);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupConfigServiceTest`
Expected: FAIL with `Class "App\Services\BackupConfigService" not found`

- [ ] **Step 3: Write the service**

`app/Services/BackupConfigService.php`:

```php
<?php

namespace App\Services;

use App\Models\BackupConfig;
use App\Services\Concerns\RunsRemoteScripts;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Places and removes the on-server artifacts for a backup config (rclone
 * binary, rclone conf, backup script) and re-syncs the managed crontab
 * block. Callers (the backup jobs) must hold the shared crontab lock for
 * the server+user, since this ends with a crontab read-modify-write.
 */
class BackupConfigService
{
    use RunsRemoteScripts;

    public function __construct(
        protected SSHService $sshService,
        protected BackupScriptService $scriptService,
        protected CrontabService $crontabService,
    ) {}

    public function install(BackupConfig $config): void
    {
        $server = $config->database->server;
        $user = $config->user;
        $quotedUser = escapeshellarg($user);
        $backupDir = escapeshellarg($config->backupDir());
        $shipyardDir = escapeshellarg($config->homeDir().'/.shipyard');
        $tmpDir = escapeshellarg($config->backupDir().'/tmp');

        $conf = $this->scriptService->renderRcloneConf($config->destination);
        $script = $this->scriptService->renderBackupScript($config);

        // Randomized heredoc delimiters: file content can never terminate
        // the heredoc early (same defense CrontabService uses).
        $confDelimiter = 'SHIPYARD_CONF_'.Str::random(32);
        $scriptDelimiter = 'SHIPYARD_SCRIPT_'.Str::random(32);
        $confTmp = '/tmp/shipyard-conf-'.Str::random(32);
        $scriptTmp = '/tmp/shipyard-backup-'.Str::random(32);
        $confPath = escapeshellarg($config->rcloneConfPath());
        $scriptPath = escapeshellarg($config->scriptPath());

        $remote = <<<BASH
set -euo pipefail

if [ "\$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi

if ! command -v rclone >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  \$SUDO apt-get update -qq
  \$SUDO apt-get install -y -qq rclone
fi

\$SUDO mkdir -p {$tmpDir}
\$SUDO chown -R {$quotedUser}: {$shipyardDir}
\$SUDO chmod 0700 {$shipyardDir} {$backupDir}

touch {$confTmp} && chmod 600 {$confTmp}
cat > {$confTmp} <<'{$confDelimiter}'
{$conf}
{$confDelimiter}
\$SUDO cp {$confTmp} {$confPath}
rm -f {$confTmp}
\$SUDO chown {$quotedUser}: {$confPath}
\$SUDO chmod 0600 {$confPath}

touch {$scriptTmp} && chmod 600 {$scriptTmp}
cat > {$scriptTmp} <<'{$scriptDelimiter}'
{$script}
{$scriptDelimiter}
\$SUDO cp {$scriptTmp} {$scriptPath}
rm -f {$scriptTmp}
\$SUDO chown {$quotedUser}: {$scriptPath}
\$SUDO chmod 0700 {$scriptPath}
BASH;

        $result = $this->runRemoteScript($server, $remote, 300);

        if (! $result['success']) {
            throw new RuntimeException('Failed to install backup artifacts: '.$result['output']);
        }

        $this->crontabService->syncManagedBlock($server, $user);
    }

    public function remove(BackupConfig $config): void
    {
        $server = $config->database->server;

        // Status is already "removing", so the rebuilt block excludes it.
        $this->crontabService->syncManagedBlock($server, $config->user);

        $scriptPath = escapeshellarg($config->scriptPath());
        $logPath = escapeshellarg($config->logPath());

        $lines = [
            'set -euo pipefail',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            "\$SUDO rm -f {$scriptPath}",
            "\$SUDO rm -f {$logPath}",
        ];

        if (! $this->destinationStillUsedOnServer($config)) {
            $confPath = escapeshellarg($config->rcloneConfPath());
            $lines[] = "\$SUDO rm -f {$confPath}";
        }

        $result = $this->runRemoteScript($server, implode("\n", $lines), 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to remove backup artifacts: '.$result['output']);
        }
    }

    /**
     * @return array{output: string, exists: bool}
     */
    public function readOutput(BackupConfig $config, int $lines = 200): array
    {
        $lines = max(1, min($lines, 2000));
        $path = escapeshellarg($config->logPath());
        $marker = '__SHIPYARD_NO_LOG__';
        $command = "if [ -f {$path} ]; then tail -n {$lines} {$path}; else echo '{$marker}'; fi";

        try {
            $this->sshService->connect($config->database->server);
            $result = $this->sshService->execute($command, 30);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to read backup output: '.$result['output']);
        }

        if (str_contains($result['output'], $marker)) {
            return ['output' => '', 'exists' => false];
        }

        return ['output' => $result['output'], 'exists' => true];
    }

    private function destinationStillUsedOnServer(BackupConfig $config): bool
    {
        return BackupConfig::query()
            ->where('id', '!=', $config->id)
            ->where('backup_destination_id', $config->backup_destination_id)
            ->where('user', $config->user)
            ->whereIn('status', ['installing', 'installed'])
            ->whereHas('database', fn ($q) => $q->where('server_id', $config->database->server_id))
            ->exists();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupConfigServiceTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup artifact install and removal over SSH"
```

---

## Task 7: Install and removal jobs

**Files:**
- Create: `app/Jobs/ProcessBackupConfigInstall.php`
- Create: `app/Jobs/ProcessBackupConfigRemoval.php`
- Test: `tests/Feature/BackupConfigJobTest.php`

Both jobs mirror `ProcessScheduledTaskInstall` exactly, including the shared `WithoutOverlapping` key: crontab writes for the same server+user must never interleave, whether they come from tasks or backups.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackupConfigInstall;
use App\Jobs\ProcessBackupConfigRemoval;
use App\Models\BackupConfig;
use App\Services\BackupConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupConfigJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_job_marks_installed_on_success(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->installing()->create();

        $this->mock(BackupConfigService::class, function ($mock) {
            $mock->shouldReceive('install')->once();
        });

        (new ProcessBackupConfigInstall($config))->handle(app(BackupConfigService::class));

        $this->assertSame('installed', $config->fresh()->status);
    }

    public function test_install_job_failure_marks_failed_with_log(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->installing()->create();

        $job = new ProcessBackupConfigInstall($config);
        $job->failed(new \RuntimeException('apt broke'));

        $fresh = $config->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('apt broke', $fresh->log);
    }

    public function test_removal_job_deletes_the_config(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create(['status' => 'removing']);

        $this->mock(BackupConfigService::class, function ($mock) {
            $mock->shouldReceive('remove')->once();
        });

        (new ProcessBackupConfigRemoval($config))->handle(app(BackupConfigService::class));

        $this->assertDatabaseMissing('backup_configs', ['id' => $config->id]);
    }

    public function test_jobs_share_the_crontab_lock_key(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create(['user' => 'shipyard']);
        $serverId = $config->database->server_id;

        $middleware = (new ProcessBackupConfigInstall($config))->middleware();

        $this->assertCount(1, $middleware);
        // Same key family as ProcessScheduledTaskInstall: crontab:{server}:{user}
        $this->assertStringContainsString("crontab:{$serverId}:shipyard", (string) $middleware[0]->key);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupConfigJobTest`
Expected: FAIL with `Class "App\Jobs\ProcessBackupConfigInstall" not found`

- [ ] **Step 3: Write the jobs**

`app/Jobs/ProcessBackupConfigInstall.php`:

```php
<?php

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Services\BackupConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessBackupConfigInstall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Same rationale as ProcessScheduledTaskInstall: lock-blocked releases
    // count as attempts, real exceptions fail immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 360;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public BackupConfig $config
    ) {}

    public function middleware(): array
    {
        $serverId = $this->config->database->server_id;

        return [
            (new WithoutOverlapping("crontab:{$serverId}:{$this->config->user}"))
                ->shared()
                ->expireAfter(420)
                ->releaseAfter(10),
        ];
    }

    public function handle(BackupConfigService $service): void
    {
        $service->install($this->config);

        $this->config->markAsInstalled();
    }

    public function failed(\Throwable $exception): void
    {
        $config = $this->config->fresh();

        if ($config === null) {
            return;
        }

        $config->appendLog("ERROR: {$exception->getMessage()}");
        $config->markAsFailed();
    }
}
```

`app/Jobs/ProcessBackupConfigRemoval.php`:

```php
<?php

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Services\BackupConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ProcessBackupConfigRemoval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public BackupConfig $config
    ) {}

    public function middleware(): array
    {
        $serverId = $this->config->database->server_id;

        return [
            (new WithoutOverlapping("crontab:{$serverId}:{$this->config->user}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(BackupConfigService $service): void
    {
        $service->remove($this->config);

        $this->config->delete();
    }

    public function failed(\Throwable $exception): void
    {
        $config = $this->config->fresh();

        if ($config === null) {
            return;
        }

        $config->appendLog("ERROR: {$exception->getMessage()}");
        $config->markAsFailed();
    }
}
```

Note on the middleware test: `WithoutOverlapping::$key` is public in Laravel 12. If the assertion errors on access, assert via `(new \ReflectionProperty($middleware[0], 'key'))->getValue($middleware[0])` instead.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupConfigJobTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup config install and removal jobs"
```

---

## Task 8: BackupConfigController, routes, feature tests

**Files:**
- Create: `app/Http/Controllers/Api/BackupConfigController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/BackupConfigApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackupConfigInstall;
use App\Jobs\ProcessBackupConfigRemoval;
use App\Models\BackupConfig;
use App\Models\BackupDestination;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupConfigApiTest extends TestCase
{
    use RefreshDatabase;

    private function setUpStack(): array
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);
        $database = Database::factory()->create(['server_id' => $server->id]);
        $destination = BackupDestination::factory()->create();

        return [$user, $server, $database, $destination];
    }

    public function test_store_creates_config_and_dispatches_install(): void
    {
        Queue::fake();
        [$user, $server, $database, $destination] = $this->setUpStack();

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs",
            [
                'backup_destination_id' => $destination->id,
                'database_name' => 'shop_prod',
                'frequency' => 'nightly',
                'keep_last' => 14,
            ]
        );

        $response->assertStatus(202)
            ->assertJsonPath('database_name', 'shop_prod')
            ->assertJsonPath('status', 'installing')
            ->assertJsonPath('user', 'shipyard')
            ->assertJsonMissingPath('secret_token');

        Queue::assertPushed(ProcessBackupConfigInstall::class);
    }

    public function test_store_rejects_shell_hostile_database_names(): void
    {
        Queue::fake();
        [$user, $server, $database, $destination] = $this->setUpStack();

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs",
            [
                'backup_destination_id' => $destination->id,
                'database_name' => 'shop; rm -rf /',
                'frequency' => 'nightly',
                'keep_last' => 7,
            ]
        )->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_store_rejects_destination_from_another_organization(): void
    {
        Queue::fake();
        [$user, $server, $database] = $this->setUpStack();

        // Created bypassing the org scope: belongs to a different org.
        $foreignDestination = BackupDestination::factory()->create([
            'organization_id' => \App\Models\Organization::factory()->create()->id,
        ]);

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs",
            [
                'backup_destination_id' => $foreignDestination->id,
                'database_name' => 'shop',
                'frequency' => 'nightly',
                'keep_last' => 7,
            ]
        )->assertStatus(422);
    }

    public function test_destroy_marks_removing_and_dispatches_removal(): void
    {
        Queue::fake();
        [$user, $server, $database, $destination] = $this->setUpStack();
        $config = BackupConfig::factory()->create([
            'database_id' => $database->id,
            'backup_destination_id' => $destination->id,
        ]);

        $this->actingAs($user)->deleteJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}"
        )->assertStatus(202);

        $this->assertSame('removing', $config->fresh()->status);
        Queue::assertPushed(ProcessBackupConfigRemoval::class);
    }

    public function test_nested_binding_rejects_mismatched_chain(): void
    {
        [$user, $server, $database, $destination] = $this->setUpStack();
        $otherDatabase = Database::factory()->create(['server_id' => $server->id]);
        $config = BackupConfig::factory()->create([
            'database_id' => $otherDatabase->id,
            'backup_destination_id' => $destination->id,
        ]);

        $this->actingAs($user)->getJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}"
        )->assertNotFound();
    }

    public function test_destination_with_configs_cannot_be_deleted(): void
    {
        [$user, , $database, $destination] = $this->setUpStack();
        BackupConfig::factory()->create([
            'database_id' => $database->id,
            'backup_destination_id' => $destination->id,
        ]);

        $this->actingAs($user)->deleteJson("/api/backup-destinations/{$destination->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('backup_destinations', ['id' => $destination->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupConfigApiTest`
Expected: FAIL with 404 (route not defined)

- [ ] **Step 3: Write controller and routes**

`app/Http/Controllers/Api/BackupConfigController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBackupConfigInstall;
use App\Jobs\ProcessBackupConfigRemoval;
use App\Models\BackupConfig;
use App\Models\Database;
use App\Models\Server;
use App\Services\BackupConfigService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BackupConfigController extends Controller
{
    public function index(Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(
            $database->backupConfigs()->with('destination:id,name,bucket')->orderByDesc('created_at')->get()
        );
    }

    public function store(Request $request, Server $server, Database $database): JsonResponse
    {
        if ($database->server_id !== $server->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $cronFieldRules = [
            'required_if:frequency,custom',
            'nullable',
            'string',
            'max:30',
            'regex:/^[0-9*,\/\-]+$/',
        ];

        $validated = $request->validate([
            'backup_destination_id' => [
                'required',
                'integer',
                // exists: bypasses global scopes; constrain to the current org.
                Rule::exists('backup_destinations', 'id')
                    ->where('organization_id', CurrentOrganization::id()),
            ],
            // Shell-safe subset; interpolated unquoted into the dump command.
            'database_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'frequency' => ['required', 'in:minutely,hourly,nightly,weekly,monthly,custom'],
            'keep_last' => ['required', 'integer', 'min:1', 'max:365'],
            'minute' => $cronFieldRules,
            'hour' => $cronFieldRules,
            'day' => $cronFieldRules,
            'month' => $cronFieldRules,
            'weekday' => $cronFieldRules,
        ]);

        if ($validated['frequency'] !== 'custom') {
            $validated['minute'] = null;
            $validated['hour'] = null;
            $validated['day'] = null;
            $validated['month'] = null;
            $validated['weekday'] = null;
        }

        $config = $database->backupConfigs()->create([
            ...$validated,
            'user' => $server->deploy_user ?? 'root',
            'status' => 'installing',
        ]);

        ProcessBackupConfigInstall::dispatch($config);

        return response()->json($config, 202);
    }

    public function show(Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($backupConfig->load('destination:id,name,bucket'));
    }

    public function update(Request $request, Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $cronFieldRules = [
            'required_if:frequency,custom',
            'nullable',
            'string',
            'max:30',
            'regex:/^[0-9*,\/\-]+$/',
        ];

        $validated = $request->validate([
            'frequency' => ['required', 'in:minutely,hourly,nightly,weekly,monthly,custom'],
            'keep_last' => ['required', 'integer', 'min:1', 'max:365'],
            'enabled' => ['required', 'boolean'],
            'minute' => $cronFieldRules,
            'hour' => $cronFieldRules,
            'day' => $cronFieldRules,
            'month' => $cronFieldRules,
            'weekday' => $cronFieldRules,
        ]);

        if ($validated['frequency'] !== 'custom') {
            $validated['minute'] = null;
            $validated['hour'] = null;
            $validated['day'] = null;
            $validated['month'] = null;
            $validated['weekday'] = null;
        }

        $backupConfig->update([...$validated, 'status' => 'installing']);

        // Re-render script and cron line with the new settings.
        ProcessBackupConfigInstall::dispatch($backupConfig);

        return response()->json($backupConfig, 202);
    }

    public function output(Request $request, Server $server, Database $database, BackupConfig $backupConfig, BackupConfigService $service): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'lines' => ['integer', 'min:1', 'max:2000'],
        ]);

        try {
            $result = $service->readOutput($backupConfig, (int) ($validated['lines'] ?? 200));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($result);
    }

    public function runs(Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(
            $backupConfig->runs()->orderByDesc('created_at')->limit(50)->get()
        );
    }

    public function destroy(Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($backupConfig->isRemoving()) {
            return response()->json(['message' => 'Removal is already in progress.'], 409);
        }

        $backupConfig->markAsRemoving();
        ProcessBackupConfigRemoval::dispatch($backupConfig);

        return response()->json([
            'message' => 'Backup config removal started.',
            'config' => $backupConfig,
        ], 202);
    }
}
```

Add the `backupConfigs` relation to `app/Models/Database.php`:

```php
    public function backupConfigs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BackupConfig::class);
    }
```

Now that the `backup_configs` table exists, finish the two pieces deferred from Task 2 in `BackupDestinationController`:

In `index()`, restore the count:

```php
        return response()->json(
            BackupDestination::query()->withCount('backupConfigs')->orderBy('name')->get()
        );
```

In `destroy()`, add the in-use guard before the delete (replacing the Task 2 comment):

```php
        if ($backupDestination->backupConfigs()->exists()) {
            return response()->json([
                'message' => 'This destination has backup configurations. Delete them first.',
            ], 409);
        }
```

Routes in `routes/api.php`, after the database users block:

```php
    // Database backups
    Route::get('/servers/{server}/databases/{database}/backup-configs', [BackupConfigController::class, 'index']);
    Route::post('/servers/{server}/databases/{database}/backup-configs', [BackupConfigController::class, 'store']);
    Route::get('/servers/{server}/databases/{database}/backup-configs/{backupConfig}', [BackupConfigController::class, 'show']);
    Route::put('/servers/{server}/databases/{database}/backup-configs/{backupConfig}', [BackupConfigController::class, 'update']);
    Route::get('/servers/{server}/databases/{database}/backup-configs/{backupConfig}/output', [BackupConfigController::class, 'output']);
    Route::get('/servers/{server}/databases/{database}/backup-configs/{backupConfig}/runs', [BackupConfigController::class, 'runs']);
    Route::delete('/servers/{server}/databases/{database}/backup-configs/{backupConfig}', [BackupConfigController::class, 'destroy']);
```

Add the import: `use App\Http\Controllers\Api\BackupConfigController;`

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupConfigApiTest`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup config API"
```

---

## Task 9: Report callback endpoint

**Files:**
- Create: `app/Http/Controllers/Api/BackupReportController.php`
- Modify: `routes/api.php` (public section, next to the webhook route)
- Test: `tests/Feature/BackupReportApiTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BackupConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(BackupConfig $config, array $overrides = []): array
    {
        return array_merge([
            'secret' => $config->secret_token,
            'status' => 'success',
            'step' => 'done',
            'size_bytes' => 2048,
            'duration_seconds' => 30,
            's3_key' => '20260729-030000.sql.gz',
            'prune_failed' => false,
        ], $overrides);
    }

    public function test_successful_report_creates_run_and_updates_last_success(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create(['overdue_notified_at' => now()]);

        $this->postJson("/api/backup-configs/{$config->id}/report", $this->payload($config))
            ->assertOk();

        $this->assertDatabaseHas('backup_runs', [
            'backup_config_id' => $config->id,
            'status' => 'success',
            'trigger' => 'cron',
            's3_key' => '20260729-030000.sql.gz',
        ]);

        $fresh = $config->fresh();
        $this->assertNotNull($fresh->last_success_at);
        $this->assertNull($fresh->overdue_notified_at);
    }

    public function test_wrong_secret_is_rejected_and_creates_nothing(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();

        $this->postJson(
            "/api/backup-configs/{$config->id}/report",
            $this->payload($config, ['secret' => 'wrong'])
        )->assertForbidden();

        $this->assertDatabaseCount('backup_runs', 0);
    }

    public function test_failed_report_records_failed_step(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();

        $this->postJson(
            "/api/backup-configs/{$config->id}/report",
            $this->payload($config, ['status' => 'failed', 'step' => 'upload'])
        )->assertOk();

        $this->assertDatabaseHas('backup_runs', [
            'backup_config_id' => $config->id,
            'status' => 'failed',
            'failed_step' => 'upload',
        ]);
        $this->assertNull($config->fresh()->last_success_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupReportApiTest`
Expected: FAIL with 404 (route not defined)

- [ ] **Step 3: Write controller and route**

`app/Http/Controllers/Api/BackupReportController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives run reports from backup scripts on managed servers. Public by
 * design (cron on the server has no user session); each config's secret
 * token is the credential, compared in constant time. Failure alerting
 * is wired here in the notifications task.
 */
class BackupReportController extends Controller
{
    public function store(Request $request, BackupConfig $backupConfig): JsonResponse
    {
        $validated = $request->validate([
            'secret' => ['required', 'string'],
            'status' => ['required', 'in:success,failed'],
            'step' => ['required', 'in:dump,upload,prune,done'],
            'size_bytes' => ['required', 'integer', 'min:0'],
            'duration_seconds' => ['required', 'integer', 'min:0'],
            's3_key' => ['required', 'string', 'max:255'],
            'prune_failed' => ['sometimes', 'boolean'],
        ]);

        if (! hash_equals($backupConfig->secret_token, $validated['secret'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $succeeded = $validated['status'] === 'success';

        $backupConfig->runs()->create([
            'kind' => 'backup',
            'trigger' => 'cron',
            'status' => $validated['status'],
            'failed_step' => $succeeded ? null : $validated['step'],
            's3_key' => $succeeded ? $validated['s3_key'] : null,
            'size_bytes' => $validated['size_bytes'],
            'duration_seconds' => $validated['duration_seconds'],
        ]);

        if ($succeeded) {
            $backupConfig->update([
                'last_success_at' => now(),
                'overdue_notified_at' => null,
            ]);
        }

        return response()->json(['message' => 'Recorded.']);
    }
}
```

Route in `routes/api.php`, in the public section next to the webhook route:

```php
// Backup run reports (validated by per-config secret)
Route::post('/backup-configs/{backupConfig}/report', [BackupReportController::class, 'store'])
    ->middleware('throttle:60,1');
```

Add the import: `use App\Http\Controllers\Api\BackupReportController;`

Note: `BackupConfig` carries no global organization scope (it is tenant-owned through database to server), so public route model binding resolves it without an org context, same as the deploy webhook resolves `{application}`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupReportApiTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup run report callback endpoint"
```

---

## Task 10: Notification payload interface, backup payload, notification job

**Files:**
- Create: `app/Services/Notifications/NotificationPayload.php` (interface)
- Create: `app/Services/Notifications/BackupNotificationPayload.php`
- Create: `app/Jobs/SendBackupNotification.php`
- Modify: `app/Services/Notifications/DeploymentNotificationPayload.php`
- Modify: `app/Services/Notifications/NotificationDriverInterface.php`
- Modify: `app/Services/Notifications/NotificationService.php`
- Modify: `app/Services/Notifications/DiscordDriver.php`
- Modify: `app/Services/Notifications/TelegramDriver.php`
- Modify: `app/Services/Notifications/ResendEmailDriver.php`
- Modify: `app/Models/NotificationChannel.php`
- Modify: `app/Http/Controllers/Api/BackupReportController.php` (dispatch on failure)
- Test: `tests/Feature/BackupNotificationTest.php`

The three drivers currently consume `DeploymentNotificationPayload` directly. Extract the members they use into a `NotificationPayload` interface so a backup payload can flow through the same drivers. Deployment detail markup (backticks around commits in Discord, `<code>` in Telegram) flattens to plain label/value pairs; acceptable loss. The existing `DeploymentNotificationTest` guards against regressions.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendBackupNotification;
use App\Models\BackupConfig;
use App\Models\NotificationChannel;
use App\Services\Notifications\BackupNotificationPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_report_dispatches_backup_notification(): void
    {
        Queue::fake();
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();

        $this->postJson("/api/backup-configs/{$config->id}/report", [
            'secret' => $config->secret_token,
            'status' => 'failed',
            'step' => 'upload',
            'size_bytes' => 0,
            'duration_seconds' => 5,
            's3_key' => 'x.sql.gz',
        ])->assertOk();

        Queue::assertPushed(SendBackupNotification::class, function ($job) {
            return $job->event === NotificationChannel::EVENT_BACKUP_FAILED;
        });
    }

    public function test_successful_report_dispatches_nothing(): void
    {
        Queue::fake();
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();

        $this->postJson("/api/backup-configs/{$config->id}/report", [
            'secret' => $config->secret_token,
            'status' => 'success',
            'step' => 'done',
            'size_bytes' => 100,
            'duration_seconds' => 5,
            's3_key' => 'x.sql.gz',
        ])->assertOk();

        Queue::assertNotPushed(SendBackupNotification::class);
    }

    public function test_notification_job_sends_only_to_same_org_subscribed_channels(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();

        NotificationChannel::factory()->create([
            'type' => NotificationChannel::TYPE_DISCORD,
            'config' => ['webhook_url' => 'https://discord.test/hook'],
            'events' => [NotificationChannel::EVENT_BACKUP_FAILED],
            'is_enabled' => true,
        ]);
        NotificationChannel::factory()->create([
            'type' => NotificationChannel::TYPE_DISCORD,
            'config' => ['webhook_url' => 'https://discord.test/other'],
            'events' => [NotificationChannel::EVENT_FAILED], // deployments only
            'is_enabled' => true,
        ]);

        (new SendBackupNotification($config, NotificationChannel::EVENT_BACKUP_FAILED, 'upload'))
            ->handle(app(\App\Services\Notifications\NotificationService::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/hook');
    }

    public function test_backup_payload_titles(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create(['database_name' => 'shop']);

        $failed = BackupNotificationPayload::fromConfig($config, NotificationChannel::EVENT_BACKUP_FAILED, 'dump');
        $this->assertStringContainsString('shop', $failed->title());
        $this->assertFalse($failed->succeeded());

        $overdue = BackupNotificationPayload::fromConfig($config, NotificationChannel::EVENT_BACKUP_OVERDUE, null);
        $this->assertStringContainsString('overdue', strtolower($overdue->title()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupNotificationTest`
Expected: FAIL with `Undefined constant ... EVENT_BACKUP_FAILED`

- [ ] **Step 3: Implement**

3a. Interface `app/Services/Notifications/NotificationPayload.php`:

```php
<?php

namespace App\Services\Notifications;

interface NotificationPayload
{
    public function succeeded(): bool;

    public function title(): string;

    /** Email subject line. */
    public function subject(): string;

    public function url(): string;

    /** Link text, e.g. "View deployment". */
    public function linkLabel(): string;

    /** ISO8601 timestamp for the event, or null. */
    public function timestamp(): ?string;

    /** Preformatted failure excerpt, or null. */
    public function excerpt(): ?string;

    /** @return array<string, string> label => value detail pairs */
    public function details(): array;
}
```

3b. `DeploymentNotificationPayload` implements it. Add `implements NotificationPayload` to the class declaration and add these methods (keep every existing member; `title()` already exists):

```php
    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function subject(): string
    {
        return "[ShipYard] {$this->appName}: {$this->eventLabel()} {$this->resultLabel()}";
    }

    public function url(): string
    {
        return $this->url;
    }

    public function linkLabel(): string
    {
        return 'View deployment';
    }

    public function timestamp(): ?string
    {
        return $this->finishedAt;
    }

    public function excerpt(): ?string
    {
        return $this->failureExcerpt;
    }

    public function details(): array
    {
        $details = [];

        if ($this->branch !== null) {
            $details['Branch'] = $this->branch;
        }

        if ($this->commitShort !== null) {
            $details['Commit'] = $this->commitShort
                .($this->commitMessage !== null ? " {$this->commitMessage}" : '');
        }

        if ($this->durationHuman() !== null) {
            $details['Duration'] = $this->durationHuman();
        }

        return $details;
    }
```

Conflict note: the class has a public property `$succeeded` and gains a method `succeeded()`. PHP allows a property and a method with the same name; no rename needed.

3c. Retarget the drivers at the interface.

`NotificationDriverInterface`: change the signature to

```php
    public function send(NotificationChannel $channel, NotificationPayload $payload): void;
```

`NotificationService`: change `send()` to accept `NotificationPayload $payload`.

`DiscordDriver::send()` becomes:

```php
    public function send(NotificationChannel $channel, NotificationPayload $payload): void
    {
        $embed = [
            'title' => ($payload->succeeded() ? '✅ ' : '❌ ').$payload->title(),
            'url' => $payload->url(),
            'color' => $payload->succeeded() ? self::COLOR_SUCCESS : self::COLOR_FAILURE,
            'fields' => collect($payload->details())
                ->map(fn ($value, $name) => ['name' => $name, 'value' => $value, 'inline' => true])
                ->values()
                ->all(),
        ];

        if ($payload->timestamp() !== null) {
            $embed['timestamp'] = $payload->timestamp();
        }

        if ($payload->excerpt() !== null) {
            $excerpt = str_replace('`', "'", $payload->excerpt());
            $embed['description'] = "```\n{$excerpt}\n```";
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post($channel->config['webhook_url'], ['embeds' => [$embed]]);

        if (! $response->successful()) {
            throw new NotificationSendException("Discord webhook returned HTTP {$response->status()}");
        }
    }
```

Delete `DiscordDriver::fields()`.

`TelegramDriver`: change `send()` and `text()` signatures to `NotificationPayload`, and replace the body of `text()` with:

```php
    private function text(NotificationPayload $payload): string
    {
        // Telegram's HTML parse mode rejects the whole message on any
        // unescaped < or &, so every interpolated value gets escaped.
        $e = fn (?string $v) => htmlspecialchars($v ?? '', ENT_QUOTES);

        $lines = [
            ($payload->succeeded() ? '✅' : '❌')." <b>{$e($payload->title())}</b>",
        ];

        $details = [];
        foreach ($payload->details() as $label => $value) {
            $details[] = strtolower($label).' '.$e($value);
        }
        if ($details !== []) {
            $lines[] = implode(' · ', $details);
        }

        if ($payload->excerpt() !== null) {
            $lines[] = "<pre>{$e($payload->excerpt())}</pre>";
        }

        $lines[] = "<a href=\"{$e($payload->url())}\">{$e($payload->linkLabel())}</a>";

        return implode("\n", $lines);
    }
```

`ResendEmailDriver`: change `send()`, `html()`, `text()`, `details()` signatures to `NotificationPayload`; in `send()` use `'subject' => $payload->subject()`; in `html()`/`text()` replace `$payload->succeeded` with `$payload->succeeded()`, `$payload->failureExcerpt` with `$payload->excerpt()`, `$payload->url` with `$payload->url()`, and the link text with `$payload->linkLabel()`; replace the private `details()` method body with `return $payload->details();`.

3d. Events on `app/Models/NotificationChannel.php`:

```php
    public const EVENT_BACKUP_FAILED = 'backup_failed';

    public const EVENT_RESTORE_FAILED = 'restore_failed';

    public const EVENT_BACKUP_OVERDUE = 'backup_overdue';

    public const EVENTS = [
        self::EVENT_SUCCEEDED,
        self::EVENT_FAILED,
        self::EVENT_BACKUP_FAILED,
        self::EVENT_RESTORE_FAILED,
        self::EVENT_BACKUP_OVERDUE,
    ];
```

3e. `app/Services/Notifications/BackupNotificationPayload.php`:

```php
<?php

namespace App\Services\Notifications;

use App\Models\BackupConfig;
use App\Models\NotificationChannel;

final readonly class BackupNotificationPayload implements NotificationPayload
{
    public function __construct(
        public string $event,
        public string $databaseName,
        public string $serverName,
        public ?string $failedStep,
        public string $url,
        public ?string $timestamp,
    ) {}

    public static function fromConfig(BackupConfig $config, string $event, ?string $failedStep): self
    {
        $database = $config->database;
        $server = $database->server;

        return new self(
            event: $event,
            databaseName: $config->database_name,
            serverName: $server->name ?? "server {$server->id}",
            failedStep: $failedStep,
            url: secure_url("/servers/{$server->id}/databases/{$database->id}"),
            timestamp: now()->toIso8601String(),
        );
    }

    public function succeeded(): bool
    {
        return false; // Only failure events exist for backups in v1.
    }

    public function title(): string
    {
        return match ($this->event) {
            NotificationChannel::EVENT_RESTORE_FAILED => "Restore failed: {$this->databaseName} on {$this->serverName}",
            NotificationChannel::EVENT_BACKUP_OVERDUE => "Backup overdue: {$this->databaseName} on {$this->serverName}",
            default => "Backup failed: {$this->databaseName} on {$this->serverName}",
        };
    }

    public function subject(): string
    {
        return "[ShipYard] {$this->title()}";
    }

    public function url(): string
    {
        return $this->url;
    }

    public function linkLabel(): string
    {
        return 'View backups';
    }

    public function timestamp(): ?string
    {
        return $this->timestamp;
    }

    public function excerpt(): ?string
    {
        return null; // Details live in the on-server run log.
    }

    public function details(): array
    {
        $details = [
            'Database' => $this->databaseName,
            'Server' => $this->serverName,
        ];

        if ($this->failedStep !== null) {
            $details['Failed step'] = $this->failedStep;
        }

        return $details;
    }
}
```

3f. Job `app/Jobs/SendBackupNotification.php` (mirrors `SendDeploymentNotification`, including the explicit org filter, since queue workers run unscoped):

```php
<?php

namespace App\Jobs;

use App\Models\BackupConfig;
use App\Models\NotificationChannel;
use App\Models\Scopes\OrganizationScope;
use App\Services\Notifications\BackupNotificationPayload;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBackupNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public BackupConfig $config,
        public string $event,
        public ?string $failedStep = null,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $channels = NotificationChannel::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->config->database->server->organization_id)
            ->enabled()
            ->subscribedTo($this->event)
            ->get();

        if ($channels->isEmpty()) {
            return;
        }

        $payload = BackupNotificationPayload::fromConfig($this->config, $this->event, $this->failedStep);

        foreach ($channels as $channel) {
            try {
                $notificationService->send($channel, $payload);
            } catch (\Throwable $e) {
                Log::warning("Notification channel '{$channel->name}' ({$channel->type}) failed for backup config {$this->config->id}: {$e->getMessage()}");
            }
        }
    }
}
```

3g. Wire the callback. In `BackupReportController::store`, after creating the run row:

```php
        if (! $succeeded) {
            SendBackupNotification::dispatch(
                $backupConfig,
                \App\Models\NotificationChannel::EVENT_BACKUP_FAILED,
                $validated['step'],
            );
        } elseif ($validated['prune_failed'] ?? false) {
            SendBackupNotification::dispatch(
                $backupConfig,
                \App\Models\NotificationChannel::EVENT_BACKUP_FAILED,
                'prune',
            );
        }
```

(with the `use App\Jobs\SendBackupNotification;` import).

- [ ] **Step 4: Run tests, including the deployment notification regression suite**

Run: `php artisan test --filter=BackupNotificationTest`
Expected: PASS (4 tests)

Run: `php artisan test --filter=DeploymentNotificationTest`
Expected: PASS (drivers still format deployment payloads correctly). If a test asserted Discord/Telegram commit markup (backticks or `<code>`), update that assertion to the plain text form; markup moved out of the drivers deliberately.

Run: `php artisan test --filter=NotificationChannelApiTest`
Expected: PASS (new event names validate through `NotificationChannel::EVENTS`)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: generalize notification payloads and add backup failure alerts"
```

---

## Task 11: Manual run (job + endpoint)

**Files:**
- Create: `app/Jobs/ProcessBackupRun.php`
- Modify: `app/Http/Controllers/Api/BackupConfigController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/BackupManualRunTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessBackupRun;
use App\Models\BackupConfig;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupManualRunTest extends TestCase
{
    use RefreshDatabase;

    private function mockSsh(bool $succeeds, string $output): void
    {
        $this->mock(\App\Services\SSHService::class, function ($mock) use ($succeeds, $output) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturn([
                'output' => $output,
                'exit_code' => $succeeds ? 0 : 1,
                'success' => $succeeds,
            ]);
        });
    }

    public function test_run_endpoint_creates_running_row_and_dispatches_job(): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $config = BackupConfig::factory()->create(['database_id' => $database->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/run"
        );

        $response->assertStatus(202);
        $this->assertDatabaseHas('backup_runs', [
            'backup_config_id' => $config->id,
            'status' => 'running',
            'trigger' => 'manual',
        ]);
        Queue::assertPushed(ProcessBackupRun::class);
    }

    public function test_run_endpoint_rejects_uninstalled_config(): void
    {
        Queue::fake();
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $config = BackupConfig::factory()->installing()->create(['database_id' => $database->id]);

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/run"
        )->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_job_success_parses_key_and_size(): void
    {
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();
        $run = BackupRun::factory()->create([
            'backup_config_id' => $config->id,
            'status' => 'running',
            'trigger' => 'manual',
            's3_key' => null,
            'size_bytes' => null,
        ]);

        $this->mockSsh(true, "[shipyard-backup] config {$config->id}: dumping\n[shipyard-backup] OK key=20260729-101500.sql.gz size=4096\n");

        (new ProcessBackupRun($run))->handle(app(\App\Services\SSHService::class));

        $fresh = $run->fresh();
        $this->assertSame('success', $fresh->status);
        $this->assertSame('20260729-101500.sql.gz', $fresh->s3_key);
        $this->assertSame(4096, $fresh->size_bytes);
        $this->assertNotNull($config->fresh()->last_success_at);
    }

    public function test_job_failure_marks_failed_and_notifies(): void
    {
        Queue::fake([\App\Jobs\SendBackupNotification::class]);
        $this->createOrgUser();
        $config = BackupConfig::factory()->create();
        $run = BackupRun::factory()->create([
            'backup_config_id' => $config->id,
            'status' => 'running',
            'trigger' => 'manual',
        ]);

        $this->mockSsh(false, "[shipyard-backup] FAILED at step upload\n");

        (new ProcessBackupRun($run))->handle(app(\App\Services\SSHService::class));

        $fresh = $run->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertSame('upload', $fresh->failed_step);
        Queue::assertPushed(\App\Jobs\SendBackupNotification::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupManualRunTest`
Expected: FAIL with `Class "App\Jobs\ProcessBackupRun" not found`

- [ ] **Step 3: Implement**

`app/Jobs/ProcessBackupRun.php`:

```php
<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\SSHService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Executes a backup script on demand. SHIPYARD_TRIGGER=manual makes the
 * script skip its own callback; this job records the outcome itself, so
 * a manual run works even when the panel URL is unreachable from the
 * server. Dump timing depends on database size, hence the long timeout.
 */
class ProcessBackupRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public BackupRun $run
    ) {}

    public function handle(SSHService $ssh): void
    {
        $config = $this->run->config;
        $server = $config->database->server;
        $scriptPath = escapeshellarg($config->scriptPath());
        $startedAt = now();

        try {
            $ssh->connect($server);
            $result = $ssh->execute("SHIPYARD_TRIGGER=manual bash {$scriptPath} 2>&1", 3540);
        } finally {
            $ssh->disconnect();
        }

        $log = Str::limit($result['output'], 60000);
        $duration = (int) abs(now()->diffInSeconds($startedAt));

        if ($result['success'] && preg_match('/OK key=(\S+) size=(\d+)/', $result['output'], $m)) {
            $this->run->update([
                'status' => 'success',
                's3_key' => $m[1],
                'size_bytes' => (int) $m[2],
                'duration_seconds' => $duration,
                'log' => $log,
            ]);
            $config->update(['last_success_at' => now(), 'overdue_notified_at' => null]);

            return;
        }

        preg_match('/FAILED at step (\w+)/', $result['output'], $m);

        $this->run->update([
            'status' => 'failed',
            'failed_step' => $m[1] ?? null,
            'duration_seconds' => $duration,
            'log' => $log,
        ]);

        SendBackupNotification::dispatch($config, NotificationChannel::EVENT_BACKUP_FAILED, $m[1] ?? null);
    }

    public function failed(\Throwable $exception): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->status !== 'running') {
            return;
        }

        $run->update([
            'status' => 'failed',
            'log' => "ERROR: {$exception->getMessage()}",
        ]);
    }
}
```

Controller method in `BackupConfigController`:

```php
    public function run(Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $backupConfig->isInstalled()) {
            return response()->json(['message' => 'Backup config is not installed yet.'], 409);
        }

        if ($backupConfig->runs()->where('status', 'running')->exists()) {
            return response()->json(['message' => 'A run is already in progress.'], 409);
        }

        $run = $backupConfig->runs()->create([
            'kind' => 'backup',
            'trigger' => 'manual',
            'status' => 'running',
        ]);

        ProcessBackupRun::dispatch($run);

        return response()->json($run, 202);
    }
```

(with the `use App\Jobs\ProcessBackupRun;` import).

Route (with the other backup-config routes):

```php
    Route::post('/servers/{server}/databases/{database}/backup-configs/{backupConfig}/run', [BackupConfigController::class, 'run']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupManualRunTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add manual backup runs"
```

---

## Task 12: Restore and object listing

**Files:**
- Create: `app/Jobs/ProcessDatabaseRestore.php`
- Create: `app/Services/BackupRestoreService.php`
- Modify: `app/Http/Controllers/Api/BackupConfigController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/BackupRestoreTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessDatabaseRestore;
use App\Models\BackupConfig;
use App\Models\BackupRun;
use App\Models\Database;
use App\Models\Server;
use App\Services\BackupRestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    private function mockSsh(bool $succeeds = true, string $output = ''): void
    {
        $this->uploadedScripts = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use ($succeeds, $output) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            // Every command returns $output: listObjects runs "rclone lsjson"
            // directly (not through a bash script), so the JSON fixture must
            // come back for it too.
            $mock->shouldReceive('execute')->andReturnUsing(function () use ($succeeds, $output) {
                return ['output' => $output, 'exit_code' => $succeeds ? 0 : 1, 'success' => $succeeds];
            });
        });
    }

    private function setUpStack(): array
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();
        $database = Database::factory()->create(['server_id' => $server->id]);
        $config = BackupConfig::factory()->create([
            'database_id' => $database->id,
            'database_name' => 'shop',
        ]);

        return [$user, $server, $database, $config];
    }

    public function test_restore_endpoint_requires_confirmation_to_overwrite_original(): void
    {
        Queue::fake();
        [$user, $server, $database, $config] = $this->setUpStack();

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/restore",
            [
                's3_key' => '20260729-030000.sql.gz',
                'target_database_name' => 'shop',
                // no confirm field
            ]
        )->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_restore_endpoint_dispatches_job_for_new_target(): void
    {
        Queue::fake();
        [$user, $server, $database, $config] = $this->setUpStack();

        $response = $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/restore",
            [
                's3_key' => '20260729-030000.sql.gz',
                'target_database_name' => 'shop_copy',
            ]
        );

        $response->assertStatus(202);
        $this->assertDatabaseHas('backup_runs', [
            'backup_config_id' => $config->id,
            'kind' => 'restore',
            'status' => 'running',
        ]);
        Queue::assertPushed(ProcessDatabaseRestore::class);
    }

    public function test_restore_endpoint_rejects_malformed_s3_key(): void
    {
        Queue::fake();
        [$user, $server, $database, $config] = $this->setUpStack();

        $this->actingAs($user)->postJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/restore",
            [
                's3_key' => '../../../etc/passwd',
                'target_database_name' => 'shop_copy',
            ]
        )->assertStatus(422);
    }

    public function test_restore_script_pipes_rclone_through_gunzip_into_mysql(): void
    {
        [, , , $config] = $this->setUpStack();
        $this->mockSsh();

        $run = BackupRun::factory()->create([
            'backup_config_id' => $config->id,
            'kind' => 'restore',
            'status' => 'running',
            's3_key' => '20260729-030000.sql.gz',
        ]);

        app(BackupRestoreService::class)->restore($run, 'shop_copy');

        $all = implode("\n===\n", $this->uploadedScripts);

        $this->assertStringContainsString('rclone --config', $all);
        $this->assertStringContainsString('cat', $all);
        $this->assertStringContainsString('20260729-030000.sql.gz', $all);
        $this->assertStringContainsString('gunzip', $all);
        $this->assertStringContainsString('mysql', $all);
        $this->assertStringContainsString('shop_copy', $all);
        $this->assertSame('success', $run->fresh()->status);
    }

    public function test_objects_endpoint_parses_rclone_lsjson(): void
    {
        [$user, $server, $database, $config] = $this->setUpStack();
        $this->mockSsh(true, '[{"Name":"20260729-030000.sql.gz","Size":2048,"ModTime":"2026-07-29T03:00:05Z"}]');

        $this->actingAs($user)->getJson(
            "/api/servers/{$server->id}/databases/{$database->id}/backup-configs/{$config->id}/objects"
        )
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', '20260729-030000.sql.gz')
            ->assertJsonPath('0.size', 2048);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupRestoreTest`
Expected: FAIL with `Class "App\Jobs\ProcessDatabaseRestore" not found`

- [ ] **Step 3: Implement**

`app/Services/BackupRestoreService.php`:

```php
<?php

namespace App\Services;

use App\Models\BackupRun;
use App\Services\Concerns\RunsRemoteScripts;
use RuntimeException;

/**
 * Restores a backup object into a database on the same connection, and
 * lists stored backup objects. Both operations run on the target server
 * using the rclone conf the backup config installed there.
 */
class BackupRestoreService
{
    use RunsRemoteScripts;

    public function __construct(
        protected SSHService $sshService,
        protected BackupScriptService $scriptService,
        protected MySQLService $mysqlService,
        protected PostgreSQLService $postgresService,
    ) {}

    /**
     * @return array<int, array{name: string, size: int, mod_time: string}>
     */
    public function listObjects(\App\Models\BackupConfig $config): array
    {
        $server = $config->database->server;
        $conf = escapeshellarg($config->rcloneConfPath());
        $remote = escapeshellarg($this->scriptService->remotePath($config));

        try {
            $this->sshService->connect($server);
            $result = $this->sshService->execute("rclone --config {$conf} lsjson {$remote}", 60);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to list backups: '.$result['output']);
        }

        $objects = json_decode(trim($result['output']), true) ?? [];

        return collect($objects)
            ->map(fn ($o) => [
                'name' => $o['Name'],
                'size' => (int) $o['Size'],
                'mod_time' => $o['ModTime'],
            ])
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    public function restore(BackupRun $run, string $targetDatabaseName): void
    {
        $config = $run->config;
        $database = $config->database;
        $server = $database->server;
        $startedAt = now();

        $this->ensureTargetExists($database, $targetDatabaseName);

        $conf = escapeshellarg($config->rcloneConfPath());
        $object = escapeshellarg($this->scriptService->remotePath($config).'/'.$run->s3_key);
        $load = $this->loadCommand($database, $targetDatabaseName);

        $script = <<<BASH
set -uo pipefail
rclone --config {$conf} cat {$object} | gunzip | {$load}
BASH;

        $result = $this->runRemoteScript($server, $script, 3540);
        $duration = (int) abs(now()->diffInSeconds($startedAt));

        if (! $result['success']) {
            $run->update([
                'status' => 'failed',
                'failed_step' => 'restore',
                'duration_seconds' => $duration,
                'log' => \Illuminate\Support\Str::limit($result['output'], 60000),
            ]);

            throw new RuntimeException('Restore failed: '.$result['output']);
        }

        $run->update([
            'status' => 'success',
            'duration_seconds' => $duration,
            'log' => \Illuminate\Support\Str::limit($result['output'], 60000),
        ]);
    }

    private function ensureTargetExists(\App\Models\Database $database, string $name): void
    {
        $driver = $database->isPostgreSQL() ? $this->postgresService : $this->mysqlService;

        try {
            $this->sshService->connect($database->server);
            $existing = $driver->listDatabases($this->sshService, $database);

            if (! in_array($name, $existing, true)) {
                $driver->createDatabase($this->sshService, $database, $name);
            }
        } finally {
            $this->sshService->disconnect();
        }
    }

    private function loadCommand(\App\Models\Database $database, string $targetName): string
    {
        $host = escapeshellarg($database->host);
        $port = escapeshellarg((string) $database->port);
        $user = escapeshellarg($database->admin_user);
        $password = escapeshellarg($database->admin_password);

        if ($database->isPostgreSQL()) {
            return "PGPASSWORD={$password} psql -h {$host} -p {$port} -U {$user} -d {$targetName} -v ON_ERROR_STOP=1 -q";
        }

        return 'mysql --defaults-extra-file=<(printf \'[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n\' '
            ."{$host} {$port} {$user} {$password}) {$targetName}";
    }
}
```

`app/Jobs/ProcessDatabaseRestore.php`:

```php
<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Models\NotificationChannel;
use App\Services\BackupRestoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDatabaseRestore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public BackupRun $run,
        public string $targetDatabaseName,
    ) {}

    public function handle(BackupRestoreService $service): void
    {
        $service->restore($this->run, $this->targetDatabaseName);
    }

    public function failed(\Throwable $exception): void
    {
        $run = $this->run->fresh();

        if ($run === null) {
            return;
        }

        if ($run->status === 'running') {
            $run->update([
                'status' => 'failed',
                'failed_step' => 'restore',
                'log' => "ERROR: {$exception->getMessage()}",
            ]);
        }

        SendBackupNotification::dispatch(
            $run->config,
            NotificationChannel::EVENT_RESTORE_FAILED,
            'restore',
        );
    }
}
```

Controller methods in `BackupConfigController`:

```php
    public function objects(Server $server, Database $database, BackupConfig $backupConfig, \App\Services\BackupRestoreService $service): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            return response()->json($service->listObjects($backupConfig));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function restore(Request $request, Server $server, Database $database, BackupConfig $backupConfig): JsonResponse
    {
        if ($database->server_id !== $server->id || $backupConfig->database_id !== $database->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            's3_key' => ['required', 'string', 'regex:/^[0-9]{8}-[0-9]{6}\.sql\.gz$/'],
            'target_database_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            // Overwriting the backed-up database is destructive: the target
            // name must be retyped as confirmation.
            'confirm' => [
                Rule::requiredIf(fn () => $request->input('target_database_name') === $backupConfig->database_name),
                'nullable',
                'same:target_database_name',
            ],
        ]);

        if ($backupConfig->runs()->where('status', 'running')->exists()) {
            return response()->json(['message' => 'A run is already in progress.'], 409);
        }

        $run = $backupConfig->runs()->create([
            'kind' => 'restore',
            'trigger' => 'manual',
            'status' => 'running',
            's3_key' => $validated['s3_key'],
        ]);

        ProcessDatabaseRestore::dispatch($run, $validated['target_database_name']);

        return response()->json($run, 202);
    }
```

(with the `use App\Jobs\ProcessDatabaseRestore;` import; `Rule` is already imported).

Routes:

```php
    Route::get('/servers/{server}/databases/{database}/backup-configs/{backupConfig}/objects', [BackupConfigController::class, 'objects']);
    Route::post('/servers/{server}/databases/{database}/backup-configs/{backupConfig}/restore', [BackupConfigController::class, 'restore']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupRestoreTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: add backup restore and object listing"
```

---

## Task 13: Overdue backup detection

**Files:**
- Create: `app/Console/Commands/CheckOverdueBackups.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/BackupOverdueTest.php`

Overdue rule from the spec: a config is overdue when its last success is older than one full cron interval plus a grace period of one more interval. Concretely, using `Cron\CronExpression` (already installed as a Laravel dependency): overdue when the last success (or `created_at` for never-succeeded configs) is before the due time two occurrences ago. Notify once per outage: `overdue_notified_at` gates re-sending and both report paths already clear it on success.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SendBackupNotification;
use App\Models\BackupConfig;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BackupOverdueTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_config_triggers_notification_once(): void
    {
        Queue::fake();
        $this->createOrgUser();

        // Nightly config whose last success was three days ago.
        $config = BackupConfig::factory()->create([
            'frequency' => 'nightly',
            'last_success_at' => now()->subDays(3),
        ]);

        $this->artisan('backups:check-overdue')->assertSuccessful();

        Queue::assertPushed(SendBackupNotification::class, function ($job) use ($config) {
            return $job->config->id === $config->id
                && $job->event === NotificationChannel::EVENT_BACKUP_OVERDUE;
        });
        $this->assertNotNull($config->fresh()->overdue_notified_at);

        // Second sweep: still overdue, but already notified.
        Queue::fake();
        $this->artisan('backups:check-overdue')->assertSuccessful();
        Queue::assertNotPushed(SendBackupNotification::class);
    }

    public function test_recent_success_is_not_overdue(): void
    {
        Queue::fake();
        $this->createOrgUser();

        BackupConfig::factory()->create([
            'frequency' => 'nightly',
            'last_success_at' => now()->subHours(2),
        ]);

        $this->artisan('backups:check-overdue')->assertSuccessful();

        Queue::assertNotPushed(SendBackupNotification::class);
    }

    public function test_disabled_and_uninstalled_configs_are_skipped(): void
    {
        Queue::fake();
        $this->createOrgUser();

        BackupConfig::factory()->create([
            'frequency' => 'nightly',
            'enabled' => false,
            'last_success_at' => now()->subDays(10),
        ]);
        BackupConfig::factory()->installing()->create([
            'frequency' => 'nightly',
            'last_success_at' => null,
        ]);

        $this->artisan('backups:check-overdue')->assertSuccessful();

        Queue::assertNotPushed(SendBackupNotification::class);
    }

    public function test_never_succeeded_installed_config_uses_created_at(): void
    {
        Queue::fake();
        $this->createOrgUser();

        $config = BackupConfig::factory()->create([
            'frequency' => 'nightly',
            'last_success_at' => null,
        ]);
        $config->timestamps = false;
        $config->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('backups:check-overdue')->assertSuccessful();

        Queue::assertPushed(SendBackupNotification::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackupOverdueTest`
Expected: FAIL with command `backups:check-overdue` not found

- [ ] **Step 3: Implement**

`app/Console/Commands/CheckOverdueBackups.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SendBackupNotification;
use App\Models\BackupConfig;
use App\Models\NotificationChannel;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CheckOverdueBackups extends Command
{
    protected $signature = 'backups:check-overdue';

    protected $description = 'Notify about backup configs whose schedule has silently stopped producing successes';

    public function handle(): int
    {
        // Runs unscoped (no org context in console); each notification job
        // re-filters channels by the config's own organization.
        $configs = BackupConfig::query()
            ->where('enabled', true)
            ->where('status', 'installed')
            ->whereNull('overdue_notified_at')
            ->with('database.server')
            ->get();

        foreach ($configs as $config) {
            if (! $this->isOverdue($config)) {
                continue;
            }

            $config->update(['overdue_notified_at' => now()]);

            SendBackupNotification::dispatch(
                $config,
                NotificationChannel::EVENT_BACKUP_OVERDUE,
            );

            $this->info("Backup config {$config->id} ({$config->database_name}) is overdue.");
        }

        return self::SUCCESS;
    }

    private function isOverdue(BackupConfig $config): bool
    {
        try {
            $cron = new CronExpression($config->cron_expression);
        } catch (\InvalidArgumentException) {
            return false; // Unparseable expression: nothing sane to check.
        }

        // Due time two occurrences ago = one interval plus one interval of
        // grace. Anything that last succeeded before that has missed at
        // least one full scheduled run.
        $threshold = Carbon::instance($cron->getPreviousRunDate(now(), 1));

        $baseline = $config->last_success_at ?? $config->created_at;

        return $baseline->lt($threshold);
    }
}
```

Schedule in `routes/console.php`:

```php
Schedule::command('backups:check-overdue')->everyFifteenMinutes();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackupOverdueTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "feat: detect and notify overdue backups"
```

---

## Task 14: Final sweep

**Files:**
- Modify: `CLAUDE.md` (add a Key Patterns paragraph)

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: PASS across the board. Pay attention to `CrossTenantIsolationTest` (nothing here should have changed its behavior, but backup destinations are a new tenant root table) and `DeploymentNotificationTest` (driver refactor).

- [ ] **Step 2: Add the pattern note to CLAUDE.md**

Append to the Key Patterns section of `CLAUDE.md`:

```markdown
### Database Backups
Backups run self-contained on target servers: `BackupConfigService` installs an rclone conf plus a rendered bash script (`BackupScriptService`) and registers a cron line in the managed crontab block (`CrontabService::buildManagedBlock` includes both `ScheduledTask` and `BackupConfig` rows, so all crontab writes share the `crontab:{server}:{user}` lock). Scheduled runs report back through the public `/api/backup-configs/{id}/report` endpoint (per-config secret token). Restores and manual runs are panel-orchestrated jobs. The restore picker lists S3 objects live via rclone, not from `backup_runs` history.
```

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint --dirty
git add -A
git commit -m "docs: document the backup subsystem pattern"
```

- [ ] **Step 4: Real-world verification (manual, outside CI)**

On a fresh EC2 instance (the usual remote-target flow):
1. Add the server, install MySQL, create a database with a few tables.
2. Create an S3/R2 destination and a backup config with frequency `minutely` (test only).
3. Watch: rclone installed, script and conf in `~shipyard/.shipyard/backups/`, cron line inside the managed block, object appears in the bucket, run row appears via callback.
4. Trigger "run now" and confirm a second object plus a manual run row.
5. Restore to a new database name and verify tables arrived.
6. Break the destination secret, force a run, confirm a failure notification fires.
7. Set keep_last=2, run three times, confirm pruning.

---

## Out of scope (follow-up plans)

- Frontend UI: destinations settings page, backups tab on `DatabaseDetail.tsx`, restore modal, run history table.
- Multipart tuning and bandwidth limits for very large dumps.
- Cross-server restore.
```
