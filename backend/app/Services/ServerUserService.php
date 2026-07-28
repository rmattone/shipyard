<?php

namespace App\Services;

use App\Exceptions\ConnectionVerificationException;
use App\Jobs\ProcessServerSshKeyInstall;
use App\Models\Application;
use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
use Throwable;

/**
 * Manages unix login users on a managed server: listing who can log in,
 * creating a new deploy user (with an optional passwordless-sudo grant), and
 * switching the unix user ShipYard itself connects as. This is what makes
 * disabling root SSH login (SshdConfigService) actually reachable without
 * locking the admin out.
 *
 * Every dangerous step here mirrors the safety pattern used elsewhere in
 * this codebase (AuthorizedKeysService, SshdConfigService): a sudoers file
 * is validated with `visudo -cf` BEFORE it is ever installed, and a switch
 * of the connection user is only persisted after the NEW user has been
 * proven reachable over a real SSH connection.
 */
class ServerUserService
{
    use RunsRemoteScripts;

    public const USER_PATTERN = '/^[a-z_][a-z0-9_-]*$/D';

    private const USER_EXISTS_MARKER = 'SHIPYARD_USER_EXISTS';

    private const SUDOERS_INVALID_MARKER = 'SHIPYARD_SUDOERS_INVALID';

    private const HOME_MISSING_MARKER = 'SHIPYARD_HOME_MISSING';

    private const HOME_NOT_OWNED_MARKER = 'SHIPYARD_HOME_NOT_OWNED';

    public function __construct(
        protected SSHService $sshService,
        protected AuthorizedKeysService $authorizedKeysService,
    ) {}

    /**
     * @return array<int, array{name: string, uid: int, home: string, shell: string, has_sudo: bool, is_connection_user: bool}>
     */
    public function listUsers(Server $server): array
    {
        $script = implode("\n", $this->buildListUsersScript());
        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            throw new RuntimeException('Failed to list server users: '.$result['output']);
        }

