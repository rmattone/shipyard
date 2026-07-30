<?php

namespace Tests\Unit;

use App\Exceptions\UnsupportedDumpException;
use App\Support\DumpInspector;
use PHPUnit\Framework\TestCase;

class DumpInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }

        $this->tempPaths = [];

        parent::tearDown();
    }

    private function writeTemp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dump');
        file_put_contents($path, $bytes);

        $this->tempPaths[] = $path;

        return $path;
    }

    public function test_detects_gzip_by_magic_bytes(): void
    {
        $path = $this->writeTemp(gzencode('-- PostgreSQL database dump'));

        $this->assertSame(DumpInspector::FORMAT_SQL_GZ, (new DumpInspector)->detect($path));
    }

    public function test_detects_plain_sql_starting_with_a_comment(): void
    {
        $path = $this->writeTemp("--\n-- PostgreSQL database dump\n--\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    public function test_detects_plain_sql_starting_with_a_statement(): void
    {
        $path = $this->writeTemp("SET statement_timeout = 0;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    public function test_detects_plain_sql_after_leading_whitespace(): void
    {
        $path = $this->writeTemp("\n\n   /* mysqldump */\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
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
    }

    public function test_rejects_arbitrary_binary(): void
    {
        $path = $this->writeTemp("\x00\x01\x02\x03binary");

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_rejects_an_empty_file(): void
    {
        $path = $this->writeTemp('');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_detects_mysqldump_starting_with_a_conditional_comment(): void
    {
        $path = $this->writeTemp("/*!40101 SET NAMES utf8mb4 */;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    public function test_detects_gzip_of_empty_content_by_magic_bytes(): void
    {
        $path = $this->writeTemp(gzencode(''));

        $this->assertSame(DumpInspector::FORMAT_SQL_GZ, (new DumpInspector)->detect($path));
    }

    public function test_rejects_a_file_of_only_whitespace(): void
    {
        $path = $this->writeTemp(str_repeat(' ', 600));

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    /**
     * "create", "drop", and "begin" alone are broad enough to match free
     * text, and this class is the only thing standing between an
     * accidentally-selected file and a database that a later restore step
     * drops before loading. These three document real near-misses.
     */
    public function test_rejects_free_text_starting_with_create(): void
    {
        $path = $this->writeTemp('Created by Acme Export Tool');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_rejects_free_text_starting_with_drop(): void
    {
        $path = $this->writeTemp('Dropbox sync log');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_rejects_free_text_starting_with_begin(): void
    {
        $path = $this->writeTemp('Begin transmission log');

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_detects_plain_sql_starting_with_bare_begin_statement(): void
    {
        $path = $this->writeTemp("BEGIN;\nINSERT INTO t VALUES (1);\nCOMMIT;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    public function test_detects_plain_sql_starting_with_create_table(): void
    {
        $path = $this->writeTemp("CREATE TABLE x (id int);\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    public function test_detects_plain_sql_starting_with_drop_database(): void
    {
        $path = $this->writeTemp("DROP DATABASE x;\n");

        $this->assertSame(DumpInspector::FORMAT_SQL, (new DumpInspector)->detect($path));
    }

    /**
     * The gzip magic bytes alone are not evidence of content: a gzipped JPEG
     * has the identical two-byte header. Detection has to look inside.
     */
    public function test_rejects_gzipped_non_sql_content(): void
    {
        $path = $this->writeTemp(gzencode(str_repeat("\xFF\xD8\xFF\xE0binary jpeg-ish bytes", 20)));

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }

    public function test_rejects_gzip_wrapped_custom_format_archive(): void
    {
        $path = $this->writeTemp(gzencode("PGDMP\x00\x00custom format archive body"));

        try {
            (new DumpInspector)->detect($path);
            $this->fail('Expected UnsupportedDumpException');
        } catch (UnsupportedDumpException $e) {
            $this->assertStringContainsString('pg_restore', $e->getMessage());
            $this->assertStringContainsString('--format=plain', $e->getMessage());
        }
    }

    public function test_rejects_a_corrupt_gzip_stream(): void
    {
        // Valid magic bytes followed by bytes that are not a valid deflate
        // stream: gzread fails to decode anything from this, unlike a
        // genuinely empty compressed payload.
        $path = $this->writeTemp("\x1f\x8b\x08\x00".str_repeat("\xff", 200));

        $this->expectException(UnsupportedDumpException::class);

        (new DumpInspector)->detect($path);
    }
}
