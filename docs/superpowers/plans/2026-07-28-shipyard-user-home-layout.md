# ShipYard Deploy User and Home Directory Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Servers gain a nullable `deploy_user`; when set, new apps default to `/home/{deploy_user}/{app}`, PHP runs through a ShipYard managed FPM pool owned by that user, and nginx points at the pool socket. Null keeps every current behavior.

**Architecture:** One new column (`servers.deploy_user`) drives all branching. `ServerUserService` provisions the user (home `711`) and can mark existing users. New `PhpFpmPoolService` idempotently installs a pool file and reloads FPM, called from `NginxService::deploy` for Laravel apps. Frontend reads a computed `default_deploy_base` field.

**Tech Stack:** Laravel 12 (PHP 8.2), phpseclib SSH (always mocked in tests), React 18 + TypeScript.

**Spec:** `docs/superpowers/specs/2026-07-28-shipyard-user-home-layout-design.md`

**Test command (from repo root):** the throwaway MySQL container must be running (`docker ps | grep shipyard-test-mysql`; if missing: `docker run -d --name shipyard-test-mysql -e MYSQL_ROOT_PASSWORD=testing -e MYSQL_DATABASE=server_management_testing -p 33061:3306 mysql:8`). Then:

```bash
cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=<Name>
```

**Conventions:** never mention Claude in commit messages. Run `./vendor/bin/pint <changed files>` before each backend commit. Work on branch `feature/shipyard-user-home-layout`.

---

### Task 1: `deploy_user` column and `default_deploy_base` accessor

**Files:**
- Create: `backend/database/migrations/2026_07_28_000001_add_deploy_user_to_servers_table.php`
- Modify: `backend/app/Models/Server.php`
- Create: `backend/tests/Feature/ServerDeployUserTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerDeployUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_deploy_user_persists_and_defaults_to_null(): void
    {
        $this->createOrgUser();

        $legacy = Server::factory()->create();
        $provisioned = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->assertNull($legacy->fresh()->deploy_user);
        $this->assertSame('shipyard', $provisioned->fresh()->deploy_user);
    }

    public function test_server_json_exposes_default_deploy_base(): void
    {
        $user = $this->createOrgUser();

        $legacy = Server::factory()->create();
        $provisioned = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->actingAs($user)->getJson("/api/servers/{$legacy->id}")
            ->assertOk()
            ->assertJsonPath('default_deploy_base', '/var/www/shipyard');

        $this->actingAs($user)->getJson("/api/servers/{$provisioned->id}")
            ->assertOk()
            ->assertJsonPath('deploy_user', 'shipyard')
            ->assertJsonPath('default_deploy_base', '/home/shipyard');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerDeployUserTest`
Expected: FAIL (unknown column `deploy_user`)

- [ ] **Step 3: Create the migration**

`backend/database/migrations/2026_07_28_000001_add_deploy_user_to_servers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Null means the legacy /var/www layout; a value means the
            // home directory layout owned by this unix user.
            $table->string('deploy_user', 32)->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('deploy_user');
        });
    }
};
```

- [ ] **Step 4: Update the Server model**

In `backend/app/Models/Server.php`, add `'deploy_user',` to `$fillable` (after `'username',`), and add below `$hidden`:

```php
    protected $appends = ['default_deploy_base'];
```

Add this method after `isLocal()`:

```php
    /**
     * Base directory new applications default into. Servers with a
     * provisioned deploy user use the home directory layout.
     */
    public function getDefaultDeployBaseAttribute(): string
    {
        return $this->deploy_user !== null
            ? "/home/{$this->deploy_user}"
            : '/var/www/shipyard';
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerDeployUserTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Models/Server.php database/migrations/2026_07_28_000001_add_deploy_user_to_servers_table.php tests/Feature/ServerDeployUserTest.php
git add -A && git commit -m "Add deploy_user column and default_deploy_base to servers"
```

---

### Task 2: Home layout default deploy path

**Files:**
- Modify: `backend/app/Models/Application.php:64-88`
- Modify: `backend/app/Http/Controllers/Api/ApplicationController.php:87-91, 417-426`
- Test: `backend/tests/Feature/ApplicationDeployPathTest.php`

**Important context from Task 1 review:** `ApplicationController::store` resolves `deploy_path` itself (line ~89) before calling `Application::create`, so the model's `creating` hook never fires on the API path; the controller call must be updated too or the API tests fail. The `/applications/generate-path` preview endpoint (line ~417) also calls `generateDeployPath` with no server context. `Server::default_deploy_base` (added in Task 1) is the single source of truth for the base path; `generateDeployPath` must consume it rather than duplicate the branch.

- [ ] **Step 1: Write the failing tests**

Append to `ApplicationDeployPathTest` (it already has a `payload()` helper that omits `deploy_path`):