        return $this->parseUsersOutput($result['output'], $server->username);
    }

    public function createDeployUser(Server $server, string $username, bool $sudo, bool $useAsDeployUser = false): void
    {
        $validated = $this->assertValidUsername($username);

        if ($useAsDeployUser) {
            $this->assertNoApplicationsOutsideHome($server, $validated);
        }

        $script = $this->buildCreateDeployUserScript($validated, $sudo);
        $result = $this->runRemoteScript($server, $script, 60);

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        if (in_array(self::USER_EXISTS_MARKER, $lines, true)) {
            throw new InvalidArgumentException("User '{$validated}' already exists on this server.");
        }

        if (in_array(self::SUDOERS_INVALID_MARKER, $lines, true)) {
            throw new RuntimeException(
                'The generated sudoers file failed validation (visudo -cf) and was NOT installed. '
                .'The user account was created, but no sudo access was granted. Output: '.$result['output']
            );
        }

        if (! $result['success']) {
            throw new RuntimeException("Failed to create user '{$validated}': ".$result['output']);
        }

        // The OS user now exists; land the panel's own SSH key into it so it
        // is immediately usable as a ShipYard connection user. Reuses the
        // exact same install path (and tracked ServerSshKey row) as manually
        // adding a key via AuthorizedKeysService/ProcessServerSshKeyInstall.
        try {
            $publicKey = PublicKeyLoader::load($server->private_key)->getPublicKey()->toString('OpenSSH');
            $normalized = $this->authorizedKeysService->validateAndNormalize($publicKey);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "User '{$validated}' was created, but preparing ShipYard's own SSH key for installation failed: ".$e->getMessage()
            );
        }

        // updateOrCreate: a retried request (e.g. after the OS user was
        // created but the response was lost) must absorb a stale row instead
        // of tripping the (server_id, username, fingerprint) unique index and
        // surfacing a raw 500 after the account already exists.
        $sshKey = $server->sshKeys()->updateOrCreate(
            [
                'server_id' => $server->id,
                'username' => $validated,
                'fingerprint' => $normalized['fingerprint'],
            ],
            [
                'name' => 'ShipYard',
                'public_key' => $normalized['key'],
                'status' => 'installing',
            ]
        );

        ProcessServerSshKeyInstall::dispatch($sshKey);

        if ($useAsDeployUser) {
            $server->update(['deploy_user' => $validated]);
        }
    }

    public function switchConnectionUser(Server $server, string $username, bool $fixOwnership): Server
    {
        $validated = $this->assertValidUsername($username);

        // Local execution runs shell commands directly as the process' own
        // user (see SSHService::executeLocal); testConnection() never
        // actually logs in as $username, so "verifying" a switch here would
        // be vacuous and the persisted username would be a lie.
        if ($server->is_local) {
            throw new InvalidArgumentException('Connection-user switching is not supported for local servers.');
        }

        // 1. The user must actually exist on the box before anything else.
        $exists = false;

        foreach ($this->listUsers($server) as $remoteUser) {
            if ($remoteUser['name'] === $validated) {
                $exists = true;

                break;
            }
        }

        if (! $exists) {
            throw new InvalidArgumentException("User '{$validated}' was not found on this server.");
        }

        // 2. Prove the new user is actually reachable over SSH before this
        // service commits to anything. Nothing is persisted yet; the
        // transient model only carries credentials to SSHService, the same
        // pattern ServerController::testConnectionAdhoc uses.
        $transient = new Server([
            'name' => 'switch-user-test',
            'host' => $server->host,
            'port' => $server->port,
            'username' => $validated,
            'private_key' => $server->private_key,
            'status' => 'active',
            'is_local' => $server->is_local,
        ]);

        $connectionCheck = $this->sshService->testConnection($transient);

        if (! $connectionCheck['success']) {
            throw new ConnectionVerificationException(
                "Could not verify a connection to this server as '{$validated}': ".$connectionCheck['message']
            );
        }

        // 2b. Reachability alone is not enough: every later deploy/hardening
        // operation runs privileged commands wrapped in `sudo -n` (see
        // RemoteSudo), so a non-root connection user that cannot sudo
        // without a password would break the very next operation. Root
        // needs no sudo, so it skips this check.
        if ($validated !== 'root') {
            $this->sshService->connect($transient);
            $sudoCheck = $this->sshService->execute('sudo -n true 2>/dev/null');
            $this->sshService->disconnect();

            if (! $sudoCheck['success']) {
                throw new ConnectionVerificationException(
                    "User '{$validated}' cannot use passwordless sudo, which ShipYard requires for a non-root connection user."
                );
            }
        }

        // 3. Fix up file ownership for existing applications WHILE still
        // connected as the current (old) user, since that is the user that
        // actually holds sudo/root on the box right now. This must happen
        // before the username is persisted below.
        if ($fixOwnership) {
            $applications = $server->applications()->get();

            if ($applications->isNotEmpty()) {
                $this->chownApplications($server, $applications, $validated);
            }
        }

        // 4. Only now persist the switch.
        $server->update(['username' => $validated]);

        // 5. SSHService is not a singleton, but this request-scoped instance
        // may still hold a live session for this server id opened as the OLD
        // user; drop it so the next operation against this server reconnects
        // fresh as the new user.
        $this->sshService->disconnect();

        return $server->fresh();
    }

    /**
     * Mark an existing unix user as this server's deploy user. The user must
     * exist and live under /home, because the home layout generates
     * /home/{user}/{app} paths. Root is refused: its home is /root and apps
     * must never run as root. Also the recovery path when createDeployUser
     * partially failed (the account exists, so a retried create 422s).
     */
    public function markDeployUser(Server $server, string $username): Server
    {
        $validated = $this->assertValidUsername($username);

        if ($validated === 'root') {
            throw new InvalidArgumentException('Root cannot be used as the deploy user.');
        }

        $this->assertNoApplicationsOutsideHome($server, $validated);

        $found = null;

        foreach ($this->listUsers($server) as $remoteUser) {
            if ($remoteUser['name'] === $validated) {
                $found = $remoteUser;

                break;
            }
        }

        if ($found === null) {
            // listUsers() silently excludes nologin/false-shell accounts, so
            // an account that genuinely exists but cannot log in lands here
            // too; the message must not claim the account is simply absent.
            throw new InvalidArgumentException(
                "User '{$validated}' was not found among login-capable users on this server. The deploy user needs a login shell because it is expected to become this server's connection user."
            );
        }

        if (rtrim($found['home'], '/') !== '/home/'.$validated) {
            throw new InvalidArgumentException(
                "User '{$validated}' has home directory '{$found['home']}', but the deploy layout requires '/home/{$validated}'."
            );
        }

        // Unlike createDeployUser's freshly-useradd'd accounts, an adopted
        // account gives no guarantee its home actually exists or is owned by
        // the account itself (e.g. it could be a leftover directory owned by
        // root). Verify both before touching permissions.
        $quotedHome = escapeshellarg('/home/'.$validated);

        $script = implode("\n", [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            "if ! \$SUDO test -d {$quotedHome}; then",
            '    echo '.self::HOME_MISSING_MARKER,
            '    exit 4',
            'fi',
            '',
            "if [ \"\$(\$SUDO stat -c %U {$quotedHome})\" != ".escapeshellarg($validated).' ]; then',
            '    echo '.self::HOME_NOT_OWNED_MARKER,
            '    exit 5',
            'fi',
            '',
            // Same traversal rule as freshly provisioned users: nginx needs
            // execute on the home directory, nothing more.
            "\$SUDO chmod 711 {$quotedHome}",
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        if (in_array(self::HOME_MISSING_MARKER, $lines, true)) {
            throw new InvalidArgumentException("User '{$validated}' has no home directory at /home/{$validated} on this server.");
        }

        if (in_array(self::HOME_NOT_OWNED_MARKER, $lines, true)) {
            throw new InvalidArgumentException("/home/{$validated} exists but is not owned by '{$validated}'.");
        }

        if (! $result['success']) {
            throw new RuntimeException("Failed to restrict /home/{$validated} to 711: ".$result['output']);
        }

        $server->update(['deploy_user' => $validated]);

        return $server->fresh();
    }

    /**
     * Changing deploy_user re-points the server-wide FPM pool and nginx
     * sockets for every PHP app on the box (see PhpFpmPoolService and
     * NginxService), so it is refused while apps live outside the new
     * user's home. Migrating existing apps is deliberately unsupported.
     */
    private function assertNoApplicationsOutsideHome(Server $server, string $username): void
    {
        // Deliberately NOT a LIKE query: '_' in a username is a single-char
        // SQL wildcard, so 'not like /home/dep_loy/%' would also match (and
        // thus wrongly PASS) an app living at /home/depXloy/... for ANY
        // character X. Exact prefix comparison in PHP has no such gap.
        $misplaced = $server->applications()
            ->pluck('deploy_path')
            ->contains(fn (string $path) => ! str_starts_with($path, '/home/'.$username.'/'));

        if ($misplaced) {
            throw new InvalidArgumentException(
                "This server has applications deployed outside /home/{$username}. Their PHP-FPM pool and file ownership would no longer match. Remove or migrate those applications first."
            );
        }
    }

    /**
     * @return string[]
     */
    private function buildListUsersScript(): array
    {
        return [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            'export LC_ALL=C',
            '',
            // uid 0 (root) or uid >= 1000 (regular/service accounts); system
            // accounts in between (1-999) are deliberately excluded. Reading
            // passwd/group membership needs no privilege, so this runs
            // WITHOUT $SUDO: listUsers must keep working even when the
            // current connection user cannot sudo (it is what lets an admin
            // discover and switch away from such a user in the first place).
            'getent passwd | awk -F: \'($3==0 || $3>=1000){print "USER:"$1":"$3":"$6":"$7}\'',
            '',
            // Sudo membership check runs in the SAME script as the passwd
            // listing above so the two are always consistent with one
            // another. A candidate has sudo if they belong to the sudo/wheel
            // group OR have a ShipYard-managed sudoers.d drop-in. Only the
            // sudoers.d probe itself needs $SUDO, and it is guarded by an
            // if/elif so `set -e` does not abort when sudo is unavailable;
            // it just yields has_sudo=false for that user.
            'getent passwd | awk -F: \'($3==0 || $3>=1000){print $1}\' | while IFS= read -r uname; do',
            '    if id -nG "$uname" 2>/dev/null | tr \' \' \'\n\' | grep -qxE \'sudo|wheel\'; then',
            '        echo "SUDO:$uname:yes"',
            '    elif $SUDO test -f "/etc/sudoers.d/shipyard-$uname"; then',
            '        echo "SUDO:$uname:yes"',
            '    else',
            '        echo "SUDO:$uname:no"',
            '    fi',
            'done',
        ];
    }

    private function buildCreateDeployUserScript(string $username, bool $sudo): string
    {
        $quotedUser = escapeshellarg($username);

        $lines = [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            "if \$SUDO id -u {$quotedUser} >/dev/null 2>&1; then",
            '    echo '.self::USER_EXISTS_MARKER,
            '    exit 2',
            'fi',
            '',
            '$SUDO useradd -m -d '.escapeshellarg('/home/'.$username)." -s /bin/bash {$quotedUser}",
            '',
            // 755 would leak the app list on shared servers; 700 (the RHEL
            // default) produces confusing nginx 403s. 711 is the only mode
            // that gives www-data traversal into webroots and nothing else.
            '$SUDO chmod 711 '.escapeshellarg('/home/'.$username),
        ];

        if ($sudo) {
            $delimiter = 'SHIPYARD_EOF_'.Str::random(32);
            $sudoersPath = escapeshellarg('/etc/sudoers.d/shipyard-'.$username);

            $lines = array_merge($lines, [
                '',
                'TMP=$(mktemp)',
                // Guarantees the scratch sudoers draft is gone however the
                // script exits, including a set -e abort partway through.
                'trap \'rm -f "$TMP"\' EXIT',
                '',
                "cat <<'{$delimiter}' > \"\$TMP\"",
                "{$username} ALL=(ALL) NOPASSWD:ALL",
                $delimiter,
                '',
                // Never install an unvalidated sudoers file: a broken one
                // bricks sudo for every user on the box, not just this one.
                'if ! $SUDO visudo -cf "$TMP"; then',
                '    rm -f "$TMP"',
                '    echo '.self::SUDOERS_INVALID_MARKER,
                '    exit 3',
                'fi',
                '',
                "\$SUDO install -m 0440 \"\$TMP\" {$sudoersPath}",
            ]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Application>|iterable  $applications
     */
    private function chownApplications(Server $server, iterable $applications, string $username): void
    {
        $quotedUser = escapeshellarg($username);

        // PHP-FPM runs as the deploy user on home-layout servers (see
        // PhpFpmPoolService), www-data otherwise. Writable paths must be
        // restored to whichever user actually executes the code.
        $restoreOwner = escapeshellarg(($server->deploy_user ?? 'www-data').':www-data');

        $lines = [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
        ];

        foreach ($applications as $application) {
            $path = $application->deploy_path;

            // Defensive assertion: deploy_path is always set by the
            // Application model, but a recursive chown of a relative, empty,
            // or too-shallow path (e.g. '/' or '/var') would be catastrophic.
            // Mirrors ApplicationController::isSafeDeployPath: at least three
            // path segments (e.g. /var/www/app) and no '..' traversal, since
            // '/var/www/..' passes the depth regex yet resolves to '/var'.
            if (! is_string($path) || str_contains($path, '..') || ! preg_match('#^(/[A-Za-z0-9._-]+){3,}$#', $path)) {
                throw new RuntimeException("Refusing to chown an unsafe deploy path: '{$path}'.");
            }

            $quotedPath = escapeshellarg($path);

            // Trailing colon (no group after it) sets the group to the
            // user's login group instead of assuming a group literally named
            // after the user, which does not hold for pre-existing accounts.
            $lines[] = "\$SUDO chown -R {$quotedUser}: {$quotedPath}";
            // Restores web-writable directories to the PHP runtime user
            // afterwards (deploy user on home-layout servers, www-data
            // otherwise). Covers both atomic (shared/) and in-place
            // (storage/, bootstrap/cache) layouts; `|| true` because not
            // every app has every one of these directories.
            $lines[] = "\$SUDO chown -R {$restoreOwner} {$quotedPath}/storage {$quotedPath}/bootstrap/cache {$quotedPath}/shared 2>/dev/null || true";
        }

        $script = implode("\n", $lines);
        $result = $this->runRemoteScript($server, $script, 120);

        if (! $result['success']) {
            throw new RuntimeException('Failed to fix file ownership for applications: '.$result['output']);
        }
    }

    /**
     * @return array<int, array{name: string, uid: int, home: string, shell: string, has_sudo: bool, is_connection_user: bool}>
     */
    private function parseUsersOutput(string $output, string $connectionUsername): array
    {
        $users = [];
        $sudoFlags = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = rtrim($line, "\r");

            if (str_starts_with($line, 'USER:')) {
                $parts = explode(':', $line, 5);

                if (count($parts) !== 5) {
                    continue;
                }

                [, $name, $uid, $home, $shell] = $parts;

                if (! ctype_digit($uid)) {
                    continue;
                }

                $uid = (int) $uid;

                if ($uid !== 0 && $uid < 1000) {
                    continue;
                }

                // Excludes non-login accounts (nologin/false shells), even
                // if their uid otherwise qualifies.
                if (preg_match('/(nologin|false)$/', $shell)) {
                    continue;
                }

                $users[$name] = [
                    'name' => $name,
                    'uid' => $uid,
                    'home' => $home,
                    'shell' => $shell,
                    'has_sudo' => false,
                    'is_connection_user' => $name === $connectionUsername,
                ];
            } elseif (str_starts_with($line, 'SUDO:')) {
                $parts = explode(':', $line, 3);

                if (count($parts) !== 3) {
                    continue;
                }

                [, $name, $flag] = $parts;
                $sudoFlags[$name] = $flag === 'yes';
            }
        }

        foreach ($sudoFlags as $name => $flag) {
            if (isset($users[$name])) {
                $users[$name]['has_sudo'] = $flag;
            }
        }

        return array_values($users);
    }

    private function assertValidUsername(string $username): string
    {
        if (strlen($username) > 32 || ! preg_match(self::USER_PATTERN, $username)) {
            throw new InvalidArgumentException('Invalid username.');
        }

        return $username;
    }
}
