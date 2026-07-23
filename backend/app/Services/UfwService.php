<?php

namespace App\Services;

use App\Models\Server;
use App\Services\Concerns\RunsRemoteScripts;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reads and manages the UFW (Uncomplicated Firewall) on a target server.
 * There is no local persistence: status and rules are always read live from
 * the server via `ufw status numbered`, so what the panel shows can never
 * drift from what is actually enforced.
 *
 * The one safety-critical operation here is enable(): it always allows both
 * the port ShipYard itself dials and every port sshd reports as effective
 * FIRST, in the same script, before force-enabling ufw, so ShipYard can
 * never firewall itself out of a box it manages.
 */
class UfwService
{
    use RunsRemoteScripts;

    private const MISSING_MARKER = 'SHIPYARD_UFW_MISSING';

    private const PORT_PATTERN = '/^(\d{1,5})(?::(\d{1,5}))?$/';

    private const PROTOCOLS = ['tcp', 'udp', 'both'];

    /**
     * Matches a `ufw status numbered` rule line, e.g.:
     *   [ 1] 22/tcp                     ALLOW IN    Anywhere
     *   [ 3] 22/tcp (v6)                ALLOW IN    Anywhere (v6)
     * The "to"/"action"/"from" columns are separated by runs of 2+ spaces;
     * non-greedy groups let single spaces inside a column (e.g. the "(v6)"
     * suffix, or "ALLOW IN" itself) through without ending the match early.
     */
    private const RULE_LINE_PATTERN = '/^\[\s*(\d+)\]\s+(.+?)\s{2,}(.+?)\s{2,}(.+)$/';

    public function __construct(
        protected SSHService $sshService,
    ) {}