```php
    public function test_default_deploy_path_uses_home_layout_when_server_has_deploy_user(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server));

        $response->assertStatus(201);
        $this->assertSame('/home/shipyard/my-app', $response->json('deploy_path'));
    }

    public function test_default_deploy_path_keeps_var_www_when_server_has_no_deploy_user(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/applications', $this->payload($server));

        $response->assertStatus(201);
        $this->assertSame('/var/www/shipyard/my-app', $response->json('deploy_path'));
    }

    public function test_generate_path_preview_uses_the_server_layout_when_given_a_server(): void
    {
        $user = $this->createOrgUser();
        $server = Server::factory()->create(['deploy_user' => 'shipyard']);

        $this->actingAs($user)
            ->postJson('/api/applications/generate-path', ['name' => 'My App', 'server_id' => $server->id])
            ->assertOk()
            ->assertJsonPath('deploy_path', '/home/shipyard/my-app');
    }

    public function test_generate_path_preview_defaults_to_legacy_without_a_server(): void
    {
        $user = $this->createOrgUser();

        $this->actingAs($user)
            ->postJson('/api/applications/generate-path', ['name' => 'My App'])
            ->assertOk()
            ->assertJsonPath('deploy_path', '/var/www/shipyard/my-app');
    }
```

- [ ] **Step 2: Run tests to verify the first fails**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ApplicationDeployPathTest`
Expected: FAIL on `test_default_deploy_path_uses_home_layout_when_server_has_deploy_user` and `test_generate_path_preview_uses_the_server_layout_when_given_a_server` (both still produce `/var/www/shipyard/my-app`); the two legacy-path tests pass.

- [ ] **Step 3: Implement**

In `backend/app/Models/Application.php`, change the `creating` hook default (currently `self::generateDeployPath($application->name)`) to:

```php
            // Auto-generate deploy path if not set
            if (empty($application->deploy_path)) {
                $application->deploy_path = self::generateDeployPath($application->name, $application->server);
            }
```

Change `generateDeployPath` to (the base path decision lives on `Server::default_deploy_base`; do not duplicate the branch here):

```php
    public static function generateDeployPath(string $name, ?Server $server = null): string
    {
        $safeName = strtolower(preg_replace('/[^a-zA-Z0-9\-]/', '-', $name));
        $safeName = preg_replace('/-+/', '-', $safeName); // collapse multiple dashes
        $safeName = trim($safeName, '-');

        $base = $server?->default_deploy_base ?? '/var/www/shipyard';

        return "{$base}/{$safeName}";
    }
```

(`Server` is already imported in the model via the `server()` relation; add the `use` statement if missing.)

In `backend/app/Http/Controllers/Api/ApplicationController.php` `store()` (line ~89), the controller pre-resolves the path, so pass the server (already imported; `Server::find` is organization-scoped by the global scope, never use an unconstrained `exists:` here):

```php
        $validated['deploy_path'] = $validated['deploy_path']
            ?? Application::generateDeployPath($validated['name'], Server::find($validated['server_id']));
```

In the same controller, `generateDeployPath()` preview action (line ~417) gains optional server context:

```php
    public function generateDeployPath(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'server_id' => 'nullable|integer',
        ]);

        // Server::find is organization scoped; a foreign org id resolves to
        // null and falls back to the legacy base.
        $server = $request->filled('server_id') ? Server::find($request->input('server_id')) : null;

        return response()->json([
            'deploy_path' => Application::generateDeployPath($request->input('name'), $server),
        ]);
    }
```

Check other callers: `grep -rn "generateDeployPath" backend/app backend/tests`. The optional parameter keeps remaining call sites valid.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ApplicationDeployPathTest`
Expected: PASS (all 8 tests). Also run `--filter=ApplicationApiTest` to confirm the store path change regressed nothing.

- [ ] **Step 5: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Models/Application.php app/Http/Controllers/Api/ApplicationController.php tests/Feature/ApplicationDeployPathTest.php
git add app/Models/Application.php app/Http/Controllers/Api/ApplicationController.php tests/Feature/ApplicationDeployPathTest.php && git commit -m "Default new apps to the deploy user home layout"
```

---

### Task 3: Provisioning: home 711 and `use_as_deploy_user`

**Files:**
- Modify: `backend/app/Services/ServerUserService.php:58-113, 244-289`
- Modify: `backend/app/Http/Controllers/Api/ServerUserController.php:27-49`
- Test: `backend/tests/Feature/ServerUserApiTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `ServerUserApiTest` (reuse its `mockSsh()` helper and `$this->uploadedScripts`; `Queue::fake()` is required because a successful create dispatches `ProcessServerSshKeyInstall`):

```php
    public function test_create_user_script_restricts_home_directory_to_711(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", ['username' => 'shipyard'])
            ->assertStatus(201);

        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString("chmod 711 '/home/shipyard'", $script);
    }

    public function test_create_user_with_use_as_deploy_user_sets_the_server_column(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(201);

        $this->assertSame('shipyard', $this->server->fresh()->deploy_user);
    }

    public function test_create_user_without_flag_leaves_deploy_user_null(): void
    {
        Queue::fake();
        $this->mockSsh('');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", ['username' => 'shipyard'])
            ->assertStatus(201);

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_failed_create_does_not_set_deploy_user(): void
    {
        Queue::fake();
        $this->mockSsh('SHIPYARD_USER_EXISTS');

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/users", [
                'username' => 'shipyard',
                'use_as_deploy_user' => true,
            ])
            ->assertStatus(422);

        $this->assertNull($this->server->fresh()->deploy_user);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerUserApiTest`
