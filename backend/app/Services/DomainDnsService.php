<?php

namespace App\Services;

use App\Models\DnsProvider;
use App\Models\Domain;
use App\Models\Server;
use App\Services\Dns\CloudflareClient;
use App\Services\Dns\DnsRecordConflict;
use RuntimeException;

/**
 * Points a domain's DNS record at its server through a DNS provider.
 *
 * Proxy ordering: Let's Encrypt validates over plain HTTP, which a proxied
 * record with "Always Use HTTPS" (or Full strict SSL) breaks before the
 * origin has a certificate. So a record is created DNS only, and the proxy
 * is switched on once the domain has a certificate (pending_proxy until
 * then). Records ShipYard did not create are adopted as they are and never
 * deleted.
 */
class DomainDnsService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PENDING_PROXY = 'pending_proxy';

    public const STATUS_ERROR = 'error';

    private const ADDRESS_TYPES = ['A', 'AAAA', 'CNAME'];

    public function client(DnsProvider $provider): CloudflareClient
    {
        return new CloudflareClient($provider->api_token);
    }

    /**
     * Make $hostname resolve to $server.
     *
     * An existing record already pointing at the server is adopted untouched.
     * Any other address record is a conflict unless $overwrite, in which case
     * those records are deleted and replaced.
     *
     * @return array{zone_id: string, record_id: string, managed: bool, replaced: bool, proxied: bool}
     *
     * @throws DnsRecordConflict
     */
    public function ensureRecord(DnsProvider $provider, string $hostname, Server $server, bool $proxied, bool $overwrite = false): array
    {
        $target = $this->targetFor($server);
        $client = $this->client($provider);

        $zone = $client->findZone($hostname)
            ?? throw new RuntimeException("No Cloudflare zone for {$hostname} is accessible with this token.");

        $existing = array_values(array_filter(
            $client->listRecords($zone['id'], $hostname),
            fn (array $record) => in_array($record['type'] ?? '', self::ADDRESS_TYPES, true)
        ));

        if (count($existing) === 1
            && $existing[0]['type'] === $target['type']
            && $existing[0]['content'] === $target['content']) {
            return [
                'zone_id' => $zone['id'],
                'record_id' => $existing[0]['id'],
                'managed' => false,
                'replaced' => false,
                'proxied' => (bool) ($existing[0]['proxied'] ?? false),
            ];
        }

        if ($existing !== [] && ! $overwrite) {
            throw new DnsRecordConflict($hostname, array_map(
                fn (array $record) => ['type' => $record['type'], 'content' => $record['content']],
                $existing
            ));
        }

        foreach ($existing as $record) {
            $client->deleteRecord($zone['id'], $record['id']);
        }

        $record = $client->createRecord($zone['id'], $target['type'], $hostname, $target['content'], $proxied);

        return [
            'zone_id' => $zone['id'],
            'record_id' => $record['id'],
            'managed' => true,
            'replaced' => $existing !== [],
            'proxied' => (bool) ($record['proxied'] ?? $proxied),
        ];
    }

    /**
     * Store an ensureRecord() result on the domain. $proxied is the desired
     * state; an adopted record keeps whatever proxy state it already has.
     */
    public function link(Domain $domain, DnsProvider $provider, array $result, bool $proxied): Domain
    {
        $desired = $result['managed'] ? $proxied : $result['proxied'];

        $domain->update([
            'dns_provider_id' => $provider->id,
            'dns_zone_id' => $result['zone_id'],
            'dns_record_id' => $result['record_id'],
            'dns_record_managed' => $result['managed'],
            'dns_proxied' => $desired,
            'dns_status' => $desired && ! $result['proxied'] ? self::STATUS_PENDING_PROXY : self::STATUS_ACTIVE,
            'dns_error' => null,
        ]);

        return $domain->fresh();
    }

    /**
     * Manage DNS for a domain that already exists in ShipYard.
     *
     * @throws DnsRecordConflict
     */
    public function attach(Domain $domain, DnsProvider $provider, bool $proxied, bool $overwrite = false): Domain
    {
        $server = $domain->application->server;
        $result = $this->ensureRecord($provider, $domain->domain, $server, $proxied && $domain->ssl_enabled, $overwrite);

        return $this->link($domain, $provider, $result, $proxied);
    }

    /**
     * Change the proxy setting. Turning the proxy on before the domain has
     * a certificate only records the wish; activatePendingProxy() applies it.
     */
    public function setProxied(Domain $domain, bool $proxied): Domain
    {
        $provider = $this->providerFor($domain);

        if ($proxied && ! $domain->ssl_enabled) {
            $domain->update([
                'dns_proxied' => true,
                'dns_status' => self::STATUS_PENDING_PROXY,
                'dns_error' => null,
            ]);

            return $domain->fresh();
        }

        $this->client($provider)->updateRecord($domain->dns_zone_id, $domain->dns_record_id, ['proxied' => $proxied]);

        $domain->update([
            'dns_proxied' => $proxied,
            'dns_status' => self::STATUS_ACTIVE,
            'dns_error' => null,
        ]);

        return $domain->fresh();
    }

    /**
     * Switch the proxy on for a domain that was waiting for its certificate.
     * Never throws: the certificate is already issued, and a failure here
     * is recorded on the domain for the operator to retry.
     */
    public function activatePendingProxy(Domain $domain): Domain
    {
        if ($domain->dns_status !== self::STATUS_PENDING_PROXY || ! $domain->ssl_enabled || ! $domain->dns_record_id) {
            return $domain;
        }

        try {
            return $this->setProxied($domain, true);
        } catch (\Throwable $e) {
            $domain->update([
                'dns_status' => self::STATUS_ERROR,
                'dns_error' => $e->getMessage(),
            ]);

            return $domain->fresh();
        }
    }

    /**
     * Stop managing DNS for the domain. The record is deleted only when
     * ShipYard created it and $deleteRecord is true.
     */
    public function detach(Domain $domain, bool $deleteRecord = true): Domain
    {
        if ($deleteRecord && $domain->dns_record_managed && $domain->dns_record_id) {
            $this->client($this->providerFor($domain))->deleteRecord($domain->dns_zone_id, $domain->dns_record_id);
        }

        $domain->update([
            'dns_provider_id' => null,
            'dns_zone_id' => null,
            'dns_record_id' => null,
            'dns_record_managed' => false,
            'dns_proxied' => false,
            'dns_status' => null,
            'dns_error' => null,
        ]);

        return $domain->fresh();
    }

    /**
     * Best-effort cleanup before a domain row goes away (domain removed,
     * renamed, or application deleted). Failures are reported, not thrown:
     * the record is left behind but the operator's action still completes.
     */
    public function releaseQuietly(Domain $domain): void
    {
        if (! $domain->dns_record_id) {
            return;
        }

        try {
            $this->detach($domain);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Delete a record created by ensureRecord() whose domain could not be
     * saved, so a failed add does not leave DNS pointing at the server.
     */
    public function discard(DnsProvider $provider, array $result): void
    {
        if (! $result['managed'] || $result['replaced']) {
            return;
        }

        try {
            $this->client($provider)->deleteRecord($result['zone_id'], $result['record_id']);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The record a server should get: its public IP. A hostname is resolved
     * to its IPv4 address; private and local addresses are refused because
     * a public DNS record cannot reach them.
     *
     * @return array{type: string, content: string}
     */
    public function targetFor(Server $server): array
    {
        if ($server->isLocal()) {
            throw new RuntimeException('The local ShipYard server cannot be published through a DNS provider.');
        }

        $host = trim((string) $server->host);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new RuntimeException("The server address {$host} is private. Cloudflare needs the server's public IP.");
            }

            return [
                'type' => filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A',
                'content' => $host,
            ];
        }

        foreach ($this->resolve($host) as $ip) {
            if ($this->isPublicIp($ip)) {
                return ['type' => 'A', 'content' => $ip];
            }
        }

        throw new RuntimeException("Could not resolve the server host {$host} to a public IPv4 address.");
    }

    /**
     * @return array<int, string>
     */
    protected function resolve(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function providerFor(Domain $domain): DnsProvider
    {
        $provider = $domain->dnsProvider;

        if (! $provider || ! $domain->dns_zone_id || ! $domain->dns_record_id) {
            throw new RuntimeException('DNS is not managed by ShipYard for this domain.');
        }

        return $provider;
    }
}
