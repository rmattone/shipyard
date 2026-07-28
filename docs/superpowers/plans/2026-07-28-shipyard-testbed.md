# ShipYard Testbed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A small Laravel 12 app (`shipyard-testbed`) whose `/status` page makes every ShipYard-deployed subsystem's health observable: FPM runtime user, database, queue daemon, scheduler/cron, storage permissions, env sync, release layout.

**Architecture:** A check layer (`app/Checks/`, one class per check, all returning a `CheckResult` value object, collected by a `ChecksRunner`) feeds `/status` (HTML) and `/status/json`. A `heartbeats` table is written by a queued job and a scheduled command (the command also dispatches the job, so cron alone keeps both flowing). A `notes` CRUD proves the database interactively. Expectations come from `TESTBED_*` env vars via `config/testbed.php`, so one app validates legacy and home-layout servers.

**Tech Stack:** Laravel 12 (PHP >= 8.2; local dev on Herd PHP 8.4), PHPUnit with in-memory SQLite, Blade only (no Vite/npm in any view), database queue driver. New public repo `shipyard-testbed` under the `rmattone-oston` GitHub account.

**Spec:** `docs/superpowers/specs/2026-07-28-shipyard-testbed-design.md` (in the server-management repo).

**Project directory (all tasks work here):** `/Users/rmattone/Documents/Pessoal/Projetos/shipyard-testbed`

**Test command:** `php artisan test` (SQLite in memory; no external services needed).

**Conventions:** commit messages must NOT mention Claude or any AI tool, no Co-Authored-By trailers. README prose must not use dashes as punctuation (rephrase with periods, commas, or parentheses). Stage files explicitly.

---

### Task 1: Scaffold the project, git, and GitHub repo

**Files:**
- Create: the whole Laravel skeleton at `/Users/rmattone/Documents/Pessoal/Projetos/shipyard-testbed`
- Modify: `composer.json` (name/description), `phpunit.xml` (verify only)

- [ ] **Step 1: Scaffold**

```bash
cd /Users/rmattone/Documents/Pessoal/Projetos
composer create-project laravel/laravel shipyard-testbed "^12.0" --no-interaction
cd shipyard-testbed
php artisan --version
```

Expected: `Laravel Framework 12.x`.

- [ ] **Step 2: Verify the test defaults**

Open `phpunit.xml` and confirm it contains `<env name="DB_CONNECTION" value="sqlite"/>` and `<env name="DB_DATABASE" value=":memory:"/>` (the Laravel 12 skeleton default). If either is missing, add both to the `<php>` block. Also confirm `<env name="QUEUE_CONNECTION" value="sync"/>` is present (needed so job-dispatch tests run inline unless faked).

Run: `php artisan test`
Expected: the skeleton's example tests pass (2 tests).

- [ ] **Step 3: Set package identity**

In `composer.json`, set `"name": "rmattone/shipyard-testbed"` and `"description": "Diagnostics-first testbed app for verifying ShipYard deployments end to end."`. Run `composer validate` (expect: valid, possibly warnings about version omitted, which is fine).

- [ ] **Step 4: Init git and first commit**

```bash
cd /Users/rmattone/Documents/Pessoal/Projetos/shipyard-testbed
git init -b main
git add .
git commit -m "Scaffold Laravel 12 skeleton"
```

(The skeleton ships a proper `.gitignore`; `vendor/` and `.env` must not appear in `git status` before the add.)

- [ ] **Step 5: Create the public GitHub repo and push**

```bash
gh repo create rmattone-oston/shipyard-testbed --public --source . --push --description "Diagnostics-first testbed for ShipYard deployments"
```

Expected: repo created, main pushed. If `gh` errors on auth, report BLOCKED rather than working around it.

---

### Task 2: CheckResult, Check contract, ChecksRunner

**Files:**
- Create: `app/Checks/CheckResult.php`, `app/Checks/Check.php`, `app/Checks/ChecksRunner.php`
- Test: `tests/Unit/CheckResultTest.php`, `tests/Unit/ChecksRunnerTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/CheckResultTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Checks\CheckResult;
use PHPUnit\Framework\TestCase;

class CheckResultTest extends TestCase
{
    public function test_pass_result(): void
    {
        $r = CheckResult::pass('Runtime user', 'shipyard', 'shipyard');

        $this->assertSame('Runtime user', $r->name);
        $this->assertTrue($r->pass);
        $this->assertSame('shipyard', $r->expected);
        $this->assertSame('shipyard', $r->actual);
        $this->assertNull($r->detail);
    }

    public function test_fail_result_carries_detail(): void
    {
        $r = CheckResult::fail('Database', 'connection works', 'connection refused', 'SQLSTATE[HY000]');

        $this->assertFalse($r->pass);
        $this->assertSame('SQLSTATE[HY000]', $r->detail);
    }

    public function test_info_result_has_null_pass(): void
    {
        $r = CheckResult::info('Release', '/home/shipyard/app/releases/3');

        $this->assertNull($r->pass);
        $this->assertNull($r->expected);
    }

    public function test_to_array_shape(): void
    {
        $r = CheckResult::pass('X', 'a', 'a');

        $this->assertSame(
            ['name' => 'X', 'pass' => true, 'expected' => 'a', 'actual' => 'a', 'detail' => null],
            $r->toArray()
        );
    }
}
```

`tests/Unit/ChecksRunnerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Checks\Check;
use App\Checks\CheckResult;
use App\Checks\ChecksRunner;
use PHPUnit\Framework\TestCase;

class ChecksRunnerTest extends TestCase
{
    public function test_runs_all_checks_in_order(): void
    {
        $a = new class implements Check
        {
            public function name(): string
            {
                return 'A';
            }

            public function run(): CheckResult
            {
                return CheckResult::pass('A', null, 'ok');
            }
        };

        $b = new class implements Check
        {
            public function name(): string
            {
                return 'B';
            }

            public function run(): CheckResult
            {
                return CheckResult::info('B', 'fact');
            }
        };

        $results = (new ChecksRunner([$a, $b]))->run();

        $this->assertCount(2, $results);
        $this->assertSame(['A', 'B'], array_map(fn ($r) => $r->name, $results));
    }

    public function test_a_throwing_check_becomes_a_failed_result(): void
    {
        $boom = new class implements Check
        {
            public function name(): string
            {
                return 'Boom';
            }

            public function run(): CheckResult
            {
                throw new \RuntimeException('kaput');
            }
        };

        $results = (new ChecksRunner([$boom]))->run();

        $this->assertFalse($results[0]->pass);
        $this->assertSame('Boom', $results[0]->name);
        $this->assertStringContainsString('kaput', $results[0]->detail);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter="CheckResultTest|ChecksRunnerTest"`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`app/Checks/CheckResult.php`:

