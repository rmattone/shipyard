<?php

namespace Tests\Unit;

use App\Services\BackupRestoreService;
use App\Services\MySQLService;
use App\Services\PostgreSQLService;
use App\Services\SSHService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BackupRetentionSecurityTest extends TestCase
{
    public function test_pruning_keeps_newest_three_and_preserves_other_files(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Runs the GNU utilities used on managed Linux servers.');
        }

        $directory = sys_get_temp_dir()."/retention ' ".bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $names = ['shop-1.sql.gz', "shop-2 with space\nline.sql.gz", 'shop-3.sql.gz', 'shop-4.sql.gz', 'shop-5.sql.gz'];
        try {
            foreach ($names as $index => $name) {
                file_put_contents($directory.'/'.$name, 'fixture');
                touch($directory.'/'.$name, 1700000000 + $index);
            }
            file_put_contents($directory.'/other-1.sql.gz', 'unrelated');
            mkdir($directory.'/shop-directory.sql.gz');
            symlink($directory.'/other-1.sql.gz', $directory.'/shop-link.sql.gz');

            $ssh = $this->createMock(SSHService::class);
            $ssh->expects($this->once())->method('execute')->willReturnCallback(function ($command) use ($directory) {
                $command = str_replace(escapeshellarg('/var/backups/shipyard'), escapeshellarg($directory), $command);
                $process = Process::fromShellCommandline($command);
                $process->mustRun();

                return ['success' => true, 'output' => $process->getOutput(), 'exit_code' => 0];
            });
            $service = new BackupRestoreService($ssh, $this->createMock(MySQLService::class), $this->createMock(PostgreSQLService::class));
            (new \ReflectionMethod($service, 'pruneSafetyDumps'))->invoke($service, 'shop');

            foreach ($names as $index => $name) {
                $this->assertSame($index >= 2, file_exists($directory.'/'.$name), $name);
            }
            $this->assertFileExists($directory.'/other-1.sql.gz');
            $this->assertDirectoryExists($directory.'/shop-directory.sql.gz');
            $this->assertTrue(is_link($directory.'/shop-link.sql.gz'));
        } finally {
            foreach (array_diff(scandir($directory), ['.', '..']) as $name) {
                $path = $directory.'/'.$name;
                is_dir($path) && ! is_link($path) ? rmdir($path) : unlink($path);
            }
            rmdir($directory);
        }
    }
}
