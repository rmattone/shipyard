<?php

namespace App\Services;

use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and writes the two sshd_config hardening toggles ShipYard exposes
 * (PasswordAuthentication / PermitRootLogin). A mistake here can lock the
 * admin out of the box entirely, so every write:
 *  - lives in its own drop-in file, never edits sshd_config in place;
 *  - is validated with `sshd -t` before it can take effect, with an
 *    automatic rollback of the drop-in on failure;
 *  - is applied with `systemctl reload` (never `restart`), so existing
 *    sessions survive even if something downstream goes wrong;
 *  - is re-read from the server afterwards and compared against what was
 *    requested, so a silent partial apply is reported as a failure rather
 *    than a success.
 *
 * `sshd -T` (without `-C`) reports only the global configuration; a `Match`
 * block that re-enables PasswordAuthentication for specific users/groups is
 * neither reflected in what this service reads back nor controlled by what
 * it writes.
 */
class SshdConfigService
{
    use RunsRemoteScripts;

    /**
     * MUST be 00-, not 99-: sshd's Include directive loads matching files
     * lexically and the FIRST value for a given directive wins. Ubuntu ships
     * a stock 50-cloud-init.conf drop-in; a 99-shipyard.conf would sort
     * after it and be silently ignored, leaving the admin with the opposite
     * of whatever they just set.
     */
    private const DROP_IN_PATH = '/etc/ssh/sshd_config.d/00-shipyard.conf';

    private const HAS_INCLUDE_MARKER = 'SHIPYARD_HAS_INCLUDE';

    private const NO_INCLUDE_MARKER = 'SHIPYARD_NO_INCLUDE';

    private const VALIDATION_FAILED_MARKER = 'SHIPYARD_VALIDATION_FAILED';

    private const PASSWORD_AUTHENTICATION_VALUES = ['yes', 'no'];

    private const PERMIT_ROOT_LOGIN_VALUES = ['yes', 'no', 'prohibit-password'];

    public function __construct(
        protected SSHService $sshService,
    ) {}

    /**
     * @return array{password_authentication: string, permit_root_login: string, supports_include: bool}
     */
    public function getSettings(Server $server): array
    {
        $script = implode("\n", [
            ...$this->prologue(),
            '',
            '$SUDO "$SSHD" -T 2>/dev/null | grep -Ei \'^(passwordauthentication|permitrootlogin) \' || true',
            '',
            // Ubuntu (and most modern distros) ship sshd_config with an
            // Include directive pointing at sshd_config.d; older/minimal
            // distros may not have one, in which case a drop-in would never
            // be read and applySettings must refuse to write it.
            '$SUDO grep -qiE \'^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d\' /etc/ssh/sshd_config && echo '.self::HAS_INCLUDE_MARKER.' || true',
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            throw new RuntimeException('Failed to read sshd settings: '.$result['output']);
        }

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));
        $values = $this->parseEffectiveValues($result['output']);

        if (! isset($values['passwordauthentication'], $values['permitrootlogin'])) {
            throw new RuntimeException('Could not determine effective sshd settings from output: '.$result['output']);
        }

