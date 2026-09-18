<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerMetricsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createOrgUser();
        $this->server = Server::factory()->create();
    }

    /**
     * One sample per hour going back $hours hours, with deterministic values
     * so peak/p95/avg are predictable: cpu = hour index mod 100.
     */
    private function seedHourlySamples(int $hours, int $diskStep = 0): void
    {
        $rows = [];
        $base = now()->startOfHour();

        for ($i = 0; $i < $hours; $i++) {
            $rows[] = ServerMetric::factory()->make([
                'server_id' => $this->server->id,
                'collected_at' => $base->copy()->subHours($i),
                'cpu_percent' => $i % 100,
                'memory_percent' => 50,
                'disk_percent' => 40,
                'disk_used' => 1000 + $diskStep * ($hours - 1 - $i),
                'load_1' => 1,
            ])->getAttributes();
        }

        ServerMetric::insert($rows);
    }

    public function test_history_requires_authentication(): void
    {
        $this->getJson("/api/servers/{$this->server->id}/metrics/history")->assertUnauthorized();
    }

    public function test_empty_history_has_no_series_and_null_summaries(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/servers/{$this->server->id}/metrics/history");

        $response->assertOk()
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('bucket_minutes', 30)
            ->assertJsonPath('samples', 0)
            ->assertJsonPath('cores', null)
            ->assertJsonPath('disk_total', null)
            ->assertJsonPath('series', [])
            ->assertJsonPath('summary.cpu.peak', null)
            ->assertJsonPath('summary.disk_growth_bytes_per_day', null);
    }

    public function test_seven_day_range_only_covers_the_last_week(): void
    {
        // 10 days of hourly samples; only the newest 7 days (168 + the current hour) count.
        $this->seedHourlySamples(240);

        $response = $this->actingAs($this->user)->getJson("/api/servers/{$this->server->id}/metrics/history?range=7d");

        $response->assertOk()->assertJsonPath('range', '7d');

        $samples = $response->json('samples');
        $this->assertGreaterThanOrEqual(168, $samples);
        $this->assertLessThanOrEqual(169, $samples);

        // Hourly samples in 30 minute buckets: one point per sample.
        $this->assertCount($samples, $response->json('series'));
        $this->assertSame(2, $response->json('cores'));
    }

    public function test_thirty_day_range_uses_two_hour_buckets_and_covers_everything(): void
    {
        $this->seedHourlySamples(240);

        $response = $this->actingAs($this->user)->getJson("/api/servers/{$this->server->id}/metrics/history?range=30d");

        $response->assertOk()
            ->assertJsonPath('range', '30d')
            ->assertJsonPath('bucket_minutes', 120)
            ->assertJsonPath('samples', 240);

        $series = $response->json('series');
        // 240 hourly samples over 2h buckets: 120 or 121 depending on alignment.
        $this->assertGreaterThanOrEqual(120, count($series));
        $this->assertLessThanOrEqual(121, count($series));

        $point = $series[0];
        foreach (['t', 'cpu', 'cpu_avg', 'memory', 'memory_avg', 'disk', 'load_1', 'swap_used'] as $key) {
            $this->assertArrayHasKey($key, $point);
        }
        $this->assertLessThanOrEqual(100, $point['cpu']);
        $this->assertGreaterThanOrEqual($point['cpu_avg'], $point['cpu']);
    }

    public function test_summary_reports_peak_p95_and_average(): void
    {
        // cpu values 0..99 exactly once each over the last 100 hours.
        $this->seedHourlySamples(100);

        $response = $this->actingAs($this->user)->getJson("/api/servers/{$this->server->id}/metrics/history?range=30d");

        $response->assertOk()
            ->assertJsonPath('summary.cpu.peak', 99)
            ->assertJsonPath('summary.cpu.p95', 94)
            ->assertJsonPath('summary.cpu.avg', 49.5)
            ->assertJsonPath('summary.memory.peak', 50)
            ->assertJsonPath('summary.load_1.avg', 1);
    }

    public function test_disk_growth_is_extrapolated_per_day(): void
    {
        // Disk grows 100 bytes per hour for 48 hours: 2400 bytes per day.
        $this->seedHourlySamples(49, diskStep: 100);

        $response = $this->actingAs($this->user)->getJson("/api/servers/{$this->server->id}/metrics/history?range=7d");

        $response->assertOk()
            ->assertJsonPath('summary.disk_growth_bytes_per_day', 2400)
            ->assertJsonPath('disk_used', 5800);
    }

    public function test_invalid_range_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/servers/{$this->server->id}/metrics/history?range=90d")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('range');
    }

    public function test_servers_of_other_organizations_are_not_found(): void
    {
        $foreign = Server::factory()->create(['organization_id' => Organization::factory()]);
        ServerMetric::factory()->create(['server_id' => $foreign->id]);

        $this->actingAs($this->user)
            ->getJson("/api/servers/{$foreign->id}/metrics/history")
            ->assertNotFound();
    }
}
