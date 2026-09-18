<?php

namespace Tests\Feature;

use App\Jobs\CollectServerMetrics;
use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CollectServerMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOrgUser();
    }

    public function test_queues_one_job_per_active_server(): void
    {
        Queue::fake();

        $active = Server::factory()->create();
        $alsoActive = Server::factory()->create();
        Server::factory()->inactive()->create();
        $trashed = Server::factory()->create();
        $trashed->delete();

        $this->artisan('servers:collect-metrics')->assertSuccessful();

        Queue::assertPushed(CollectServerMetrics::class, 2);
        Queue::assertPushed(CollectServerMetrics::class, fn ($job) => $job->server->is($active));
        Queue::assertPushed(CollectServerMetrics::class, fn ($job) => $job->server->is($alsoActive));
    }

    public function test_prunes_samples_past_retention_and_keeps_recent_ones(): void
    {
        Queue::fake();

        $server = Server::factory()->create();
        $old = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'collected_at' => now()->subDays(ServerMetric::RETENTION_DAYS + 1),
        ]);
        $recent = ServerMetric::factory()->create([
            'server_id' => $server->id,
            'collected_at' => now()->subDays(ServerMetric::RETENTION_DAYS - 1),
        ]);

        $this->artisan('servers:collect-metrics')->assertSuccessful();

        $this->assertDatabaseMissing('server_metrics', ['id' => $old->id]);
        $this->assertDatabaseHas('server_metrics', ['id' => $recent->id]);
    }
}
