<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stateful stand-in for the Cloudflare v4 API: zones by name and an
 * in-memory record table, so tests can assert what ShipYard left in DNS.
 */
final class FakeCloudflare
{
    /** @var array<string, array{id: string, type: string, name: string, content: string, proxied: bool, zone_id: string}> */
    public array $records = [];

    /** @var array<int, string> requests as "METHOD /path" */
    public array $calls = [];

    private int $nextId = 1;

    /**
     * @param  array<string, string>  $zones  zone name => zone id
     */
    public function __construct(private array $zones = ['example.com' => 'zone-1'])
    {
        Http::fake(fn (Request $request) => $this->handle($request));
    }

    public function addRecord(string $type, string $name, string $content, bool $proxied = false): string
    {
        $id = 'rec-'.$this->nextId++;
        $this->records[$id] = [
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'proxied' => $proxied,
            'zone_id' => $this->zoneFor($name) ?? 'zone-1',
        ];

        return $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recordsFor(string $name): array
    {
        return array_values(array_filter($this->records, fn ($r) => $r['name'] === $name));
    }

    private function handle(Request $request)
    {
        $url = parse_url($request->url());
        $path = substr($url['path'], strlen('/client/v4'));
        parse_str($url['query'] ?? '', $query);
        $method = $request->method();
        $this->calls[] = "{$method} {$path}";

        if ($path === '/zones' && $method === 'GET') {
            $zones = [];
            foreach ($this->zones as $name => $id) {
                if (! isset($query['name']) || $query['name'] === $name) {
                    $zones[] = ['id' => $id, 'name' => $name];
                }
            }

            return $this->ok($zones);
        }

        if (preg_match('#^/zones/([^/]+)/dns_records$#', $path, $m)) {
            if ($method === 'GET') {
                return $this->ok(array_values(array_filter(
                    $this->records,
                    fn ($r) => $r['zone_id'] === $m[1] && (! isset($query['name']) || $r['name'] === $query['name'])
                )));
            }

            $data = $request->data();
            $id = $this->addRecord($data['type'], $data['name'], $data['content'], (bool) $data['proxied']);

            return $this->ok($this->records[$id]);
        }

        if (preg_match('#^/zones/([^/]+)/dns_records/([^/]+)$#', $path, $m)) {
            if (! isset($this->records[$m[2]])) {
                return Http::response(['success' => false, 'errors' => [['message' => 'Record not found']]], 404);
            }

            if ($method === 'DELETE') {
                unset($this->records[$m[2]]);

                return $this->ok(['id' => $m[2]]);
            }

            $this->records[$m[2]] = [...$this->records[$m[2]], ...$request->data()];

            return $this->ok($this->records[$m[2]]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => "Unexpected {$method} {$path}"]]], 400);
    }

    private function zoneFor(string $name): ?string
    {
        foreach ($this->zones as $zone => $id) {
            if ($name === $zone || str_ends_with($name, ".{$zone}")) {
                return $id;
            }
        }

        return null;
    }

    private function ok(array $result)
    {
        return Http::response([
            'success' => true,
            'errors' => [],
            'result' => $result,
            'result_info' => ['total_count' => array_is_list($result) ? count($result) : 1],
        ]);
    }
}