Expected: the four new tests FAIL (missing chmod line, deploy_user never set); all existing tests still pass.

- [ ] **Step 3: Implement the service change**

In `ServerUserService::createDeployUser`, change the signature:

```php
    public function createDeployUser(Server $server, string $username, bool $sudo, bool $useAsDeployUser = false): void
```

At the end of the method, after `ProcessServerSshKeyInstall::dispatch($sshKey);`, add:

```php
        if ($useAsDeployUser) {
            $server->update(['deploy_user' => $validated]);
        }
```

In `buildCreateDeployUserScript`, right after the `useradd` line, append to `$lines`:

```php
            "\$SUDO useradd -m -s /bin/bash {$quotedUser}",
            '',
            // 711 lets nginx (www-data) traverse into webroots under the
            // home directory without being able to list or read it. A more
            // permissive default here produces confusing 403s or leaks the
            // app list on shared servers.
            '$SUDO chmod 711 '.escapeshellarg('/home/'.$username),
```

(The `useradd` line already exists; only the comment and chmod lines are new.)

- [ ] **Step 4: Implement the controller change**

In `ServerUserController::store`, add to the validation array:

```php
            'use_as_deploy_user' => ['nullable', 'boolean'],
```

and change the service call to:

```php
        $useAsDeployUser = $request->boolean('use_as_deploy_user', false);

        try {
            $serverUserService->createDeployUser($server, $validated['username'], $sudo, $useAsDeployUser);
        } catch (InvalidArgumentException $e) {
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerUserApiTest`
Expected: PASS (all tests)

- [ ] **Step 6: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Services/ServerUserService.php app/Http/Controllers/Api/ServerUserController.php tests/Feature/ServerUserApiTest.php
git add -A && git commit -m "Provision deploy users with 711 homes and optional deploy_user assignment"
```

---

### Task 4: Mark an existing user as deploy user

**Files:**
- Modify: `backend/app/Services/ServerUserService.php` (new method)
- Modify: `backend/app/Http/Controllers/Api/ServerUserController.php` (new action)
- Modify: `backend/routes/api.php:130-132`
- Test: `backend/tests/Feature/ServerUserApiTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `ServerUserApiTest`. The `USERS_FIXTURE` constant already lists a `deploy` user with home `/home/deploy` and an `analytics` user with home `/home/analytics`:

```php
    public function test_set_deploy_user_marks_an_existing_user(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'deploy'])
            ->assertOk()
            ->assertJsonPath('deploy_user', 'deploy')
            ->assertJsonPath('default_deploy_base', '/home/deploy');

        $this->assertSame('deploy', $this->server->fresh()->deploy_user);

        // The follow-up script restricts the home directory.
        $joined = implode("\n", $this->uploadedScripts);
        $this->assertStringContainsString("chmod 711 '/home/deploy'", $joined);
    }

    public function test_set_deploy_user_rejects_unknown_user(): void
    {
        $this->mockSsh(self::USERS_FIXTURE);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'ghost'])
            ->assertStatus(422);

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_root(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'root'])
            ->assertStatus(422);

        $this->assertNull($this->server->fresh()->deploy_user);
    }

    public function test_set_deploy_user_rejects_nonstandard_home(): void
    {
        // Fixture with a user whose home is outside /home; the layout
        // generates /home/{user} paths, so this must be refused loudly.
        $fixture = <<<'TXT'
        USER:root:0:/root:/bin/bash
        USER:svc:1000:/srv/svc:/bin/bash
        SUDO:root:yes
        SUDO:svc:no
        TXT;

        $this->mockSsh($fixture);

        $this->actingAs($this->user)
            ->postJson("/api/servers/{$this->server->id}/deploy-user", ['username' => 'svc'])
            ->assertStatus(422);

        $this->assertNull($this->server->fresh()->deploy_user);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerUserApiTest`
Expected: the four new tests FAIL with 404 (route does not exist).

- [ ] **Step 3: Implement the service method**

Add to `ServerUserService` (after `switchConnectionUser`):

```php
    /**
     * Mark an existing unix user as this server's deploy user. The user must
     * exist and live under /home, because the home layout generates
     * /home/{user}/{app} paths. Root is refused: its home is /root and apps
     * must never run as root.
     */
    public function markDeployUser(Server $server, string $username): Server
    {
        $validated = $this->assertValidUsername($username);

        if ($validated === 'root') {
            throw new InvalidArgumentException('Root cannot be used as the deploy user.');
        }

        $found = null;

        foreach ($this->listUsers($server) as $remoteUser) {
            if ($remoteUser['name'] === $validated) {
                $found = $remoteUser;

                break;
            }
        }

        if ($found === null) {
            throw new InvalidArgumentException("User '{$validated}' was not found on this server.");
        }

        if ($found['home'] !== '/home/'.$validated) {
            throw new InvalidArgumentException(
                "User '{$validated}' has home directory '{$found['home']}', but the deploy layout requires '/home/{$validated}'."
            );
        }

        // Same traversal rule as freshly provisioned users: nginx needs
        // execute on the home directory, nothing more.
        $script = implode("\n", [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            '$SUDO chmod 711 '.escapeshellarg('/home/'.$validated),
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            throw new RuntimeException("Failed to restrict /home/{$validated} to 711: ".$result['output']);
        }

        $server->update(['deploy_user' => $validated]);

        return $server->fresh();
    }
```

