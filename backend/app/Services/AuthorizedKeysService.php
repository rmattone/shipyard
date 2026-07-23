<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerSshKey;
use App\Services\Concerns\RunsRemoteScripts;
use App\Support\RemoteSudo;
use Illuminate\Support\Str;
use InvalidArgumentException;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
use Throwable;

/**
 * Installs, removes, and audits individual public keys in a unix user's
 * authorized_keys file on a target server. Key content only ever reaches
 * the remote shell inside a single-quoted, randomized heredoc so a hostile
 * comment or blob can never be interpreted by the shell.
 */
class AuthorizedKeysService
{
    use RunsRemoteScripts;

    private const USER_PATTERN = '/^[a-z_][a-z0-9_-]*$/';

    private const KEY_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ssh-dss',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
        'sk-ssh-ed25519@openssh.com',
        'sk-ecdsa-sha2-nistp256@openssh.com',
    ];

    private const NO_USER_MARKER = 'SHIPYARD_NO_USER';

    private const NO_FILE_MARKER = 'SHIPYARD_NO_AUTHORIZED_KEYS';

    private const SYMLINK_MARKER = 'SHIPYARD_AK_IS_SYMLINK';

    public function __construct(
        protected SSHService $sshService,
    ) {}

    /**
     * @return array{key: string, fingerprint: string}
     */
    public function validateAndNormalize(string $publicKey): array
    {
        $trimmed = trim($publicKey);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Public key is required.');
        }

        if (preg_match('/[\r\n]/', $trimmed) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $trimmed)) {
            throw new InvalidArgumentException('Public key must be a single line without control characters.');
        }

        $types = implode('|', array_map(fn ($type) => preg_quote($type, '/'), self::KEY_TYPES));

        if (! preg_match('/^('.$types.')\s+([A-Za-z0-9+\/]+=*)(?:\s+(.*))?$/', $trimmed, $matches)) {
            throw new InvalidArgumentException('Unrecognized SSH public key format.');
        }

        $type = $matches[1];
        $blob = $matches[2];
        $comment = $matches[3] ?? '';

        $decodedBlob = base64_decode($blob, true);

        if ($decodedBlob === false) {
            throw new InvalidArgumentException('Public key blob is not valid base64.');
        }

        // The blob's own leading length-prefixed string must agree with the
        // type declared on the line; this is what actually pins the type,
        // since PublicKeyLoader can't parse FIDO (sk-*) keys at all (see
        // below) and would otherwise be the only check for those.
        if (! $this->blobDeclaresType($decodedBlob, $type)) {
            throw new InvalidArgumentException('Public key blob does not match the declared key type.');
        }

        // phpseclib 3 has no FIDO/U2F (sk-*) support, so PublicKeyLoader
        // throws on otherwise-valid sk-ssh-ed25519@openssh.com /
        // sk-ecdsa-sha2-nistp256@openssh.com keys. The blob-type check
        // above is the real validation for those; for every other type,
        // PublicKeyLoader is kept as an extra parseability check.
        if (! str_starts_with($type, 'sk-')) {
            try {
                PublicKeyLoader::load("{$type} {$blob}");
            } catch (Throwable $e) {
                throw new InvalidArgumentException('Public key could not be parsed: '.$e->getMessage());
            }
        }

        $comment = trim(preg_replace('/[^A-Za-z0-9@._+ -]/', '', $comment) ?? '');
        $normalized = $comment !== '' ? "{$type} {$blob} {$comment}" : "{$type} {$blob}";

        return [
            'key' => $normalized,
            'fingerprint' => ServerSshKey::fingerprintFor($normalized),
        ];
    }

    public function installKey(ServerSshKey $key): void
    {
        $quotedUser = escapeshellarg($this->assertValidUsername($key->username));
        $this->assertSingleLine($key->public_key);
        $delimiter = 'SHIPYARD_EOF_'.Str::random(32);

        $script = implode("\n", [
            ...$this->homePrologue($quotedUser),
            '',
            "\$SUDO install -d -m 0700 -o {$quotedUser} -g \"\$GROUP\" \"\$USER_HOME/.ssh\"",
            '',
            // Refuse to follow a symlink into some other, possibly
            // attacker-controlled, target file.
            'if [ -L "$AK" ]; then',
            '    echo '.self::SYMLINK_MARKER,
            '    exit 1',
            'fi',
            '$SUDO touch "$AK"',
            "\$SUDO chown {$quotedUser} \"\$AK\"",
            '$SUDO chgrp "$GROUP" "$AK"',
            '$SUDO chmod 0600 "$AK"',
            '',
            "KEY=\$(cat <<'{$delimiter}'",
            $key->public_key,
            $delimiter,
            ')',
            '',
            '$SUDO grep -qxF "$KEY" "$AK" || printf \'%s\\n\' "$KEY" | $SUDO tee -a "$AK" > /dev/null',
        ]);

        $result = $this->runRemoteScript($key->server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to install SSH key: '.$result['output']);
        }
    }

    public function removeKey(ServerSshKey $key): void
    {
        $quotedUser = escapeshellarg($this->assertValidUsername($key->username));
        $this->assertSingleLine($key->public_key);
        $delimiter = 'SHIPYARD_EOF_'.Str::random(32);

        $script = implode("\n", [
            ...$this->homePrologue($quotedUser),
            '',
            // A missing authorized_keys file means there is nothing to
            // remove; that is success, not an error.
            'if [ ! -f "$AK" ]; then',
            '    exit 0',
            'fi',
            '',
            "KEY=\$(cat <<'{$delimiter}'",
            $key->public_key,
            $delimiter,
            ')',
            '',
            'TMP=$(mktemp)',
            // Guarantees the scratch file is gone however the script
            // exits, including a set -e abort partway through.
            'trap \'rm -f "$TMP"\' EXIT',
            '$SUDO grep -vxF "$KEY" "$AK" > "$TMP" || true',
            "\$SUDO install -m 0600 -o {$quotedUser} -g \"\$GROUP\" \"\$TMP\" \"\$AK\"",
        ]);

        $result = $this->runRemoteScript($key->server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to remove SSH key: '.$result['output']);
        }
    }

    /**
     * @return array<int, array{type: string, comment: ?string, fingerprint: ?string, tracked: bool}>
     */
    public function listAuthorizedKeys(Server $server, string $username): array
    {
        $quotedUser = escapeshellarg($this->assertValidUsername($username));

        $inner = "USER_HOME=\$(getent passwd {$quotedUser} | cut -d: -f6); "
            .'if [ -z "$USER_HOME" ]; then echo '.self::NO_USER_MARKER.'; exit 0; fi; '
            .'AK="$USER_HOME/.ssh/authorized_keys"; '
            .'if [ ! -f "$AK" ]; then echo '.self::NO_FILE_MARKER.'; exit 0; fi; '
            .'cat "$AK"';

        $command = RemoteSudo::wrap($server, 'sh -c '.escapeshellarg($inner));

        try {
            $this->sshService->connect($server);
            $result = $this->sshService->execute($command, 30);
        } finally {
            $this->sshService->disconnect();
        }

        if (! $result['success']) {
            throw new RuntimeException('Failed to list authorized keys: '.$result['output']);
        }

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        // Exact-line match: a key comment that happens to contain one of
        // these marker strings must not be mistaken for the marker itself.
        if (in_array(self::NO_USER_MARKER, $lines, true) || in_array(self::NO_FILE_MARKER, $lines, true)) {
            return [];
        }

        return $this->parseAuthorizedKeys($server, $username, $result['output']);
    }

    private function assertValidUsername(string $username): string
    {
        if (! preg_match(self::USER_PATTERN, $username)) {
            throw new InvalidArgumentException('Invalid username.');
        }

        return $username;
    }

    /**
     * Defense in depth for installKey/removeKey: the row's public_key is
     * normalized by validateAndNormalize on the way in, but a future write
     * path (a migration, a manual DB edit, another service) could store
     * something else. A newline here would let extra authorized_keys lines
     * ride along inside the heredoc.
     */
    private function assertSingleLine(string $value): string
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new InvalidArgumentException('Public key must be a single line.');
        }

        return $value;
    }

    /**
     * True when the blob's own leading OpenSSH string field (a uint32
     * length prefix followed by that many bytes) equals the declared key
     * type verbatim.
     */
    private function blobDeclaresType(string $blob, string $type): bool
    {
        if (strlen($blob) < 4) {
            return false;
        }

        $length = unpack('N', substr($blob, 0, 4))[1];

        if ($length < 0 || strlen($blob) < 4 + $length) {
            return false;
        }

        return substr($blob, 4, $length) === $type;
    }

    /**
     * @return string[]
     */
    private function homePrologue(string $quotedUser): array
    {
        return [
            'set -euo pipefail',
            '',
            'if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi',
            '',
            // set -euo pipefail makes getent's exit 2 (no such user) abort
            // the script before the -z check ever runs, so the || true is
            // load-bearing: it lets the empty-USER_HOME branch below fire
            // instead of the script dying silently on the pipeline.
            "USER_HOME=\$(\$SUDO getent passwd {$quotedUser} | cut -d: -f6 || true)",
            'if [ -z "$USER_HOME" ]; then',
            '    echo '.self::NO_USER_MARKER,
            '    exit 1',
            'fi',
            "GROUP=\$(\$SUDO id -gn {$quotedUser})",
            'AK="$USER_HOME/.ssh/authorized_keys"',
        ];
    }

    /**
     * @return array<int, array{type: string, comment: ?string, fingerprint: ?string, tracked: bool}>
     */
    private function parseAuthorizedKeys(Server $server, string $username, string $output): array
    {
        // Scoped to this username, and to rows that represent a key the
        // panel still expects to be present: a failed row never made it
        // onto the server (or was cleaned back off), so it must not read
        // as tracked, and a key tracked for one user must not bleed into
        // another user's listing just because the fingerprint matches.
        $trackedFingerprints = ServerSshKey::query()
            ->where('server_id', $server->id)
            ->where('username', $username)
            ->whereIn('status', ['installing', 'installed', 'removing'])
            ->pluck('fingerprint')
            ->all();

        $keys = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            try {
                $normalized = $this->validateAndNormalize($this->stripLeadingOptions($line));
            } catch (InvalidArgumentException) {
                $keys[] = [
                    'type' => 'unknown',
                    'comment' => null,
                    'fingerprint' => null,
                    'tracked' => false,
                ];

                continue;
            }

            $parts = preg_split('/\s+/', $normalized['key'], 3);

            $keys[] = [
                'type' => $parts[0],
                'comment' => $parts[2] ?? null,
                'fingerprint' => $normalized['fingerprint'],
                'tracked' => in_array($normalized['fingerprint'], $trackedFingerprints, true),
            ];
        }

        return $keys;
    }

    /**
     * OpenSSH authorized_keys lines may be preceded by a comma-separated
     * options field (command="...",no-port-forwarding ssh-ed25519 ...).
     * Strips it so the key itself can be classified; a quoted option value
     * may itself contain whitespace, so only unquoted whitespace ends it.
     */
    private function stripLeadingOptions(string $line): string
    {
        $types = implode('|', array_map(fn ($type) => preg_quote($type, '/'), self::KEY_TYPES));

        if (preg_match('/^(?:'.$types.')(?:\s|$)/', $line)) {
            return $line;
        }

        $length = strlen($line);
        $inQuotes = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($char === '"' && ($i === 0 || $line[$i - 1] !== '\\')) {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && ($char === ' ' || $char === "\t")) {
                return ltrim(substr($line, $i));
            }
        }

        return $line;
    }
}