```php
<?php

namespace App\Checks;

/**
 * The uniform shape every check produces. pass=null means informational:
 * the check reports facts without judging them (rendered grey, not red).
 */
final class CheckResult
{
    private function __construct(
        public readonly string $name,
        public readonly ?bool $pass,
        public readonly ?string $expected,
        public readonly string $actual,
        public readonly ?string $detail,
    ) {}

    public static function pass(string $name, ?string $expected, string $actual, ?string $detail = null): self
    {
        return new self($name, true, $expected, $actual, $detail);
    }

    public static function fail(string $name, ?string $expected, string $actual, ?string $detail = null): self
    {
        return new self($name, false, $expected, $actual, $detail);
    }

    public static function info(string $name, string $actual, ?string $detail = null): self
    {
        return new self($name, null, null, $actual, $detail);
    }

    /** @return array{name: string, pass: ?bool, expected: ?string, actual: string, detail: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'pass' => $this->pass,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'detail' => $this->detail,
        ];
    }
}
```

`app/Checks/Check.php`:

```php
<?php

namespace App\Checks;

interface Check
{
    public function name(): string;

    /**
     * Must not throw: convert failures into CheckResult::fail with the
     * message in detail. ChecksRunner still guards against escapes so a
     * buggy check cannot take the whole /status page down.
     */
    public function run(): CheckResult;
}
```

`app/Checks/ChecksRunner.php`:

```php
<?php

namespace App\Checks;

use Throwable;

final class ChecksRunner
{
    /** @param array<int, Check> $checks */
    public function __construct(
        private readonly array $checks,
    ) {}

    /** @return array<int, CheckResult> */
    public function run(): array
    {
        return array_map(function (Check $check): CheckResult {
            try {
                return $check->run();
            } catch (Throwable $e) {
                return CheckResult::fail($check->name(), null, 'check crashed', $e->getMessage());
            }
        }, $this->checks);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter="CheckResultTest|ChecksRunnerTest"`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Checks tests/Unit/CheckResultTest.php tests/Unit/ChecksRunnerTest.php
git commit -m "Add check contract, result value object, and runner"
```

---

### Task 3: Heartbeats (migration, model, job, command, schedule)

**Files:**
- Create: `database/migrations/2026_07_28_000001_create_heartbeats_table.php`, `app/Models/Heartbeat.php`, `app/Support/RuntimeUser.php`, `app/Jobs/RecordHeartbeat.php`, `app/Console/Commands/TestbedHeartbeat.php`
- Modify: `routes/console.php` (schedule registration)
- Test: `tests/Feature/HeartbeatTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/HeartbeatTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\RecordHeartbeat;
use App\Models\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_records_a_queue_heartbeat_with_the_runtime_user(): void
    {
        (new RecordHeartbeat)->handle();

        $row = Heartbeat::sole();
        $this->assertSame(Heartbeat::SOURCE_QUEUE, $row->source);
        $this->assertNotSame('', $row->ran_as);
    }

    public function test_command_records_a_scheduler_heartbeat_and_dispatches_the_job(): void
    {
        Queue::fake();

        $this->artisan('testbed:heartbeat')->assertSuccessful();

        $this->assertSame(1, Heartbeat::where('source', Heartbeat::SOURCE_SCHEDULER)->count());
        Queue::assertPushed(RecordHeartbeat::class, 1);
    }

    public function test_heartbeat_command_is_scheduled_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $match = $events->first(fn ($e) => str_contains($e->command ?? '', 'testbed:heartbeat'));

        $this->assertNotNull($match, 'testbed:heartbeat is not on the schedule');
        $this->assertSame('* * * * *', $match->expression);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=HeartbeatTest`
Expected: FAIL (missing classes/table).

- [ ] **Step 3: Implement**

`database/migrations/2026_07_28_000001_create_heartbeats_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32)->index();
            $table->string('ran_as', 64);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
```

`app/Models/Heartbeat.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Heartbeat extends Model
{
    public const SOURCE_QUEUE = 'queue';

    public const SOURCE_SCHEDULER = 'scheduler';

    public const UPDATED_AT = null;

    protected $fillable = ['source', 'ran_as'];
}
```

`app/Support/RuntimeUser.php`:

```php
<?php

namespace App\Support;

/**
 * The unix user this PHP process executes as, recorded at execution time
 * (never from configuration) so heartbeats prove which user actually ran.
 */
final class RuntimeUser
{
    public static function name(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return $info['name'];
            }
        }

        return get_current_user();
    }
}
```

`app/Jobs/RecordHeartbeat.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Heartbeat;
use App\Support\RuntimeUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Heartbeat::create([
            'source' => Heartbeat::SOURCE_QUEUE,
            'ran_as' => RuntimeUser::name(),
        ]);
    }
}
```

`app/Console/Commands/TestbedHeartbeat.php`:

```php
<?php

namespace App\Console\Commands;

use App\Jobs\RecordHeartbeat;
use App\Models\Heartbeat;
use App\Support\RuntimeUser;
use Illuminate\Console\Command;

class TestbedHeartbeat extends Command
{
    protected $signature = 'testbed:heartbeat';

    protected $description = 'Record a scheduler heartbeat and dispatch a queue heartbeat job';