- [ ] **Step 4: Implement controller action and route**

Add to `ServerUserController`:

```php
    public function setDeployUser(Request $request, Server $server, ServerUserService $serverUserService): JsonResponse
    {
        $validated = $request->validate([
            'username' => self::USERNAME_RULES,
        ]);

        try {
            $server = $serverUserService->markDeployUser($server, $validated['username']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json($server);
    }
```

In `routes/api.php`, next to the existing server user routes (line ~132), add:

```php
    Route::post('/servers/{server}/deploy-user', [ServerUserController::class, 'setDeployUser']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ServerUserApiTest`
Expected: PASS (all tests)

- [ ] **Step 6: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Services/ServerUserService.php app/Http/Controllers/Api/ServerUserController.php routes/api.php tests/Feature/ServerUserApiTest.php
git add -A && git commit -m "Allow marking an existing server user as the deploy user"
```

---

### Task 5: PhpFpmPoolService

**Files:**
- Create: `backend/app/Services/PhpFpmPoolService.php`
- Create: `backend/tests/Feature/PhpFpmPoolTest.php`

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/PhpFpmPoolTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\PhpFpmPoolService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The ShipYard FPM pool is what makes the home directory layout actually
 * serve PHP: the pool runs as the deploy user (code owner == code executor)
 * while nginx keeps talking to a www-data owned socket. These tests pin the
 * dangerous properties: the pool file is validated with php-fpm -t before
 * FPM is ever reloaded, and a failed validation must roll the file back.
 */
class PhpFpmPoolTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] */
    private array $uploadedScripts = [];

    /** @var string[] */
    private array $executedCommands = [];

    private function mockSsh(string $scriptOutput, bool $scriptSucceeds = true): void
    {
        $this->uploadedScripts = [];
        $this->executedCommands = [];

        $this->mock(SSHService::class, function ($mock) use ($scriptOutput, $scriptSucceeds) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturnUsing(function (string $content, string $path) {
                $this->uploadedScripts[] = $content;

                return true;
            });
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use ($scriptOutput, $scriptSucceeds) {
                $this->executedCommands[] = $command;

                if (str_starts_with($command, 'bash ')) {
                    return [
                        'output' => $scriptOutput,
                        'exit_code' => $scriptSucceeds ? 0 : 1,
                        'success' => $scriptSucceeds,
                    ];
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });
    }

    private function provisionedServer(): Server
    {
        $this->createOrgUser();

        return Server::factory()->create(['deploy_user' => 'shipyard']);
    }

    public function test_pool_config_runs_as_the_deploy_user(): void
    {
        $server = $this->provisionedServer();

        $config = app(PhpFpmPoolService::class)->poolConfig($server, '8.3');

        $this->assertStringContainsString('[shipyard]', $config);
        $this->assertStringContainsString('user = shipyard', $config);
        $this->assertStringContainsString('group = shipyard', $config);
        $this->assertStringContainsString('listen = /run/php/php8.3-fpm-shipyard.sock', $config);
        $this->assertStringContainsString('listen.owner = www-data', $config);
        $this->assertStringContainsString('listen.group = www-data', $config);
    }

    public function test_ensure_pool_script_validates_before_reload_and_can_roll_back(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_POOL_APPLIED');

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');

        $script = $this->uploadedScripts[0];
        $this->assertStringContainsString('/etc/php/8.3/fpm/pool.d/shipyard.conf', $script);
        $this->assertStringContainsString('php-fpm8.3 -t', $script);
        $this->assertStringContainsString('reload-or-restart php8.3-fpm', $script);
        // Validation failure must restore the previous pool file.
        $this->assertStringContainsString('SHIPYARD_POOL_INVALID', $script);
        // The reload must come after the validation in the script.
        $this->assertGreaterThan(
            strpos($script, 'php-fpm8.3 -t'),
            strpos($script, 'reload-or-restart'),
        );
    }

    public function test_ensure_pool_throws_when_validation_fails_remotely(): void
    {
        $server = $this->provisionedServer();
        $this->mockSsh('SHIPYARD_POOL_INVALID', scriptSucceeds: false);

        $this->expectException(RuntimeException::class);

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_requires_a_deploy_user(): void
    {
        $this->createOrgUser();
        $server = Server::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3');
    }

    public function test_ensure_pool_rejects_malformed_php_versions(): void
    {
        $server = $this->provisionedServer();

        $this->expectException(InvalidArgumentException::class);

        app(PhpFpmPoolService::class)->ensurePool($server, '8.3; rm -rf /');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=PhpFpmPoolTest`
Expected: FAIL (class `PhpFpmPoolService` not found)

- [ ] **Step 3: Implement the service**

`backend/app/Services/PhpFpmPoolService.php`:

