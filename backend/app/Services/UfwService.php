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
 * The one safety-critical operation here is enable(): it always allows the
 * server's own SSH port FIRST, in the same script, before force-enabling
 * ufw, so ShipYard can never firewall itself out of a box it manages.
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
            'command -v ufw >/dev/null 2>&1 || { echo '.self::MISSING_MARKER.'; exit 0; }',
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

    public function deleteRule(Server $server, string $port, string $protocol, ?string $source): void
    {
        $this->mutateRule($server, true, $port, $protocol, $source);
    }

    /**
     * THE safeguard: allows the server's own SSH port before force-enabling
     * ufw, both in the same script, so a freshly-enabled firewall can never
     * cut off the very connection managing it.
     */
    public function enable(Server $server): void
    {
        $port = (int) $server->port;

        $script = implode("\n", [
            ...$this->prologue(),
            '',
            "\$SUDO ufw allow {$port}/tcp",
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
            'export DEBIAN_FRONTEND=noninteractive',
            '$SUDO apt-get update -qq',
            '$SUDO apt-get install -y ufw',
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

        $commands = array_map(
            fn (string $proto) => $this->buildRuleCommand($delete, $port, $proto, $source),
            $protocols,
        );

        $script = implode("\n", [
            ...$this->prologue(),
            '',
            ...$commands,
        ]);

        $result = $this->runRemoteScript($server, $script, 30);

        if (! $result['success']) {
            $action = $delete ? 'delete' : 'add';

            throw new RuntimeException("Failed to {$action} firewall rule: ".$result['output']);
        }
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