        return [
            'password_authentication' => $values['passwordauthentication'],
            'permit_root_login' => $values['permitrootlogin'],
            'supports_include' => in_array(self::HAS_INCLUDE_MARKER, $lines, true),
        ];
    }

    /**
     * @return array{password_authentication: string, permit_root_login: string, supports_include: bool}
     */
    public function applySettings(Server $server, string $passwordAuth, string $permitRootLogin): array
    {
        // Defense in depth: the controller validates these against the same
        // allowlist, but this service must never trust a caller not to.
        // Only these validated enum values ever reach the heredoc content
        // below, never arbitrary user text.
        $this->assertAllowedValue($passwordAuth, self::PASSWORD_AUTHENTICATION_VALUES, 'password_authentication');
        $this->assertAllowedValue($permitRootLogin, self::PERMIT_ROOT_LOGIN_VALUES, 'permit_root_login');

        $delimiter = 'SHIPYARD_EOF_'.Str::random(32);

        $content = implode("\n", [
            '# Managed by ShipYard - do not edit',
            "PasswordAuthentication {$passwordAuth}",
            "PermitRootLogin {$permitRootLogin}",
        ]);

        $script = implode("\n", [
            ...$this->prologue(),
            '',
            'CONF='.escapeshellarg(self::DROP_IN_PATH),
            '',
            // Refuse to touch sshd_config itself, ever. If this distro has
            // no Include pointed at sshd_config.d, a drop-in here would
            // simply never be read by sshd, so bail out instead of quietly
            // doing nothing (or worse, editing sshd_config in place). The
            // exit code is captured rather than just negated: grep exits 1
            // for "no match" (genuinely no Include directive) but exits >1
            // on its own errors (e.g. sudo denied, file unreadable), which
            // must NOT be reported to the admin as "add an Include
            // directive" when the real problem is something else entirely.
            'INCLUDE_RC=0',
            '$SUDO grep -qiE \'^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d\' /etc/ssh/sshd_config || INCLUDE_RC=$?',
            'if [ "$INCLUDE_RC" -eq 1 ]; then',
            '    echo '.self::NO_INCLUDE_MARKER,
            '    exit 2',
            'elif [ "$INCLUDE_RC" -ne 0 ]; then',
            '    exit "$INCLUDE_RC"',
            'fi',
            '',
            'HAD_FILE=0',
            'BACKUP=""',
            'if $SUDO test -f "$CONF"; then',
            '    HAD_FILE=1',
            '    BACKUP=$($SUDO cat "$CONF" || true)',
            'fi',
            '',
            '$SUDO install -d -m 0755 /etc/ssh/sshd_config.d',
            '',
            "\$SUDO tee \"\$CONF\" > /dev/null <<'{$delimiter}'",
            $content,
            $delimiter,
            '',
            // Never take an unvalidated config live: if `sshd -t` rejects
            // it, restore whatever was there before (or remove the file if
            // there was nothing) and report the failure instead of reloading.
            'if ! $SUDO "$SSHD" -t; then',
            '    if [ "$HAD_FILE" -eq 1 ]; then',
            '        printf \'%s\n\' "$BACKUP" | $SUDO tee "$CONF" > /dev/null',
            '    else',
            '        $SUDO rm -f "$CONF"',
            '    fi',
            '    echo '.self::VALIDATION_FAILED_MARKER,
            '    exit 3',
            'fi',
            '',
            // reload, never restart: restart would drop the session we're
            // running this over if sshd doesn't come back up cleanly.
            // Stderr is deliberately left unsuppressed on both attempts: a
            // real failure on the primary unit name must not be hidden
            // behind the (usually benign) "unit not found" from the
            // fallback name. Neither can collide with parseEffectiveValues'
            // regex, which only matches exact "directive value" lines.
            '$SUDO systemctl reload ssh || $SUDO systemctl reload sshd',
            '',
            '$SUDO "$SSHD" -T 2>/dev/null | grep -Ei \'^(passwordauthentication|permitrootlogin) \' || true',
        ]);

        $result = $this->runRemoteScript($server, $script, 60);

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        if (in_array(self::NO_INCLUDE_MARKER, $lines, true)) {
            throw new InvalidArgumentException(
                'This server\'s sshd_config has no Include directive for /etc/ssh/sshd_config.d, so ShipYard cannot '
                .'safely manage these settings without editing sshd_config in place. Add an Include directive '
                .'manually and try again.'
            );
        }

        if (in_array(self::VALIDATION_FAILED_MARKER, $lines, true)) {
            throw new RuntimeException(
                'sshd rejected the new configuration (sshd -t failed); the previous configuration was restored. Output: '.$result['output']
            );
        }

        if (! $result['success']) {
            // By this point the new drop-in has already passed `sshd -t`,
            // so the most likely remaining cause is the reload step itself
            // failing; the drop-in is left in place either way (it is only
            // ever removed/restored by the validation-failure branch above),
            // so it will still take effect on the next sshd restart or
            // server reboot even if this reload did not succeed.
            throw new RuntimeException(
                'Failed to apply sshd settings: '.$result['output']
                .' The validated configuration remains at '.self::DROP_IN_PATH
                .' and will take effect on the next sshd restart or server reboot even if this attempt failed to reload sshd.'
            );
        }

        $values = $this->parseEffectiveValues($result['output']);

        if (! isset($values['passwordauthentication'], $values['permitrootlogin'])) {
            throw new RuntimeException('Could not verify effective sshd settings after apply: '.$result['output']);
        }

        if ($values['passwordauthentication'] !== strtolower($passwordAuth) || $values['permitrootlogin'] !== strtolower($permitRootLogin)) {
            throw new RuntimeException(sprintf(
                'sshd effective settings do not match what was requested after apply (requested password_authentication=%s permit_root_login=%s, effective password_authentication=%s permit_root_login=%s).',
                $passwordAuth,
                $permitRootLogin,
                $values['passwordauthentication'],
                $values['permitrootlogin'],
            ));
        }

        return [
            'password_authentication' => $values['passwordauthentication'],
            'permit_root_login' => $values['permitrootlogin'],
            // Reaching here means the Include check above passed.
            'supports_include' => true,
        ];
    }

    /**
     * @return string[]
     */
    private function prologue(): array
    {
        return [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            // sshd is not normally on a non-root user's PATH.
            'SSHD=$(command -v sshd || echo /usr/sbin/sshd)',
        ];
    }

    /**
     * @param  string[]  $allowed
     */
    private function assertAllowedValue(string $value, array $allowed, string $field): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid value for {$field}.");
        }
    }

    /**
     * Parses `sshd -T`-style output (lowercase "directive value" lines) for
     * just the two directives this service manages.
     *
     * @return array<string, string>
     */
    private function parseEffectiveValues(string $output): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);

            if (preg_match('/^(passwordauthentication|permitrootlogin)\s+(\S+)$/i', $line, $matches)) {
                $directive = strtolower($matches[1]);
                $value = strtolower($matches[2]);

                if ($directive === 'permitrootlogin' && $value === 'without-password') {
                    // sshd -T always echoes the legacy alias
                    // "without-password" for what sshd_config accepts (and
                    // this service writes) as "prohibit-password"; real
                    // OpenSSH never prints "prohibit-password" back, even
                    // when that's exactly what was configured. The two are
                    // synonyms, so canonicalize to the modern spelling this
                    // API accepts, or every apply of prohibit-password would
                    // fail its own effective-value verification below.
                    $value = 'prohibit-password';
                }

                $values[$directive] = $value;
            }
        }

        return $values;
    }
}