```php
<?php

namespace App\Services;

use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Installs and maintains the ShipYard PHP-FPM pool on servers using the
 * home directory layout. The pool runs as the deploy user so the code owner
 * and the code executor are the same unix user; nginx (www-data) only needs
 * the socket. Mirrors the safety pattern used by NginxService: the new pool
 * file is validated (php-fpm -t) BEFORE FPM is reloaded, and a failed
 * validation restores the previous file so a broken pool can never take the
 * FPM service down.
 */
class PhpFpmPoolService
{
    use RunsRemoteScripts;

    private const POOL_INVALID_MARKER = 'SHIPYARD_POOL_INVALID';

    private const PHP_VERSION_PATTERN = '/^\d+\.\d+$/';

    public function __construct(
        protected SSHService $sshService,
    ) {}

    public static function socketPath(string $phpVersion): string
    {
        return "/run/php/php{$phpVersion}-fpm-shipyard.sock";
    }

    public function poolConfig(Server $server, string $phpVersion): string
    {
        $user = $server->deploy_user;
        $socket = self::socketPath($phpVersion);

        return <<<INI
        [shipyard]
        user = {$user}
        group = {$user}
        listen = {$socket}
        listen.owner = www-data
        listen.group = www-data
        listen.mode = 0660
        pm = ondemand
        pm.max_children = 10
        pm.process_idle_timeout = 10s
        pm.max_requests = 500
        INI;
    }

    /**
     * Idempotently install the pool for one PHP version. Unchanged content
     * short-circuits server-side without touching FPM.
     */
    public function ensurePool(Server $server, string $phpVersion): void
    {
        if ($server->deploy_user === null) {
            throw new InvalidArgumentException('This server has no deploy user; the ShipYard FPM pool only applies to the home directory layout.');
        }

        if (! preg_match(self::PHP_VERSION_PATTERN, $phpVersion)) {
            throw new InvalidArgumentException("Invalid PHP version '{$phpVersion}'.");
        }

        $poolPath = "/etc/php/{$phpVersion}/fpm/pool.d/shipyard.conf";
        $delimiter = 'SHIPYARD_EOF_'.Str::random(32);
        $config = $this->poolConfig($server, $phpVersion);

        $script = implode("\n", [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            'TMP=$(mktemp)',
            'trap \'rm -f "$TMP"\' EXIT',
            '',
            "cat <<'{$delimiter}' > \"\$TMP\"",
            $config,
            $delimiter,
            '',
            "POOL={$poolPath}",
            '',
            'if $SUDO test -f "$POOL" && $SUDO cmp -s "$TMP" "$POOL"; then',
            '    echo SHIPYARD_POOL_UNCHANGED',
            '    exit 0',
            'fi',
            '',
            'BACKUP=""',
            'if $SUDO test -f "$POOL"; then',
            '    BACKUP="${POOL}.shipyard-prev"',
            '    $SUDO cp "$POOL" "$BACKUP"',
            'fi',
            '',
            '$SUDO install -m 0644 "$TMP" "$POOL"',
            '',
            // Never reload FPM on an unvalidated pool: a broken pool file
            // takes down every PHP site on the box, not just this one.
            "if ! \$SUDO php-fpm{$phpVersion} -t; then",
            '    if [ -n "$BACKUP" ]; then',
            '        $SUDO mv "$BACKUP" "$POOL"',
            '    else',
            '        $SUDO rm -f "$POOL"',
            '    fi',
            '    echo '.self::POOL_INVALID_MARKER,
            '    exit 3',
            'fi',
            '',
            'if [ -n "$BACKUP" ]; then',
            '    $SUDO rm -f "$BACKUP"',
            'fi',
            '',
            "\$SUDO systemctl reload-or-restart php{$phpVersion}-fpm",
            'echo SHIPYARD_POOL_APPLIED',
        ]);

        try {
            $result = $this->runRemoteScript($server, $script, 120);
        } finally {
            $this->sshService->disconnect();
        }

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        if (in_array(self::POOL_INVALID_MARKER, $lines, true)) {
            throw new RuntimeException(
                "The generated PHP-FPM pool failed validation (php-fpm{$phpVersion} -t) and was rolled back. Output: ".$result['output']
            );
        }

        if (! $result['success']) {
            throw new RuntimeException("Failed to install the ShipYard PHP-FPM pool for PHP {$phpVersion}: ".$result['output']);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=PhpFpmPoolTest`
Expected: PASS (5 tests)

