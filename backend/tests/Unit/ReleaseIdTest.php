<?php

namespace Tests\Unit;

use App\Models\Deployment;
use PHPUnit\Framework\TestCase;

class ReleaseIdTest extends TestCase
{
    public function test_release_id_has_timestamp_prefix_and_random_suffix(): void
    {
        $releaseId = Deployment::generateReleaseId();

        $this->assertMatchesRegularExpression('/^\d{14}-[a-z0-9]{6}$/', $releaseId);
    }

    public function test_release_ids_generated_in_the_same_second_differ(): void
    {
        $first = Deployment::generateReleaseId();
        $second = Deployment::generateReleaseId();

        $this->assertNotSame($first, $second);
    }
}