    /**
     * @return array{installed: bool, active: bool, rules: array<int, array{number: int, to: string, action: string, from: string, v6: bool}>}
     */
    public function getStatus(Server $server): array
    {
        $script = implode("\n", [
            ...$this->prologue(),
            '',
            // `command -v ufw` alone can miss a real install: ufw normally
            // lives in /usr/sbin, which is often absent from a non-root
            // user's PATH even though sudo's secure_path would still find
            // it fine for the actual mutation commands below. Missing that
            // would report an ACTIVE firewall as not-installed.
            '{ command -v ufw >/dev/null 2>&1 || [ -x /usr/sbin/ufw ]; } || { echo '.self::MISSING_MARKER.'; exit 0; }',
            '$SUDO ufw status numbered',
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            throw new RuntimeException('Failed to read firewall status: '.$result['output']);
        }

        $lines = array_map('trim', preg_split('/\r?\n/', $result['output']));

        if (in_array(self::MISSING_MARKER, $lines, true)) {
            return ['installed' => false, 'active' => false, 'rules' => []];
        }

        $active = false;

        foreach ($lines as $line) {
            if (preg_match('/^Status:\s+(active|inactive)$/i', $line, $matches)) {
                $active = strtolower($matches[1]) === 'active';

                break;
            }
        }

        return [
            'installed' => true,
            'active' => $active,
            'rules' => $this->parseRules($lines),
        ];
    }

    public function addRule(Server $server, string $port, string $protocol, ?string $source): void
    {
        $this->mutateRule($server, false, $port, $protocol, $source);
    }

    /**
     * Deleting a rule that does not exist prints "Could not delete
     * non-existent rule" but still exits 0 (ufw/Launchpad bug #1361872,
     * never fixed since it's the documented, relied-upon behavior); this is
     * treated as success, making repeated deletes idempotent.
     */
    public function deleteRule(Server $server, string $port, string $protocol, ?string $source): void
    {
        $this->mutateRule($server, true, $port, $protocol, $source);
    }

    /**
     * THE safeguard: before force-enabling ufw, allows both the port
     * ShipYard itself dials ($server->port) and every port sshd reports as
     * effective via `sshd -T` (multiple Port directives, or a dialed port
     * that differs from what sshd actually listens on, e.g. behind a DNAT),
     * all in the same script. This cannot see through a firewall/NAT that
     * remaps to a port sshd itself never learns of; a pure network-level
     * remap beyond what sshd is configured for is out of scope for what ufw
     * itself can control.
     */
    public function enable(Server $server): void
    {
        $port = (int) $server->port;

        $script = implode("\n", [
            ...$this->prologue(),
            '',
            // sshd is not normally on a non-root user's PATH.
            'SSHD=$(command -v sshd || echo /usr/sbin/sshd)',
            '',
            "\$SUDO ufw allow {$port}/tcp",
            // `|| true` on the inner pipeline is load-bearing: without it,
            // a failure of `sshd -T` itself (not merely "no port lines")
            // would abort the whole script under set -o pipefail before
            // ufw is ever force-enabled, which is the one thing this
            // safeguard must not allow to happen silently.
            'for p in $($SUDO "$SSHD" -T 2>/dev/null | awk \'$1=="port"{print $2}\' || true); do',
            '    $SUDO ufw allow "$p"/tcp',
            'done',
            '',
            '$SUDO ufw --force enable',
        ]);

        $result = $this->runRemoteScript($server, $script, 60);

        if (! $result['success']) {
            throw new RuntimeException('Failed to enable firewall: '.$result['output']);
        }
    }

    public function disable(Server $server): void
    {
        $script = implode("\n", [
            ...$this->prologue(),
            '',
            '$SUDO ufw disable',
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            throw new RuntimeException('Failed to disable firewall: '.$result['output']);
        }
    }

    public function install(Server $server): void
    {
        $script = implode("\n", [
            ...$this->prologue(),
            '',
            // sudo's default env_reset strips a plain `export` from the
            // environment it hands to apt-get for non-root users; passing
            // it as a VAR=val prefix to the sudo'd command itself survives
            // that reset instead (see DatabaseInstallationService).
            '$SUDO DEBIAN_FRONTEND=noninteractive apt-get update -qq',
            '$SUDO DEBIAN_FRONTEND=noninteractive apt-get install -y ufw',
        ]);

        $result = $this->runRemoteScript($server, $script, 180);

        if (! $result['success']) {
            throw new RuntimeException('Failed to install ufw: '.$result['output']);
        }
    }

    private function mutateRule(Server $server, bool $delete, string $port, string $protocol, ?string $source): void
    {
        $port = $this->assertValidPort($port);
        $protocol = $this->assertValidProtocol($protocol);
        $source = $this->assertValidSource($source);

        $protocols = $protocol === 'both' ? ['tcp', 'udp'] : [$protocol];

        // Each protocol's command is preceded by an echoed phase marker so
        // that, under set -euo pipefail, a mid-script failure can still be
        // attributed to the protocol that was running: the script aborts
        // immediately after the failing command, so the LAST phase marker
        // seen in the output is the one that failed (a later protocol's
        // marker, if any, never gets the chance to print).
        $commands = [];

        foreach ($protocols as $proto) {
            $commands[] = 'echo '.$this->phaseMarker($proto);
            $commands[] = $this->buildRuleCommand($delete, $port, $proto, $source);
        }

        $script = implode("\n", [
            ...$this->prologue(),
            '',
            ...$commands,
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            $action = $delete ? 'delete' : 'add';
            $failedProtocol = $this->lastPhaseProtocol($result['output'], $protocols);

            throw new RuntimeException("Failed to {$action} firewall rule (protocol {$failedProtocol}): ".$result['output']);
        }
    }

    private function phaseMarker(string $protocol): string
    {
        return 'SHIPYARD_UFW_PHASE_'.strtoupper($protocol);
    }

    /**
     * @param  string[]  $protocols
     */
    private function lastPhaseProtocol(string $output, array $protocols): string
    {
        $lines = array_map('trim', preg_split('/\r?\n/', $output));
        $failed = null;

        foreach ($lines as $line) {
            foreach ($protocols as $proto) {
                if ($line === $this->phaseMarker($proto)) {
                    $failed = $proto;
                }
            }
        }

        // No marker made it into the output at all (e.g. the connection
        // dropped before the script could run); name every protocol that
        // was attempted rather than guessing which one.
        return $failed ?? implode('/', $protocols);
    }

    private function buildRuleCommand(bool $delete, string $port, string $protocol, ?string $source): string
    {
        $verb = $delete ? 'delete allow' : 'allow';

        if ($source === null) {
            return '$SUDO ufw '.$verb.' '.escapeshellarg($port).'/'.$protocol;
        }

        return '$SUDO ufw '.$verb.' from '.escapeshellarg($source).' to any port '.escapeshellarg($port).' proto '.$protocol;
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
            // ufw's "Status: active/inactive" and rule-action strings are
            // gettext-translated; a non-English locale on the target server
            // would otherwise silently break the parsing below.
            'export LC_ALL=C',
        ];
    }

    /**
     * @param  string[]  $lines
     * @return array<int, array{number: int, to: string, action: string, from: string, v6: bool}>
     */
    private function parseRules(array $lines): array
    {
        $rules = [];

        foreach ($lines as $line) {
            if (! preg_match(self::RULE_LINE_PATTERN, $line, $matches)) {
                continue;
            }

            [, $number, $to, $action, $from] = $matches;

            // ufw >= 0.35 appends a user-supplied rule comment to the From
            // column (e.g. "Anywhere            # allow office VPN"); strip
            // it before any v6 check below, since a commented v6 rule would
            // otherwise no longer end in the literal " (v6)" suffix.
            $from = preg_replace('/\s*#.*$/', '', $from) ?? $from;

            $v6 = false;

            if (str_ends_with($to, ' (v6)')) {
                $to = substr($to, 0, -strlen(' (v6)'));
                $v6 = true;
            }

            if (str_ends_with($from, ' (v6)')) {
                $from = substr($from, 0, -strlen(' (v6)'));
                $v6 = true;
            }

            $rules[] = [
                'number' => (int) $number,
                'to' => $to,
                'action' => $action,
                'from' => $from,
                'v6' => $v6,
            ];
        }

        return $rules;
    }

    /**
     * Defense in depth: the controller validates port/protocol/source
     * against the same rules, but this service must never trust a caller
     * not to. Returns the value unchanged (it is only ever escapeshellarg'd
     * by the caller, never interpolated raw).
     */
    private function assertValidPort(string $port): string
    {
        if (! preg_match(self::PORT_PATTERN, $port, $matches)) {
            throw new InvalidArgumentException('Invalid port.');
        }

        $low = (int) $matches[1];
        $high = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;

        if ($low < 1 || $low > 65535) {
            throw new InvalidArgumentException('Invalid port.');
        }

        if ($high !== null) {
            if ($high < 1 || $high > 65535) {
                throw new InvalidArgumentException('Invalid port.');
            }

            if ($low >= $high) {
                throw new InvalidArgumentException('Invalid port range: the low value must be less than the high value.');
            }
        }

        return $port;
    }

    private function assertValidProtocol(string $protocol): string
    {
        if (! in_array($protocol, self::PROTOCOLS, true)) {
            throw new InvalidArgumentException('Invalid protocol.');
        }

        return $protocol;
    }

    private function assertValidSource(?string $source): ?string
    {
        if ($source === null) {
            return null;
        }

        [$address, $prefix] = array_pad(explode('/', $source, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('Invalid source address: only IPv4 is supported.');
        }

        if ($prefix !== null) {
            if (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
                throw new InvalidArgumentException('Invalid source CIDR prefix.');
            }
        }

        return $source;
    }
}
