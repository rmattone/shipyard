<?php

namespace Tests\Feature;

use App\Jobs\CollectServerMetrics;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerMetricsService;
use App\Services\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectServerMetricsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createOrgUser();
    }

    private function mockSsh(): void
    {
        $this->mock(SSHService::class, function ($mock) {
            $mock->shouldReceive('connect')->andReturnSelf();
            $mock->shouldReceive('disconnect');
            $mock->shouldReceive('execute')->andReturnUsing(function (string $command) {
                $ok = fn (string $output) => ['output' => $output, 'exit_code' => 0, 'success' => true];

                if (str_contains($command, 'NR==2')) {
                    // total used free available
                    return $ok('4294967296 2147483648 1073741824 2147483648');
                }
                if (str_contains($command, 'NR==3')) {
                    return $ok('1073741824 268435456');
                }
                if (str_contains($command, '/proc/stat')) {
                    return $ok("cpu  1000 0 500 8000 200 0 50 0 0 0\ncpu  1040 0 510 8040 210 0 50 0 0 0\n4\n");
                }
                if (str_contains($command, 'df -B1')) {
                    return $ok('107374182400 53687091200 53687091200');
                }
                if (str_contains($command, '/proc/uptime')) {
                    return $ok('86400.00');
                }
                if (str_contains($command, '/proc/loadavg')) {
                    return $ok('1.50 1.25 1.00');
                }

                return $ok('');
            });
        });
    }

    public function test_job_stores_one_sample_for_the_server(): void
    {
        $this->mockSsh();
        $server = Server::factory()->create();

        (new CollectServerMetrics($server))->handle(app(ServerMetricsService::class));

        $this->assertDatabaseCount('server_metrics', 1);
        $this->assertDatabaseHas('server_metrics', [
            'server_id' => $server->id,
            'cpu_percent' => 50.0,
            'cpu_cores' => 4,
            'memory_total' => 4294967296,
            'memory_used' => 2147483648,
            'memory_percent' => 50.0,
            'swap_total' => 1073741824,
            'swap_used' => 268435456,
            'disk_total' => 107374182400,
            'disk_used' => 53687091200,
            'disk_percent' => 50.0,
            'load_1' => 1.5,
            'load_5' => 1.25,
            'load_15' => 1.0,
        ]);
    }

    public function test_live_metrics_endpoint_exposes_swap_and_cores(): void
    {
        $this->mockSsh();
        $server = Server::factory()->create();

        $response = $this->actingAs($this->user)->getJson("/api/servers/{$server->id}/metrics");

        $response->assertOk()
            ->assertJsonPath('cpu.usage', 50)
            ->assertJsonPath('cpu.cores', 4)
            ->assertJsonPath('swap.total', 1073741824)
            ->assertJsonPath('swap.percentage', 25);
    }
}
