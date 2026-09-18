<?php

namespace Tests\Unit;

use App\Services\ServerMetricsService;
use App\Services\SSHService;
use PHPUnit\Framework\TestCase;

class ServerMetricsServiceTest extends TestCase
{
    private function service(): ServerMetricsService
    {
        return new ServerMetricsService($this->createMock(SSHService::class));
    }

    public function test_cpu_usage_is_the_delta_between_two_proc_stat_samples(): void
    {
        // Between the samples: 100 ticks total, 40 idle, 10 iowait -> 50% busy.
        $output = implode("\n", [
            'cpu  1000 0 500 8000 200 0 50 0 0 0',
            'cpu  1040 0 510 8040 210 0 50 0 0 0',
            '4',
        ]);

        $parsed = $this->service()->parseCpuSamples($output);

        $this->assertSame(50.0, $parsed['usage']);
        $this->assertSame(4, $parsed['cores']);
    }

    public function test_cpu_usage_is_zero_when_counters_did_not_move(): void
    {
        $output = "cpu  100 0 50 800 20 0 5 0 0 0\ncpu  100 0 50 800 20 0 5 0 0 0\n2\n";

        $parsed = $this->service()->parseCpuSamples($output);

        $this->assertSame(0.0, $parsed['usage']);
        $this->assertSame(2, $parsed['cores']);
    }

    public function test_cpu_parse_returns_null_without_two_samples(): void
    {
        $this->assertNull($this->service()->parseCpuSamples("cpu  100 0 50 800 20 0 5 0 0 0\n2\n"));
        $this->assertNull($this->service()->parseCpuSamples(''));
    }

    public function test_cpu_cores_default_to_one_when_nproc_is_missing(): void
    {
        $output = "cpu  100 0 50 800 20 0 5 0 0 0\ncpu  200 0 50 800 20 0 5 0 0 0";

        $parsed = $this->service()->parseCpuSamples($output);

        $this->assertSame(100.0, $parsed['usage']);
        $this->assertSame(1, $parsed['cores']);
    }

    public function test_swap_is_parsed_from_free_output(): void
    {
        $parsed = $this->service()->parseSwap('2147483648 536870912');

        $this->assertSame(2147483648, $parsed['total']);
        $this->assertSame(536870912, $parsed['used']);
        $this->assertSame(25.0, $parsed['percentage']);
    }

    public function test_servers_without_swap_report_zeros(): void
    {
        $this->assertSame(['total' => 0, 'used' => 0, 'percentage' => 0], $this->service()->parseSwap('0 0'));
        $this->assertSame(['total' => 0, 'used' => 0, 'percentage' => 0], $this->service()->parseSwap(''));
    }
}