- [ ] **Step 5: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Services/PhpFpmPoolService.php tests/Feature/PhpFpmPoolTest.php
git add -A && git commit -m "Add PhpFpmPoolService for deploy user owned FPM pools"
```

---

### Task 6: Nginx socket selection and pool hook

**Files:**
- Modify: `backend/app/Services/NginxService.php:21-23, 94-102, 286-291`
- Test: `backend/tests/Feature/NginxTemplateTest.php`

- [ ] **Step 1: Write the failing template tests**

Append to `NginxTemplateTest` (its `makeApp()` helper accepts server attributes):

```php
    public function test_laravel_template_uses_shipyard_socket_on_provisioned_servers(): void
    {
        $app = $this->makeApp(['type' => 'laravel'], ['deploy_user' => 'shipyard']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.3-fpm-shipyard.sock;', $config);
        $this->assertStringNotContainsString('/var/run/php/php8.3-fpm.sock', $config);
    }

    public function test_laravel_template_keeps_distro_socket_on_legacy_servers(): void
    {
        $app = $this->makeApp(['type' => 'laravel']);

        $config = app(NginxService::class)->generateConfig($app);

        $this->assertStringContainsString('fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;', $config);
        $this->assertStringNotContainsString('fpm-shipyard.sock', $config);
    }
```

Note: if `ApplicationFactory` sets a different default `php_version`, pass `'php_version' => '8.3'` in the app attributes so the assertions are deterministic.

- [ ] **Step 2: Run tests to verify the first fails**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=NginxTemplateTest`
Expected: FAIL on the provisioned server test only.

- [ ] **Step 3: Implement socket selection**

In `NginxService`, change the constructor to:

```php
    public function __construct(
        private SSHService $sshService,
        private PhpFpmPoolService $phpFpmPoolService,
    ) {}
```

In `laravelTemplate` (line ~290) replace:

```php
        $phpSocket = "unix:/var/run/php/php{$app->getPhpVersion()}-fpm.sock";
```

with:

```php
        $phpSocket = 'unix:'.$this->phpSocketPath($app);
```

and add this private method next to `laravelTemplate`:

```php
    /**
     * Servers with a deploy user serve PHP through the ShipYard managed
     * pool (owned by that user); legacy servers keep the distro default
     * pool socket. Decided per server so one server never mixes layouts.
     */
    private function phpSocketPath(Application $app): string
    {
        $version = $app->getPhpVersion();

        if ($app->server?->deploy_user !== null) {
            return PhpFpmPoolService::socketPath($version);
        }

        return "/var/run/php/php{$version}-fpm.sock";
    }
```

- [ ] **Step 4: Run template tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=NginxTemplateTest`
Expected: PASS

- [ ] **Step 5: Write the failing pool hook test**

Append to `NginxTemplateTest`:

```php
    public function test_deploy_ensures_the_fpm_pool_for_laravel_apps_on_provisioned_servers(): void
    {
        $app = $this->makeApp(['type' => 'laravel'], ['deploy_user' => 'shipyard']);

        $this->mock(\App\Services\PhpFpmPoolService::class, function ($mock) use ($app) {
            $mock->shouldReceive('ensurePool')
                ->once()
                ->withArgs(fn ($server, $version) => $server->id === $app->server_id && $version === $app->getPhpVersion());
        });

        $this->mock(\App\Services\SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturn(['output' => '', 'exit_code' => 0, 'success' => true]);
        });

        app(NginxService::class)->deploy($app);
    }

    public function test_deploy_skips_the_fpm_pool_on_legacy_servers(): void
    {
        $app = $this->makeApp(['type' => 'laravel']);

        $this->mock(\App\Services\PhpFpmPoolService::class, function ($mock) {
            $mock->shouldReceive('ensurePool')->never();
        });

        $this->mock(\App\Services\SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('connectSftp')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('uploadContent')->andReturn(true);
            $mock->shouldReceive('execute')->andReturn(['output' => '', 'exit_code' => 0, 'success' => true]);
        });

        app(NginxService::class)->deploy($app);
    }
```

- [ ] **Step 6: Run to verify the first hook test fails**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=NginxTemplateTest`
Expected: FAIL (`ensurePool` expected once, called zero times)

- [ ] **Step 7: Implement the hook**

At the top of `NginxService::deploy`, before `$config = $this->generateConfig($app);`, add:

```php
        // The vhost is about to reference the ShipYard pool socket; make
        // sure the pool exists first. Idempotent and cheap when unchanged.
        if ($app->type === 'laravel' && $app->server?->deploy_user !== null) {
            $this->phpFpmPoolService->ensurePool($app->server, $app->getPhpVersion());
        }
```

- [ ] **Step 8: Run the affected suites**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=Nginx`
Expected: PASS (NginxTemplateTest, NginxConfigNamingTest, NginxConfigRollbackTest, NginxPrivilegeTest all green; the last three exercise `deploy()` on legacy servers where the hook is a no-op)

- [ ] **Step 9: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Services/NginxService.php tests/Feature/NginxTemplateTest.php
git add -A && git commit -m "Point nginx at the shipyard FPM pool on provisioned servers"
```

---

### Task 7: Import scans the deploy user home

**Files:**
- Modify: `backend/app/Services/ApplicationImportService.php:12-14, 141-148`
- Test: `backend/tests/Feature/ApplicationImportTest.php`

- [ ] **Step 1: Write the failing test**

Append to `ApplicationImportTest` (match its existing SSH mocking style if one exists; otherwise this self-contained test works):