    public function handle(): int
    {
        Heartbeat::create([
            'source' => Heartbeat::SOURCE_SCHEDULER,
            'ran_as' => RuntimeUser::name(),
        ]);

        RecordHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
```

Append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('testbed:heartbeat')->everyMinute();
```

(Keep whatever the skeleton already has in that file; only add these lines. If `Schedule` is already imported, do not duplicate the import.)

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=HeartbeatTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_28_000001_create_heartbeats_table.php app/Models/Heartbeat.php app/Support/RuntimeUser.php app/Jobs/RecordHeartbeat.php app/Console/Commands/TestbedHeartbeat.php routes/console.php tests/Feature/HeartbeatTest.php
git commit -m "Add heartbeat table, job, command, and schedule"
```

---

### Task 4: Testbed config, RuntimeUserCheck, EnvSyncCheck

**Files:**
- Create: `config/testbed.php`, `app/Checks/RuntimeUserCheck.php`, `app/Checks/EnvSyncCheck.php`
- Modify: `app/Support/RuntimeUser.php` (fallback honesty, see below)
- Test: `tests/Unit/RuntimeUserCheckTest.php`, `tests/Unit/EnvSyncCheckTest.php`

**Amendment from the Task 3 review (important):** `RuntimeUser::name()`'s `get_current_user()` fallback returns the script's file OWNER, not the process user. On a box where posix is unavailable, that would report `shipyard` even when FPM actually runs as `www-data`, silently hiding the exact misconfiguration `RuntimeUserCheck` exists to catch. Make the degraded path visible instead of pretending: add a companion method and use it in the check.

```php
    /** True when the process user was determined reliably (posix available). */
    public static function isReliable(): bool
    {
        return function_exists('posix_geteuid') && function_exists('posix_getpwuid');
    }
```

and correct the class docblock to say the posix path reports the effective process user, while the fallback reports the script owner and is therefore not authoritative. `RuntimeUserCheck` must then never report a bare pass on the unreliable path: when `isReliable()` is false it returns an informational result whose detail says the process user could not be determined (posix unavailable) and that the value shown is the script owner. Add a test covering that branch by stubbing reliability through a small seam or by asserting the detail text when posix is present is absent, whichever is cleanest without adding machinery.

Note: these unit tests need the Laravel container (config), so they extend `Tests\TestCase`, not PHPUnit's.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/RuntimeUserCheckTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Checks\RuntimeUserCheck;
use App\Support\RuntimeUser;
use Tests\TestCase;

class RuntimeUserCheckTest extends TestCase
{
    public function test_informational_when_no_expectation_is_set(): void
    {
        config(['testbed.expected_user' => null]);

        $r = (new RuntimeUserCheck)->run();

        $this->assertNull($r->pass);
        $this->assertSame(RuntimeUser::name(), $r->actual);
    }

    public function test_passes_when_expectation_matches(): void
    {
        config(['testbed.expected_user' => RuntimeUser::name()]);

        $r = (new RuntimeUserCheck)->run();

        $this->assertTrue($r->pass);
    }

    public function test_fails_when_expectation_differs(): void
    {
        config(['testbed.expected_user' => 'someone-else']);

        $r = (new RuntimeUserCheck)->run();

        $this->assertFalse($r->pass);
        $this->assertSame('someone-else', $r->expected);
    }
}
```

`tests/Unit/EnvSyncCheckTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Checks\EnvSyncCheck;
use Tests\TestCase;

class EnvSyncCheckTest extends TestCase
{
    public function test_fails_when_marker_is_absent(): void
    {
        config(['testbed.marker' => null]);

        $r = (new EnvSyncCheck)->run();

        $this->assertFalse($r->pass);
    }

    public function test_passes_when_marker_is_present(): void
    {
        config(['testbed.marker' => 'hello-from-shipyard']);

        $r = (new EnvSyncCheck)->run();

        $this->assertTrue($r->pass);
        $this->assertStringContainsString('hello-from-shipyard', $r->actual);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter="RuntimeUserCheckTest|EnvSyncCheckTest"`
Expected: FAIL.

- [ ] **Step 3: Implement**

`config/testbed.php`:

```php
<?php

return [
    // Expected PHP runtime user; null means report without judging.
    'expected_user' => env('TESTBED_EXPECTED_USER'),

    // Any value set through ShipYard's env panel proves env sync end to end.
    'marker' => env('TESTBED_MARKER'),

    // Heartbeat freshness limits in seconds.
    'queue_max_age' => (int) env('TESTBED_QUEUE_MAX_AGE', 300),
    'scheduler_max_age' => (int) env('TESTBED_SCHEDULER_MAX_AGE', 120),
];
```

`app/Checks/RuntimeUserCheck.php`:

```php
<?php

namespace App\Checks;

use App\Support\RuntimeUser;

final class RuntimeUserCheck implements Check
{
    public function name(): string
    {
        return 'PHP runtime user';
    }

    public function run(): CheckResult
    {
        $actual = RuntimeUser::name();
        $expected = config('testbed.expected_user');

        if ($expected === null) {
            return CheckResult::info($this->name(), $actual, 'Set TESTBED_EXPECTED_USER to make this a pass/fail check.');
        }

        return $actual === $expected
            ? CheckResult::pass($this->name(), $expected, $actual)
            : CheckResult::fail($this->name(), $expected, $actual, 'PHP-FPM is not running as the expected user. Check the pool config and the vhost socket.');
    }
}
```

`app/Checks/EnvSyncCheck.php`:

```php
<?php

namespace App\Checks;

final class EnvSyncCheck implements Check
{
    public function name(): string
    {
        return 'Env sync (TESTBED_MARKER)';
    }

    public function run(): CheckResult
    {
        $marker = config('testbed.marker');

        if ($marker === null || $marker === '') {
            return CheckResult::fail(
                $this->name(),
                'TESTBED_MARKER set via the ShipYard env panel',
                'not set',
                'Set any value for TESTBED_MARKER in ShipYard and redeploy (or sync env).'
            );
        }

        return CheckResult::pass($this->name(), 'set', "set ({$marker})");
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter="RuntimeUserCheckTest|EnvSyncCheckTest"`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add config/testbed.php app/Checks/RuntimeUserCheck.php app/Checks/EnvSyncCheck.php tests/Unit/RuntimeUserCheckTest.php tests/Unit/EnvSyncCheckTest.php
git commit -m "Add testbed config, runtime user check, and env sync check"
```

---

### Task 5: DatabaseCheck and StorageWriteCheck

**Files:**
- Create: `app/Checks/DatabaseCheck.php`, `app/Checks/StorageWriteCheck.php`
- Test: `tests/Feature/DatabaseCheckTest.php`, `tests/Feature/StorageWriteCheckTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DatabaseCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Checks\DatabaseCheck;
use App\Models\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_trip_passes_and_cleans_up(): void
    {
        $r = (new DatabaseCheck)->run();

        $this->assertTrue($r->pass);
        $this->assertStringContainsString('sqlite', $r->actual);
        $this->assertSame(0, Heartbeat::where('source', DatabaseCheck::PROBE_SOURCE)->count());
    }

    public function test_fails_gracefully_when_connection_is_broken(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
            'database.connections.mysql.database' => 'nope',
        ]);
        \DB::purge('mysql');

