<?php

namespace Tests\Feature;

use App\Services\SSHService;
use phpseclib3\Net\SFTP;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Restore dumps reach a gigabyte, so upload() must hand phpseclib a path and
 * let it stream, never read the file into memory first.
 */
class SSHServiceUploadTest extends TestCase
{
    public function test_upload_streams_from_the_local_path(): void
    {
        $localPath = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($localPath, 'SELECT 1;');

        $sftp = $this->mock(SFTP::class);
        $sftp->shouldReceive('put')
            ->once()
            ->with('/var/tmp/dump.sql', $localPath, SFTP::SOURCE_LOCAL_FILE)
            ->andReturn(true);
        // SSHService::__destruct() calls disconnect(), which calls
        // $sftp->disconnect() during teardown; stub it so the strict mock
        // doesn't fail on a call unrelated to the behavior under test.
        $sftp->shouldReceive('disconnect')->zeroOrMoreTimes();

        $service = new SSHService;

        $property = new ReflectionProperty(SSHService::class, 'sftp');
        $property->setValue($service, $sftp);

        $this->assertTrue($service->upload($localPath, '/var/tmp/dump.sql'));

        unlink($localPath);
    }
}
