<?php

namespace App\Support\Ssh;

use App\Models\GitProvider;
use RuntimeException;

final class GitHostKey
{
    // Ignore user/system SSH configuration and alternative trust sources.
    public const OPTIONS = '-F /dev/null -o StrictHostKeyChecking=yes -o GlobalKnownHostsFile=/dev/null -o VerifyHostKeyDNS=no -o UpdateHostKeys=no -o BatchMode=yes -o IdentitiesOnly=yes';

    public static function knownHosts(GitProvider $provider): string
    {
        $host = $provider->getEffectiveHost();
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.\-]*\z/', $host)) {
            throw new RuntimeException('The Git SSH host must be a hostname or IPv4 address without a scheme, port, or path.');
        }

        return $host.' '.HostKey::normalize($provider->ssh_host_key)."\n";
    }

    public static function setupScript(GitProvider $provider): string
    {
        $content = escapeshellarg(self::knownHosts($provider));

        return <<<BASH
# Trust is persisted on the provider; each operation receives only that saved key.
_SSH_KNOWN_HOSTS=\$(mktemp) || exit 1
chmod 600 "\$_SSH_KNOWN_HOSTS" || exit 1
printf '%s' {$content} > "\$_SSH_KNOWN_HOSTS" || exit 1
trap 'rm -f "\$_SSH_KNOWN_HOSTS"' EXIT
BASH;
    }
}
