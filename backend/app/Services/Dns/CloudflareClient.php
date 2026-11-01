<?php

namespace App\Services\Dns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal client for the Cloudflare v4 API: finding the zone a hostname
 * belongs to and managing that hostname's records. Error messages carry
 * Cloudflare's own message, never the token.
 */
class CloudflareClient
{
    public const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private string $token) {}

    /**
     * Check the token by listing the zones it can see. Works for both user
     * and account owned tokens (the /user/tokens/verify endpoint rejects
     * account tokens), and also proves the token has Zone read access.
     *
     * @return array{zone_count: int, zones: array<int, string>}
     */
    public function verify(): array
    {
        $data = $this->unwrap($this->request()->get('/zones', ['per_page' => 50]), 'list zones');
        $zones = array_column($data['result'] ?? [], 'name');

        return [
            'zone_count' => (int) ($data['result_info']['total_count'] ?? count($zones)),
            'zones' => $zones,
        ];
    }

    /**
     * Find the zone a hostname belongs to by trying each parent name, most
     * specific first, so delegated subzones win over the apex zone.
     *
     * @return array{id: string, name: string}|null
     */
    public function findZone(string $hostname): ?array
    {
        $labels = explode('.', $hostname);

        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $data = $this->unwrap($this->request()->get('/zones', ['name' => $candidate]), 'look up the zone');

            foreach ($data['result'] ?? [] as $zone) {
                if (strcasecmp($zone['name'] ?? '', $candidate) === 0) {
                    return ['id' => $zone['id'], 'name' => $zone['name']];
                }
            }
        }

        return null;
    }

    /**
     * Every record for exactly this name, whatever its type.
     *
     * @return array<int, array{id: string, type: string, name: string, content: string, proxied?: bool}>
     */
    public function listRecords(string $zoneId, string $name): array
    {
        $data = $this->unwrap(
            $this->request()->get("/zones/{$zoneId}/dns_records", ['name' => $name, 'per_page' => 100]),
            'list DNS records'
        );

        return $data['result'] ?? [];
    }

    /**
     * @return array{id: string, type: string, name: string, content: string, proxied?: bool}
     */
    public function createRecord(string $zoneId, string $type, string $name, string $content, bool $proxied): array
    {
        $data = $this->unwrap($this->request()->post("/zones/{$zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => 1,
            'proxied' => $proxied,
            'comment' => 'Managed by ShipYard',
        ]), 'create the DNS record');

        return $data['result'];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{id: string, type: string, name: string, content: string, proxied?: bool}
     */
    public function updateRecord(string $zoneId, string $recordId, array $fields): array
    {
        $data = $this->unwrap(
            $this->request()->patch("/zones/{$zoneId}/dns_records/{$recordId}", $fields),
            'update the DNS record'
        );

        return $data['result'];
    }

    /**
     * Delete a record. A record that is already gone counts as deleted.
     */
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $response = $this->request()->delete("/zones/{$zoneId}/dns_records/{$recordId}");

        if ($response->status() === 404) {
            return;
        }

        $this->unwrap($response, 'delete the DNS record');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::API_BASE)
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrap(Response $response, string $action): array
    {
        $data = $response->json();

        if ($response->successful() && is_array($data) && ($data['success'] ?? false) === true) {
            return $data;
        }

        $message = is_array($data) ? ($data['errors'][0]['message'] ?? null) : null;
        $message ??= 'HTTP '.$response->status();

        throw new RuntimeException("Cloudflare could not {$action}: {$message}");
    }
}