        $r = (new DatabaseCheck)->run();

        $this->assertFalse($r->pass);
        $this->assertNotNull($r->detail);
    }
}
```

`tests/Feature/StorageWriteCheckTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Checks\StorageWriteCheck;
use Tests\TestCase;

class StorageWriteCheckTest extends TestCase
{
    public function test_writes_and_cleans_up(): void
    {
        $r = (new StorageWriteCheck)->run();

        $this->assertTrue($r->pass);
        $this->assertStringContainsString('owner', $r->actual);
        $this->assertFileDoesNotExist(storage_path('app/private/testbed-write-probe.txt'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter="DatabaseCheckTest|StorageWriteCheckTest"`
Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Checks/DatabaseCheck.php`:

```php
<?php

namespace App\Checks;

use App\Models\Heartbeat;
use App\Support\RuntimeUser;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

final class DatabaseCheck implements Check
{
    public const PROBE_SOURCE = 'database-check';

    public function name(): string
    {
        return 'Database round trip';
    }

    public function run(): CheckResult
    {
        try {
            $row = Heartbeat::create([
                'source' => self::PROBE_SOURCE,
                'ran_as' => RuntimeUser::name(),
            ]);

            $found = Heartbeat::findOrFail($row->id);
            $found->delete();

            $pdo = DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            return CheckResult::pass($this->name(), 'insert, read, delete', "{$driver} {$version}");
        } catch (Throwable $e) {
            return CheckResult::fail($this->name(), 'insert, read, delete', 'connection or query failed', $e->getMessage());
        }
    }
}
```

`app/Checks/StorageWriteCheck.php`:

```php
<?php

namespace App\Checks;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StorageWriteCheck implements Check
{
    private const PROBE_FILE = 'testbed-write-probe.txt';

    public function name(): string
    {
        return 'Storage writable';
    }

    public function run(): CheckResult
    {
        try {
            Storage::disk('local')->put(self::PROBE_FILE, now()->toIso8601String());
            $readBack = Storage::disk('local')->get(self::PROBE_FILE);
            Storage::disk('local')->delete(self::PROBE_FILE);

            if ($readBack === null || $readBack === '') {
                return CheckResult::fail($this->name(), 'write, read, delete under storage/', 'read-back empty');
            }

            Log::info('testbed storage write check');

            return CheckResult::pass($this->name(), 'write, read, delete under storage/', $this->storageFacts());
        } catch (Throwable $e) {
            return CheckResult::fail($this->name(), 'write, read, delete under storage/', $this->storageFacts(), $e->getMessage());
        }
    }

    private function storageFacts(): string
    {
        $path = storage_path();
        $mode = substr(sprintf('%o', @fileperms($path) ?: 0), -4);
        $owner = '(unknown)';

        if (function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(@fileowner($path) ?: -1);
            $owner = is_array($info) ? $info['name'] : $owner;
        }

        return "storage/ owner {$owner}, mode {$mode}";
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter="DatabaseCheckTest|StorageWriteCheckTest"`
Expected: PASS (3 tests). If the storage probe path assertion fails because the local disk root differs, check `config/filesystems.php` for the local disk root (Laravel 12 uses `storage/app/private`) and align the test's `assertFileDoesNotExist` path with it.

- [ ] **Step 5: Commit**

```bash
git add app/Checks/DatabaseCheck.php app/Checks/StorageWriteCheck.php tests/Feature/DatabaseCheckTest.php tests/Feature/StorageWriteCheckTest.php
git commit -m "Add database round trip and storage write checks"
```

---

### Task 6: Queue and Scheduler heartbeat checks

**Files:**
- Create: `app/Checks/HeartbeatCheck.php` (abstract base), `app/Checks/QueueHeartbeatCheck.php`, `app/Checks/SchedulerHeartbeatCheck.php`
- Test: `tests/Feature/HeartbeatChecksTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/HeartbeatChecksTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Checks\QueueHeartbeatCheck;
use App\Checks\SchedulerHeartbeatCheck;
use App\Models\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatChecksTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(string $source, int $secondsAgo): void
    {
        Heartbeat::forceCreate([
            'source' => $source,
            'ran_as' => 'testuser',
            'created_at' => now()->subSeconds($secondsAgo),
        ]);
    }

    public function test_queue_check_fails_with_no_heartbeats(): void
    {
        $r = (new QueueHeartbeatCheck)->run();

        $this->assertFalse($r->pass);
        $this->assertStringContainsString('never', $r->actual);
    }

    public function test_queue_check_passes_when_fresh(): void
    {
        config(['testbed.queue_max_age' => 300]);
        $this->heartbeat(Heartbeat::SOURCE_QUEUE, 60);

        $r = (new QueueHeartbeatCheck)->run();

        $this->assertTrue($r->pass);
        $this->assertStringContainsString('testuser', $r->actual);
    }

    public function test_queue_check_fails_when_stale(): void
    {
        config(['testbed.queue_max_age' => 300]);
        $this->heartbeat(Heartbeat::SOURCE_QUEUE, 301);

        $r = (new QueueHeartbeatCheck)->run();

        $this->assertFalse($r->pass);
        $this->assertStringContainsString('testuser', $r->actual);
    }

    public function test_scheduler_check_uses_its_own_source_and_limit(): void
    {
        config(['testbed.scheduler_max_age' => 120]);
        $this->heartbeat(Heartbeat::SOURCE_SCHEDULER, 60);
        $this->heartbeat(Heartbeat::SOURCE_QUEUE, 9999);

        $r = (new SchedulerHeartbeatCheck)->run();

        $this->assertTrue($r->pass);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=HeartbeatChecksTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Checks/HeartbeatCheck.php`:

```php
<?php

namespace App\Checks;

use App\Models\Heartbeat;
use Throwable;

/**
 * Shared freshness logic: a heartbeat source passes when its newest row is
 * younger than the configured max age. Actual always names the writing user
 * so a stale or wrong-user heartbeat is diagnosable from the row alone.
 */
abstract class HeartbeatCheck implements Check
{
    abstract protected function source(): string;

    abstract protected function maxAgeSeconds(): int;

    public function run(): CheckResult
    {
        try {
            $latest = Heartbeat::where('source', $this->source())->latest('created_at')->first();
            $limit = $this->maxAgeSeconds();
            $expected = "fresher than {$limit}s";

            if ($latest === null) {
                return CheckResult::fail($this->name(), $expected, 'never written', $this->neverHint());
            }

            $age = (int) abs(now()->diffInSeconds($latest->created_at));
            $actual = "{$age}s ago by {$latest->ran_as}";

            return $age <= $limit
                ? CheckResult::pass($this->name(), $expected, $actual)
                : CheckResult::fail($this->name(), $expected, $actual, $this->staleHint());
        } catch (Throwable $e) {
            return CheckResult::fail($this->name(), 'heartbeat table readable', 'query failed', $e->getMessage());
        }
    }

    abstract protected function neverHint(): string;

    abstract protected function staleHint(): string;
}
```

`app/Checks/QueueHeartbeatCheck.php`:

```php
<?php

namespace App\Checks;

use App\Models\Heartbeat;

final class QueueHeartbeatCheck extends HeartbeatCheck
{
    public function name(): string
    {
        return 'Queue heartbeat';
    }

    protected function source(): string
    {
        return Heartbeat::SOURCE_QUEUE;
    }

    protected function maxAgeSeconds(): int
    {
        return (int) config('testbed.queue_max_age');
    }

    protected function neverHint(): string
    {
        return 'No queue job has ever run. Is the queue:work daemon running? Is the scheduler dispatching (it feeds the queue)?';
    }

    protected function staleHint(): string
    {
        return 'Queue heartbeats stopped. If the scheduler heartbeat is fresh, the daemon is the problem; if both are stale, cron is.';
    }
}
```

**Amendment from the Task 3 review (important):** on a server configured with `QUEUE_CONNECTION=sync`, `dispatch()` runs the job inline in the cron process, so a fresh queue heartbeat would prove nothing about the `queue:work` daemon and this check would give a false green. Override `run()` in `QueueHeartbeatCheck` to guard that first:

```php
    public function run(): CheckResult
    {
        if (config('queue.default') === 'sync') {
            return CheckResult::fail(
                $this->name(),
                'a real queue connection (database)',
                'QUEUE_CONNECTION=sync',
                'With the sync driver, jobs run inline in the dispatching process, so this check cannot prove the queue:work daemon is alive. Set QUEUE_CONNECTION=database.'
            );
        }

        return parent::run();
    }
```

Add a test: with `config(['queue.default' => 'sync'])` and a fresh queue heartbeat present, the check still fails and its detail mentions `sync`. Note the test suite itself runs with `QUEUE_CONNECTION=sync` (phpunit.xml), so the other queue heartbeat tests in this task must set `config(['queue.default' => 'database'])` in their arrange step to exercise the freshness logic.

`app/Checks/SchedulerHeartbeatCheck.php`:

```php
<?php

namespace App\Checks;

use App\Models\Heartbeat;

final class SchedulerHeartbeatCheck extends HeartbeatCheck
{
    public function name(): string
    {
        return 'Scheduler heartbeat';
    }

    protected function source(): string
    {
        return Heartbeat::SOURCE_SCHEDULER;
    }

    protected function maxAgeSeconds(): int
    {
        return (int) config('testbed.scheduler_max_age');
    }

    protected function neverHint(): string
    {
        return 'schedule:run has never fired. Is the scheduled task installed in ShipYard (crontab)?';
    }

    protected function staleHint(): string
    {
        return 'Scheduler heartbeats stopped. Check the crontab entry and the scheduled task user.';
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=HeartbeatChecksTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Checks/HeartbeatCheck.php app/Checks/QueueHeartbeatCheck.php app/Checks/SchedulerHeartbeatCheck.php tests/Feature/HeartbeatChecksTest.php
git commit -m "Add queue and scheduler heartbeat checks"
```

---

### Task 7: ReleaseInfoCheck

**Files:**
- Create: `app/Checks/ReleaseInfoCheck.php`
- Test: `tests/Unit/ReleaseInfoCheckTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Unit/ReleaseInfoCheckTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Checks\ReleaseInfoCheck;
use Tests\TestCase;

class ReleaseInfoCheckTest extends TestCase
{
    public function test_reports_facts_and_never_judges(): void
    {
        $r = (new ReleaseInfoCheck)->run();

        $this->assertNull($r->pass);
        $this->assertStringContainsString(base_path(), $r->actual);
        $this->assertStringContainsString(PHP_VERSION, $r->actual);
    }

    public function test_includes_revision_when_the_file_exists(): void
    {
        file_put_contents(base_path('REVISION'), "abc1234\n");

        try {
            $r = (new ReleaseInfoCheck)->run();
            $this->assertStringContainsString('abc1234', $r->actual);
        } finally {
            unlink(base_path('REVISION'));
        }
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=ReleaseInfoCheckTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`app/Checks/ReleaseInfoCheck.php`:

```php
<?php

namespace App\Checks;

final class ReleaseInfoCheck implements Check
{
    public function name(): string
    {
        return 'Release info';
    }

    public function run(): CheckResult
    {
        $base = base_path();
        $atomic = str_contains($base, '/releases/') ? 'atomic (releases/N)' : 'in place';
        $revision = is_file($base.'/REVISION') ? trim((string) file_get_contents($base.'/REVISION')) : null;

        $facts = "path {$base}, layout {$atomic}, PHP ".PHP_VERSION;

        if ($revision !== null && $revision !== '') {
            $facts .= ", revision {$revision}";
        }

        return CheckResult::info($this->name(), $facts);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=ReleaseInfoCheckTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Checks/ReleaseInfoCheck.php tests/Unit/ReleaseInfoCheckTest.php
git commit -m "Add release info check"
```

---

### Task 8: Status endpoint (registration, controller, views, JSON, DB-down)

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (ChecksRunner binding)
- Create: `app/Http/Controllers/StatusController.php`, `resources/views/status.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/StatusPageTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/StatusPageTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_json_lists_all_seven_checks(): void
    {
        $response = $this->getJson('/status/json')->assertOk();

        $names = array_column($response->json('checks'), 'name');

        $this->assertSame([
            'PHP runtime user',
            'Database round trip',
            'Queue heartbeat',
            'Scheduler heartbeat',
            'Storage writable',
            'Env sync (TESTBED_MARKER)',
            'Release info',
        ], $names);
    }

    public function test_status_html_renders(): void
    {
        $this->get('/status')
            ->assertOk()
            ->assertSee('PHP runtime user')
            ->assertSee('Release info');
    }

    public function test_status_renders_with_the_database_down(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
        ]);
        \DB::purge('mysql');

        $response = $this->get('/status')->assertOk();

        $response->assertSee('Database round trip');
    }
}
```

Note: the DB-down test must NOT use RefreshDatabase semantics after reconfiguring; keep it in this class (RefreshDatabase migrated sqlite before the config swap, and the swap only affects the checks' queries), but if trait interference appears, move that one test to its own class without the trait and document why.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=StatusPageTest`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement**

In `app/Providers/AppServiceProvider.php`, add to `register()`:

```php
        $this->app->bind(\App\Checks\ChecksRunner::class, function () {
            return new \App\Checks\ChecksRunner([
                new \App\Checks\RuntimeUserCheck,
                new \App\Checks\DatabaseCheck,
                new \App\Checks\QueueHeartbeatCheck,
                new \App\Checks\SchedulerHeartbeatCheck,
                new \App\Checks\StorageWriteCheck,
                new \App\Checks\EnvSyncCheck,
                new \App\Checks\ReleaseInfoCheck,
            ]);
        });
```

`app/Http/Controllers/StatusController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Checks\ChecksRunner;

class StatusController extends Controller
{
    public function html(ChecksRunner $runner)
    {
        return view('status', ['results' => $runner->run()]);
    }

    public function json(ChecksRunner $runner)
    {
        $results = $runner->run();

        return response()->json([
            'ok' => collect($results)->every(fn ($r) => $r->pass !== false),
            'checks' => array_map(fn ($r) => $r->toArray(), $results),
        ]);
    }
}
```

`resources/views/status.blade.php` (no Vite, inline styles only):

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ShipYard Testbed Status</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; background: #0f172a; color: #e2e8f0; }
        h1 { font-size: 1.4rem; }
        table { border-collapse: collapse; width: 100%; max-width: 60rem; }
        th, td { text-align: left; padding: .6rem .8rem; border-bottom: 1px solid #334155; vertical-align: top; }
        .pass { color: #4ade80; font-weight: 600; }
        .fail { color: #f87171; font-weight: 600; }
        .info { color: #94a3b8; font-weight: 600; }
        .detail { color: #94a3b8; font-size: .85rem; }
        a { color: #7dd3fc; }
    </style>
</head>
<body>
    <h1>ShipYard Testbed Status</h1>
    <p><a href="/">home</a> · <a href="/status/json">json</a> · <a href="/notes">notes CRUD</a></p>
    <table>
        <thead>
            <tr><th>Check</th><th>State</th><th>Expected</th><th>Actual</th></tr>
        </thead>
        <tbody>
            @foreach ($results as $r)
                <tr>
                    <td>{{ $r->name }}</td>
                    <td>
                        @if ($r->pass === true) <span class="pass">PASS</span>
                        @elseif ($r->pass === false) <span class="fail">FAIL</span>
                        @else <span class="info">INFO</span>
                        @endif
                    </td>
                    <td>{{ $r->expected ?? '' }}</td>
                    <td>
                        {{ $r->actual }}
                        @if ($r->detail)
                            <div class="detail">{{ $r->detail }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
```

In `routes/web.php`, add:

```php
use App\Http\Controllers\StatusController;

Route::get('/status', [StatusController::class, 'html']);
Route::get('/status/json', [StatusController::class, 'json']);
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=StatusPageTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Http/Controllers/StatusController.php resources/views/status.blade.php routes/web.php tests/Feature/StatusPageTest.php
git commit -m "Add status page with HTML and JSON output"
```

---

### Task 9: Notes CRUD

**Files:**
- Create: `database/migrations/2026_07_28_000002_create_notes_table.php`, `app/Models/Note.php`, `app/Http/Controllers/NoteController.php`, `resources/views/notes/index.blade.php`, `resources/views/notes/form.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/NoteCrudTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/NoteCrudTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_notes(): void
    {
        Note::create(['title' => 'First', 'body' => 'hello']);

        $this->get('/notes')->assertOk()->assertSee('First');
    }

    public function test_store_creates_a_note(): void
    {
        $this->post('/notes', ['title' => 'New note', 'body' => 'content'])
            ->assertRedirect('/notes');

        $this->assertSame(1, Note::where('title', 'New note')->count());
    }

    public function test_store_validates_title(): void
    {
        $this->from('/notes/create')
            ->post('/notes', ['title' => '', 'body' => 'x'])
            ->assertRedirect('/notes/create')
            ->assertSessionHasErrors('title');
    }

    public function test_update_edits_a_note(): void
    {
        $note = Note::create(['title' => 'Old', 'body' => 'b']);

        $this->put("/notes/{$note->id}", ['title' => 'Updated', 'body' => 'b'])
            ->assertRedirect('/notes');

        $this->assertSame('Updated', $note->fresh()->title);
    }

    public function test_destroy_deletes_a_note(): void
    {
        $note = Note::create(['title' => 'Bye', 'body' => 'b']);

        $this->delete("/notes/{$note->id}")->assertRedirect('/notes');

        $this->assertNull(Note::find($note->id));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=NoteCrudTest`
Expected: FAIL.

- [ ] **Step 3: Implement**

`database/migrations/2026_07_28_000002_create_notes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
```

`app/Models/Note.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = ['title', 'body'];
}
```

`app/Http/Controllers/NoteController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index()
    {
        return view('notes.index', ['notes' => Note::latest()->get()]);
    }

    public function create()
    {
        return view('notes.form', ['note' => new Note]);
    }

    public function store(Request $request)
    {
        Note::create($request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
        ]));

        return redirect('/notes');
    }

    public function edit(Note $note)
    {
        return view('notes.form', ['note' => $note]);
    }

    public function update(Request $request, Note $note)
    {
        $note->update($request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
        ]));

        return redirect('/notes');
    }

    public function destroy(Note $note)
    {
        $note->delete();

        return redirect('/notes');
    }
}
```

`resources/views/notes/index.blade.php`:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Notes</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; background: #0f172a; color: #e2e8f0; }
        a { color: #7dd3fc; } table { border-collapse: collapse; }
        th, td { text-align: left; padding: .5rem .8rem; border-bottom: 1px solid #334155; }
        button { background: none; border: none; color: #f87171; cursor: pointer; padding: 0; font: inherit; }
    </style>
</head>
<body>
    <h1>Notes</h1>
    <p><a href="/">home</a> · <a href="/status">status</a> · <a href="/notes/create">new note</a></p>
    <table>
        <thead><tr><th>Title</th><th>Body</th><th></th><th></th></tr></thead>
        <tbody>
            @forelse ($notes as $note)
                <tr>
                    <td>{{ $note->title }}</td>
                    <td>{{ $note->body }}</td>
                    <td><a href="/notes/{{ $note->id }}/edit">edit</a></td>
                    <td>
                        <form method="POST" action="/notes/{{ $note->id }}">
                            @csrf @method('DELETE')
                            <button type="submit">delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4">No notes yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
```

`resources/views/notes/form.blade.php`:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $note->exists ? 'Edit note' : 'New note' }}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; background: #0f172a; color: #e2e8f0; }
        a { color: #7dd3fc; } label { display: block; margin-top: 1rem; }
        input, textarea { width: 24rem; padding: .4rem; background: #1e293b; color: inherit; border: 1px solid #334155; }
        .error { color: #f87171; } button { margin-top: 1rem; padding: .4rem 1rem; }
    </style>
</head>
<body>
    <h1>{{ $note->exists ? 'Edit note' : 'New note' }}</h1>
    <p><a href="/notes">back to notes</a></p>
    <form method="POST" action="{{ $note->exists ? '/notes/'.$note->id : '/notes' }}">
        @csrf
        @if ($note->exists) @method('PUT') @endif
        <label>Title <input name="title" value="{{ old('title', $note->title) }}"></label>
        @error('title') <div class="error">{{ $message }}</div> @enderror
        <label>Body <textarea name="body" rows="5">{{ old('body', $note->body) }}</textarea></label>
        <button type="submit">Save</button>
    </form>
</body>
</html>
```

In `routes/web.php`, add:

```php
use App\Http\Controllers\NoteController;

Route::resource('notes', NoteController::class)->except(['show']);
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test --filter=NoteCrudTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_28_000002_create_notes_table.php app/Models/Note.php app/Http/Controllers/NoteController.php resources/views/notes routes/web.php tests/Feature/NoteCrudTest.php
git commit -m "Add notes CRUD for interactive database testing"
```

---

### Task 10: Landing page, .env.example, README run book

**Files:**
- Create: `resources/views/home.blade.php`
- Modify: `routes/web.php` (replace the `/` route), `.env.example`, `README.md` (replace skeleton README)
- Delete: `resources/views/welcome.blade.php`
- Test: `tests/Feature/HomePageTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Feature/HomePageTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_home_links_to_status_and_notes(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/status')
            ->assertSee('/notes');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --filter=HomePageTest`
Expected: FAIL (welcome view has neither link) or errors after the welcome view is removed.

- [ ] **Step 3: Implement**

`resources/views/home.blade.php`:

```blade
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>ShipYard Testbed</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; background: #0f172a; color: #e2e8f0; }
        a { color: #7dd3fc; } li { margin: .4rem 0; }
    </style>
</head>
<body>
    <h1>ShipYard Testbed</h1>
    <p>Diagnostics fixture for verifying ShipYard deployments. Not a product; deploy to throwaway servers only.</p>
    <ul>
        <li><a href="/status">/status</a> all subsystem checks (HTML)</li>
        <li><a href="/status/json">/status/json</a> the same checks as JSON</li>
        <li><a href="/notes">/notes</a> CRUD for interactive database testing</li>
    </ul>
</body>
</html>
```

In `routes/web.php`, replace the skeleton `/` route with:

```php
Route::get('/', fn () => view('home'));
```

Delete `resources/views/welcome.blade.php`.

Append to `.env.example`:

```
TESTBED_EXPECTED_USER=
TESTBED_MARKER=
TESTBED_QUEUE_MAX_AGE=300
TESTBED_SCHEDULER_MAX_AGE=120
```

Also confirm `.env.example` has `QUEUE_CONNECTION=database` (Laravel 12 default; set it if not).

Replace `README.md` entirely with the run book. Follow the repo owner's documentation rule: no dashes as punctuation anywhere in this file (hyphens inside literal commands, paths, and variable names are fine). Content:

```markdown
# ShipYard Testbed

A diagnostics fixture for verifying [ShipYard](https://github.com/rmattone) deployments end to end. Deploy it to a throwaway server through ShipYard, open `/status`, and every subsystem a deploy touches reports pass or fail on one page: PHP runtime user, database, queue daemon, scheduler cron, storage permissions, env sync, and release layout.

This app has no auth and exists to be broken. Never deploy it anywhere that matters.

## What /status checks

| Check | Proves | Fails when |
|---|---|---|
| PHP runtime user | The FPM pool runs as the expected user | `TESTBED_EXPECTED_USER` is set and differs |
| Database round trip | DB credentials, the ShipYard created database, migrations | insert or read or delete fails |
| Queue heartbeat | The queue:work daemon runs and reaches the DB | newest queue heartbeat older than `TESTBED_QUEUE_MAX_AGE` (default 300s) |
| Scheduler heartbeat | Cron fires schedule:run | newest scheduler heartbeat older than `TESTBED_SCHEDULER_MAX_AGE` (default 120s) |
| Storage writable | storage/ ownership and permissions | writes under storage/ fail |
| Env sync | ShipYard's encrypted env pipeline | `TESTBED_MARKER` unset |
| Release info | Informational: path, atomic vs in place, PHP version | never (grey row) |

The scheduler command dispatches the queue job, so a working cron keeps both heartbeats flowing with no manual action. Scheduler fresh with queue stale means the daemon is broken. Both stale means cron is broken.

## Run book (fresh server verification)

1. Connect the fresh server in ShipYard (as ubuntu or root).
2. Provision the deploy user: Server Settings, Users, Create deploy user (name `shipyard`, sudo on, "Use as deploy user" on). Switch the connection to it.
3. Create a database and a database user in ShipYard; note the credentials.
4. Create the application: type Laravel, repository `https://github.com/rmattone-oston/shipyard-testbed.git`, branch `main`, no git provider.
5. Set env vars in ShipYard for the app: `APP_KEY` (generate locally with `php artisan key:generate --show`), `APP_ENV=production`, `APP_DEBUG=false`, `DB_*` from step 3, `QUEUE_CONNECTION=database`, `TESTBED_MARKER=hello`, `TESTBED_EXPECTED_USER=shipyard`.
6. Deploy. The default Laravel deploy script (composer install, migrate) is enough; no build command.
7. Add a daemon: command `php8.3 artisan queue:work --sleep=3 --tries=3`, directory the app's current path, user `shipyard` (the panel defaults it on provisioned servers).
8. Add a scheduled task: `php8.3 /home/shipyard/{app}/current/artisan schedule:run` every minute, user `shipyard`.
9. Open `/status` on the app's domain or server IP vhost. Expect every row green within two minutes (the heartbeats need one scheduler tick plus one queue run).
10. Poke `/notes`: create, edit, and delete a note.

### Legacy mode

On a server without a deploy user, leave `TESTBED_EXPECTED_USER` unset (the runtime user row turns grey and informational) and expect paths under `/var/www/shipyard`.

### Two gotchas the checks themselves warn about

Never deploy with `QUEUE_CONNECTION=sync`. The sync driver runs jobs inline in whichever process dispatches them, so the queue heartbeat would stay fresh even with no daemon running at all. The queue check fails outright when it sees `sync`, precisely so a false green is impossible.

The runtime user row degrades to informational when PHP has no posix extension. In that case the value shown is the script owner, not the process user, so it cannot prove which user PHP-FPM runs as. Standard Ubuntu PHP builds include posix, so this should not happen on a normal target.

### Manual extras not covered by /status

SSL issuance (needs a real domain), rollback (deploy twice, roll back, confirm `/status` release info shows the previous release path), and import (delete the app row in ShipYard, run import on the server, confirm re adoption).

## Deliberate breakage drills

Each of these should turn exactly the expected row red:

1. Stop the daemon: queue heartbeat goes stale.
2. Remove the crontab line: scheduler heartbeat goes stale (queue follows later).
3. `chown -R root:root storage/`: storage writable fails.
4. Unset `TESTBED_MARKER` and redeploy: env sync fails.
5. Set a wrong DB password: database round trip fails immediately, both heartbeats go stale later.

## Local development

    composer install
    php artisan test

The suite uses in memory SQLite; no services required.
```

- [ ] **Step 4: Run to verify pass**

Run: `php artisan test`
Expected: full suite PASS (skeleton example tests may need the welcome-view reference removed; the skeleton's `ExampleTest` asserts `/` returns 200, which still holds).

- [ ] **Step 5: Commit**

```bash
git add resources/views/home.blade.php routes/web.php .env.example README.md tests/Feature/HomePageTest.php
git rm resources/views/welcome.blade.php
git commit -m "Add landing page, env contract, and run book"
```

---

### Task 11: Final verification and push

- [ ] **Step 1: Full suite**

Run: `php artisan test`
Expected: all tests pass (roughly 26 tests across unit and feature).

- [ ] **Step 2: Boot check without a database**

```bash
php artisan route:list | grep -E "status|notes"
```

Expected: `/`, `/status`, `/status/json`, and the notes resource routes all listed.

- [ ] **Step 3: Manual smoke (optional but cheap)**

```bash
php artisan serve --port=8099 &
sleep 2 && curl -s http://127.0.0.1:8099/status/json | head -c 400; kill %1
```

Expected: JSON starting with `{"ok":` and a checks array (heartbeat checks fail red here, which is correct: nothing feeds them locally).

- [ ] **Step 4: Push**

```bash
git push origin main
```

## Verification checklist (after all tasks)

1. Full suite green locally on SQLite.
2. `/status/json` returns all seven checks in the specified order.
3. Repo public at github.com/rmattone-oston/shipyard-testbed and clonable anonymously over HTTPS.
4. The README run book matches the spec's step list and the breakage drills match the spec's success criteria.
5. First real use: follow the run book on the next fresh EC2 run of ShipYard (this also discharges the home-layout feature's pending manual verification).
