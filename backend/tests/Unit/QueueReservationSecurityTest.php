<?php

namespace Tests\Unit;

use App\Services\BackupRestoreService;
use Illuminate\Support\Env;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class QueueReservationSecurityTest extends TestCase
{
    public function test_defaults_and_old_overrides_exceed_the_restore_timeout(): void
    {
        $environment = Env::getRepository();
        foreach (['REDIS_QUEUE_RETRY_AFTER' => 'redis', 'DB_QUEUE_RETRY_AFTER' => 'database'] as $key => $connection) {
            $original = $environment->get($key);
            try {
                foreach ([null, '90', '3000', 'invalid', '7200'] as $value) {
                    $environment->clear($key);
                    if ($value !== null) {
                        $environment->set($key, $value);
                    }
                    $config = require __DIR__.'/../../config/queue.php';
                    $reservation = $config['connections'][$connection]['retry_after'];
                    $this->assertSame($value === '7200' ? 7200 : 3600, $reservation);
                    $this->assertGreaterThan(BackupRestoreService::MAX_RESTORE_SECONDS, $reservation);
                }
            } finally {
                $environment->clear($key);
                if ($original !== null) {
                    $environment->set($key, $original);
                }
            }
        }
    }

    public function test_update_migration_is_idempotent_and_preserves_other_configuration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'queue-env');
        try {
            foreach ([
                "APP_NAME=ShipYard\n",
                "APP_NAME=ShipYard\nREDIS_QUEUE_RETRY_AFTER=90\nDB_QUEUE_RETRY_AFTER=\"7200\" # custom\n",
                "APP_NAME=ShipYard\nexport REDIS_QUEUE_RETRY_AFTER=invalid\nDB_QUEUE_RETRY_AFTER=3000\n",
            ] as $fixture) {
                file_put_contents($path, $fixture);
                $process = new Process([PHP_BINARY, __DIR__.'/../../scripts/ensure-queue-reservation.php', $path]);
                $process->mustRun();
                $result = file_get_contents($path);
                $this->assertStringContainsString("APP_NAME=ShipYard\n", $result);
                $this->assertStringContainsString('REDIS_QUEUE_RETRY_AFTER=3600', $result);
                $this->assertStringContainsString(str_contains($fixture, '7200') ? 'DB_QUEUE_RETRY_AFTER="7200" # custom' : 'DB_QUEUE_RETRY_AFTER=3600', $result);
                $process->mustRun();
                $this->assertSame($result, file_get_contents($path));
            }
        } finally {
            unlink($path);
        }
    }
}
