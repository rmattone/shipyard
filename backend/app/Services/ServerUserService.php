<?php

namespace App\Services;

use App\Exceptions\ConnectionVerificationException;
use App\Jobs\ProcessServerSshKeyInstall;
use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
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

    private const USER_PATTERN = '/^[a-z_][a-z0-9_-]*$/';

    private const USER_EXISTS_MARKER = 'SHIPYARD_USER_EXISTS';

    private const SUDOERS_INVALID_MARKER = 'SHIPYARD_SUDOERS_INVALID';

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

    public function createDeployUser(Server $server, string $username, bool $sudo): void
    {
        $validated = $this->assertValidUsername($username);

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

        $sshKey = $server->sshKeys()->create([
            'name' => 'ShipYard',
            'username' => $validated,
            'public_key' => $normalized['key'],
            'fingerprint' => $normalized['fingerprint'],
            'status' => 'installing',
        ]);

        ProcessServerSshKeyInstall::dispatch($sshKey);
    }

    public function switchConnectionUser(Server $server, string $username, bool $fixOwnership): Server
    {
        $validated = $this->assertValidUsername($username);

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

        // 5. The SSHService singleton may still hold a live session for this
        // server id opened as the OLD user; drop it so the next operation
        // against this server reconnects fresh as the new user.
        $this->sshService->disconnect();

        return $server->fresh();
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
            // accounts in between (1-999) are deliberately excluded.
            '$SUDO getent passwd | awk -F: \'($3==0 || $3>=1000){print "USER:"$1":"$3":"$6":"$7}\'',
            '',
            // Sudo membership check runs in the SAME script as the passwd
            // listing above so the two are always consistent with one
            // another. A candidate has sudo if they belong to the sudo/wheel
            // group OR have a ShipYard-managed sudoers.d drop-in.
            '$SUDO getent passwd | awk -F: \'($3==0 || $3>=1000){print $1}\' | while IFS= read -r uname; do',
            '    if $SUDO id -nG "$uname" 2>/dev/null | tr \' \' \'\n\' | grep -qxE \'sudo|wheel\'; then',
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
            "\$SUDO useradd -m -s /bin/bash {$quotedUser}",
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
     * @param  \Illuminate\Support\Collection<int, \App\Models\Application>|iterable  $applications
     */
    private function chownApplications(Server $server, iterable $applications, string $username): void
    {
        $quotedUser = escapeshellarg($username);

        $lines = [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
        ];

        foreach ($applications as $application) {
            $path = $application->deploy_path;

            // Defensive assertion: deploy_path is always set by the
            // Application model, but a recursive chown of a relative or
            // empty path resolved against the shell's cwd would be
            // catastrophic, so refuse anything that isn't absolute.
            if (! is_string($path) || $path === '' || $path[0] !== '/') {
                throw new RuntimeException("Refusing to chown a non-absolute deploy path: '{$path}'.");
            }

            $quotedPath = escapeshellarg($path);

            $lines[] = "\$SUDO chown -R {$quotedUser}:{$quotedUser} {$quotedPath}";
            // Restores web-writable directories back to www-data afterwards.
            // Covers both atomic (shared/) and in-place (storage/,
            // bootstrap/cache) layouts; `|| true` because not every app has
            // every one of these directories.
            $lines[] = "\$SUDO chown -R www-data:www-data {$quotedPath}/storage {$quotedPath}/bootstrap/cache {$quotedPath}/shared 2>/dev/null || true";
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