```php
    public function test_import_scans_the_deploy_user_home_on_provisioned_servers(): void
    {
        $this->createOrgUser();
        $server = \App\Models\Server::factory()->create(['deploy_user' => 'shipyard']);

        $findCommands = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use (&$findCommands) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use (&$findCommands) {
                if (str_starts_with($command, 'find ')) {
                    $findCommands[] = $command;
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });

        app(\App\Services\ApplicationImportService::class)->import($server);

        $this->assertNotEmpty($findCommands);
        $this->assertStringContainsString('/home/shipyard', $findCommands[0]);
        $this->assertStringContainsString('/var/www', $findCommands[0]);
    }

    public function test_import_does_not_scan_home_on_legacy_servers(): void
    {
        $this->createOrgUser();
        $server = \App\Models\Server::factory()->create();

        $findCommands = [];

        $this->mock(\App\Services\SSHService::class, function ($mock) use (&$findCommands) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) use (&$findCommands) {
                if (str_starts_with($command, 'find ')) {
                    $findCommands[] = $command;
                }

                return ['output' => '', 'exit_code' => 0, 'success' => true];
            });
        });

        app(\App\Services\ApplicationImportService::class)->import($server);

        $this->assertNotEmpty($findCommands);
        $this->assertStringNotContainsString('/home/', $findCommands[0]);
    }
```

- [ ] **Step 2: Run tests to verify the first fails**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ApplicationImportTest`
Expected: FAIL on the provisioned server test (`/home/shipyard` missing from the find command).

- [ ] **Step 3: Implement**

In `ApplicationImportService`, change `listCandidateDirs()` to take the server and add a bases helper:

```php
    /** @return array<int, string> */
    private function scanBases(Server $server): array
    {
        $bases = self::SCAN_BASES;

        if ($server->deploy_user !== null) {
            $bases[] = '/home/'.$server->deploy_user;
        }

        return $bases;
    }

    /** @return array<int, string> */
    private function listCandidateDirs(Server $server): array
    {
        $bases = implode(' ', $this->scanBases($server));
        $result = $this->sshService->execute("find {$bases} -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort -u", 30);

        return array_values(array_filter(array_map('trim', explode("\n", $result['output'] ?? ''))));
    }
```

Update the call site in `import()` from `$this->listCandidateDirs()` to `$this->listCandidateDirs($server)`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test --filter=ApplicationImportTest`
Expected: PASS (all tests)

- [ ] **Step 5: Format and commit**

```bash
cd backend && ./vendor/bin/pint app/Services/ApplicationImportService.php tests/Feature/ApplicationImportTest.php
git add -A && git commit -m "Scan the deploy user home during application import"
```

---

### Task 8: Frontend

**Files:**
- Modify: `frontend/src/services/api.ts:74-85, 917-935`
- Modify: `frontend/src/pages/apps/AppNew.tsx:171, ~312`
- Modify: `frontend/src/pages/servers/settings/UsersSection.tsx`
- Modify: `frontend/src/components/SchedulerPanel.tsx:364`
- Modify: `frontend/src/components/DaemonsPanel.tsx:533`

There is no frontend test suite; verification is `npm run lint` plus `npm run build`.

- [ ] **Step 1: Extend the API client**

In `api.ts`, add to the `Server` interface (after `php_version`):

```ts
  deploy_user?: string | null
  default_deploy_base?: string
```

Also update the unused-but-exported `applicationsApi.generateDeployPath` client (line ~729) to accept and pass an optional `server_id`, matching the backend preview endpoint extended in Task 2:

```ts
  generateDeployPath: (name: string, serverId?: number) =>
    api.post<{ deploy_path: string }>('/applications/generate-path', { name, server_id: serverId }),
```

In the `serverUsersApi` object (line ~928), change `create`'s data type and add `setDeployUser`:

```ts
  create: (serverId: number, data: { username: string; sudo: boolean; use_as_deploy_user?: boolean }) =>
    api.post('/servers/' + serverId + '/users', data),
  setDeployUser: (serverId: number, data: { username: string }) =>
    api.post<Server>('/servers/' + serverId + '/deploy-user', data),
```

(Keep the existing `list` and `switchUser` entries unchanged.)

- [ ] **Step 2: Dynamic deploy path prefix in AppNew**

In `AppNew.tsx`, add near the top of the component body (after the `server` state is available in render paths, i.e. below the `if (!server)` guard is fine because both uses render after it):

```ts
  const deployBase = server?.default_deploy_base ?? '/var/www/shipyard'
```

Change line 171 from:

```ts
        deploy_path: formData.deploy_path ? `/var/www/shipyard/${formData.deploy_path}` : undefined,
```

to:

```ts
        deploy_path: formData.deploy_path ? `${deployBase}/${formData.deploy_path}` : undefined,
```

Change the visible prefix at line ~312 from the literal `/var/www/shipyard/` to `{deployBase}/`.

- [ ] **Step 3: Update placeholders**

- `SchedulerPanel.tsx:364`: placeholder becomes `php8.3 /home/shipyard/app/current/artisan schedule:run`
- `DaemonsPanel.tsx:533`: placeholder becomes `/home/shipyard/app/current`

- [ ] **Step 4: UsersSection provisioning flow**

In `UsersSection.tsx`:

1. Create form state gains the flag and defaults to the canonical username:

```ts
  const [createForm, setCreateForm] = useState({ username: 'shipyard', sudo: true, use_as_deploy_user: true })
```

and `openCreateDialog` resets to the same object. The dialog button label stays "Create deploy user"; the username input keeps working for custom names.

2. Add a switch to the create dialog below the sudo switch, same markup pattern:

