<?php

namespace Tests\Unit;

use App\Exceptions\UnsupportedDumpException;
use App\Support\DumpInspector;
use PHPUnit\Framework\TestCase;

class DumpInspectorTest extends TestCase
{
    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_detects_gzip_by_magic_bytes(): void
    {
        $path = $this->writeTemp(gzencode('-- PostgreSQL database dump'));

        $this->assertSame(DumpInspector::FORMAT_SQL_GZ, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_starting_with_a_comment(): void
    {
        $path = $this->writeTemp("--\n-- PostgreSQL database dump\n--\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_starting_with_a_statement(): void
    {
        $path = $this->writeTemp("SET statement_timeout = 0;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_plain_sql_after_leading_whitespace(): void
    {
        $path = $this->writeTemp("\n\n   /* mysqldump */\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_rejects_custom_format_pg_dump_archives_by_name(): void
    {
        $path = $this->writeTemp("PGDMP\x00\x00");

        try {
            (new DumpInspector)->detect($path);
            $this->fail('Expected UnsupportedDumpException');
        } catch (UnsupportedDumpException $e) {
            $this->assertStringContainsString('pg_restore', $e->getMessage());
            $this->assertStringContainsString('--format=plain', $e->getMessage());
        }

        unlink($path);
    }

    public function test_rejects_arbitrary_binary(): void
    {
        $path = $this->writeTemp("\x00\x01\x02\x03binary");

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);

        unlink($path);
    }

    public function test_rejects_an_empty_file(): void
    {
        $path = $this->writeTemp('');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);

        unlink($path);
    }

    public function test_detects_mysqldump_starting_with_a_conditional_comment(): void
    {
        $path = $this->writeTemp("/*!40101 SET NAMES utf8mb4 */;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_detects_gzip_of_empty_content_by_magic_bytes(): void
    {
        $path = $this->writeTemp(gzencode(''));

        $this->assertSame(DumpInspector::FORMAT_SQL_GZ, (new DumpInspector)->detect($path));

        unlink($path);
    }

    public function test_rejects_a_file_of_only_whitespace(): void
    {
        $path = $this->writeTemp(str_repeat(' ', 600));

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);

        unlink($path);
    }
}
