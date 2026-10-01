<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\SSHService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SSHServiceLocalSecurityTest extends TestCase
{
    public static function operations(): array
    {
        $cases = [];
        foreach (['connect', 'connectSftp'] as $connection) {
            foreach (['execute', 'upload', 'uploadContent', 'download', 'fileExists'] as $operation) {
                $cases[] = [$connection, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('operations')]
    public function test_rejected_local_connection_cannot_execute_or_access_files(string $connection, string $operation): void
    {
        $service = new SSHService;
        $server = new Server(['is_local' => true]);
        try {
            $service->$connection($server);
            $this->fail('Local connections must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Local server execution is disabled', $e->getMessage());
        }

        $args = match ($operation) {
            'execute' => ['echo local-execution-must-not-run'],
            'upload' => [__FILE__, sys_get_temp_dir().'/shipyard-must-not-upload'],
            'uploadContent' => ['blocked', sys_get_temp_dir().'/shipyard-must-not-write'],
            default => [__FILE__],
        };
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not connected to any server');
        $service->$operation(...$args);
    }
}
