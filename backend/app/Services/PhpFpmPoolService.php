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

    private const PHP_MISSING_MARKER = 'SHIPYARD_PHP_MISSING';

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
     *
     * Owns its SSH session: connects and disconnects itself, so it must not
     * be called in the middle of another service's open SSH session
     * (SSHService is shared per request).
     */
    public function ensurePool(Server $server, string $phpVersion): void
    {
        if ($server->deploy_user === null) {
            throw new InvalidArgumentException('This server has no deploy user; the ShipYard FPM pool only applies to the home directory layout.');
        }

        if (! preg_match(ServerUserService::USER_PATTERN, $server->deploy_user)) {
            throw new InvalidArgumentException("Invalid deploy user '{$server->deploy_user}'.");
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
            // /etc/php/{version}/fpm/pool.d is world-readable on Debian/
            // Ubuntu, so this check does not need sudo; running it plain
            // avoids misattributing a broken passwordless-sudo grant to a
            // missing PHP-FPM install.
            "if ! test -d /etc/php/{$phpVersion}/fpm/pool.d; then",
            '    echo '.self::PHP_MISSING_MARKER,
            '    exit 4',
            'fi',
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
            'if $SUDO test -f "$POOL" && $SUDO cmp -s "$TMP" "$POOL" && $SUDO test -S '.escapeshellarg(self::socketPath($phpVersion)).'; then',
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

        if (in_array(self::PHP_MISSING_MARKER, $lines, true)) {
            throw new RuntimeException(
                "PHP {$phpVersion} FPM is not installed on this server; install php{$phpVersion}-fpm or change the application's PHP version. Output: ".$result['output']
            );
        }

        if (in_array(self::POOL_INVALID_MARKER, $lines, true)) {
            throw new RuntimeException(
                "The generated PHP-FPM pool failed validation (php-fpm{$phpVersion} -t) and was rolled back. "
                .'Note php-fpm -t tests the entire FPM configuration, so a pre-existing broken pool from another source can also cause this. '
                .'Output: '.$result['output']
            );
        }

        if (! $result['success']) {
            throw new RuntimeException("Failed to install the ShipYard PHP-FPM pool for PHP {$phpVersion}: ".$result['output']);
        }
    }
}