```tsx
            <div className="flex items-center justify-between py-2">
              <div>
                <p className="font-medium text-sm">Use as deploy user</p>
                <p className="text-sm text-muted-foreground">
                  New applications will live in /home/{createForm.username || 'shipyard'} and run as this user.
                </p>
              </div>
              <Switch
                checked={createForm.use_as_deploy_user}
                onCheckedChange={(checked) => setCreateForm({ ...createForm, use_as_deploy_user: checked })}
              />
            </div>
```

3. After a successful create with the flag on, refresh the server object so the badge and AppNew prefix update. `handleCreate` becomes:

```ts
      await serverUsersApi.create(server.id, createForm)
      toast.success('User creation started')
      setShowCreateDialog(false)
      if (createForm.use_as_deploy_user) {
        onServerChange?.({ ...server, deploy_user: createForm.username, default_deploy_base: `/home/${createForm.username}` })
      }
      await loadUsers()
```

4. Each user row: show a `deploy user` badge when `server.deploy_user === user.name`, and a "Set as deploy user" outline button (next to "Use for connection") when it is not. The handler:

```ts
  const handleSetDeployUser = async (user: ServerUser) => {
    try {
      const response = await serverUsersApi.setDeployUser(server.id, { username: user.name })
      toast.success(`'${user.name}' is now the deploy user`)
      onServerChange?.(response.data)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error, 'Failed to set deploy user'))
    }
  }
```

Row markup (replacing the current single-button cell contents):

```tsx
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2">
                      {server.deploy_user !== user.name && user.name !== 'root' && (
                        <Button variant="outline" size="sm" onClick={() => handleSetDeployUser(user)}>
                          Set as deploy user
                        </Button>
                      )}
                      {!user.is_connection_user && (
                        <Button variant="outline" size="sm" onClick={() => openSwitchDialog(user)}>
                          Use for connection
                        </Button>
                      )}
                    </div>
                  </TableCell>
```

and in the name cell, alongside the existing connection user badge:

```tsx
                      {server.deploy_user === user.name && (
                        <Badge variant="outline">deploy user</Badge>
                      )}
```

- [ ] **Step 5: Verify**

Run: `cd frontend && npm run lint && npm run build`
Expected: both succeed with no new errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src && git commit -m "Surface deploy user provisioning and home layout defaults in the UI"
```

---

### Task 9: Docs and full verification

**Files:**
- Modify: `README.md` (server setup section)
- Modify: `CLAUDE.md` (Key Patterns)

- [ ] **Step 1: README**

Find the server setup or requirements section (`grep -n "sudo\|server" README.md | head`). Add a short subsection describing the recommended flow. Follow the repo documentation rule: no dashes as punctuation. Suggested text (adapt heading level to the surrounding document):

```markdown
### Recommended server layout

After connecting a server, provision a deploy user from Server Settings, Users, "Create deploy user" (the default name is shipyard). ShipYard creates the user with a home directory restricted to mode 711, installs its own SSH key, and can switch the connection to it. New applications then default to /home/shipyard/{app}, and PHP applications run through a ShipYard managed PHP-FPM pool owned by that user, so deployed code and the PHP processes share one owner.

Servers connected before this feature keep their existing /var/www layout and behavior. Nothing changes until you provision a deploy user.
```

- [ ] **Step 2: CLAUDE.md**

Add one paragraph to Key Patterns (after the SSH Operations entry):

```markdown
### Deploy User Layout
`servers.deploy_user` (nullable) selects the filesystem layout. Null means the legacy `/var/www/shipyard/{app}` defaults and the distro PHP-FPM socket. When set (provisioned via `ServerUserService`, home mode 711), new apps default to `/home/{deploy_user}/{app}`, `PhpFpmPoolService` installs a pool running as that user, and `NginxService` points vhosts at the ShipYard pool socket. Never hardcode either base path; use `Server::default_deploy_base` / `Application::generateDeployPath()`.
```

- [ ] **Step 3: Run the full backend suite**

Run: `cd backend && DB_HOST=127.0.0.1 DB_PORT=33061 DB_USERNAME=root DB_PASSWORD=testing php artisan test`
Expected: PASS, no failures anywhere (watch for tests that assert on `/var/www` defaults; they must still pass because factories set no `deploy_user`).

- [ ] **Step 4: Commit**

```bash
git add README.md CLAUDE.md && git commit -m "Document the deploy user home directory layout"
```

---

## Verification checklist (after all tasks)

1. Full backend suite green (Task 9 Step 3).
2. `cd frontend && npm run build` green.
3. Spec section coverage: deploy_user column (Task 1), provisioning + 711 (Tasks 3, 4), default path (Task 2), FPM pool (Task 5), nginx socket + hook (Task 6), import (Task 7), frontend (Task 8), docs (Task 9). Migration tooling and scoped sudoers intentionally absent (spec non-goals).
4. Manual end-to-end on a fresh EC2 instance (user's workflow): connect as ubuntu, provision shipyard deploy user with "use as deploy user", switch connection to it, create a Laravel app, deploy, confirm the site serves and `storage/` is writable by the app, confirm `/home/shipyard` is mode 711 and the pool socket exists.
