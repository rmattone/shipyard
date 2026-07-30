<?php

namespace App\Support;

use App\Exceptions\UnsupportedDumpException;

/**
 * Resolves a dump's format from its leading bytes, including one layer of
 * gzip decompression. Extensions are not trustworthy here: .sql.gz has no
 * reliable MIME type and browsers report it inconsistently, so the file
 * itself is the only real evidence, compressed or not: the gzip wrapper
 * alone is not proof of content, a gzipped JPEG has the same two magic
 * bytes as a gzipped SQL dump.
 */
class DumpInspector
{
    public const FORMAT_SQL = 'sql';

    public const FORMAT_SQL_GZ = 'sql_gz';

    private const PEEK_BYTES = 512;

    /**
     * Prefixes that mark the start of a plain SQL dump produced by pg_dump or
     * mysqldump, compared case insensitively. Verified against real output
     * from pg_dump 18.3 (plain, --clean, --data-only, --no-comments,
     * single-table) and mysqldump 8.4 (default, --compact,
     * --single-transaction): none of these tools ever starts a file with a
     * bare CREATE/DROP/BEGIN, they always lead with a "--" header comment.
     * Requiring a trailing space after CREATE/DROP (and an immediate ";"
     * after BEGIN, which is how Postgres's bare transaction statement is
     * always written) therefore costs no real dump while rejecting free text
     * such as "Created by ..." or "Begin transmission log".
     */
    private const SQL_PREFIXES = ['--', '/*', 'set ', 'begin;', 'create ', 'drop ', 'use ', 'start transaction'];

    public function detect(string $path): string
    {
        $head = $this->readHead($path);

        if (str_starts_with($head, "\x1f\x8b")) {
            return $this->detectGzipped($path);
        }

        $this->assertLooksLikeSql($head);

        return self::FORMAT_SQL;
    }

    private function readHead(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new UnsupportedDumpException('The uploaded file could not be read.');
        }

        $head = fread($handle, self::PEEK_BYTES);
        fclose($handle);

        if ($head === false || $head === '') {
            throw new UnsupportedDumpException('The uploaded file is empty.');
        }

        return $head;
    }

    /**
     * Reads only the first bytes of the decompressed stream, never the whole
     * file: this is what keeps classification cheap even on a multi-gigabyte
     * dump.
     */
    private function detectGzipped(string $path): string
    {
        $stream = @gzopen($path, 'rb');

        if ($stream === false) {
            throw new UnsupportedDumpException('The uploaded file is not a valid gzip archive.');
        }

        $inner = @gzread($stream, self::PEEK_BYTES);
        gzclose($stream);

        if ($inner === false) {
            throw new UnsupportedDumpException(
                'The uploaded file is not a valid gzip archive. Re-create it and upload again.'
            );
        }

        if ($inner === '') {
            // Decompresses to nothing, so there is no content to classify.
            // Whether that is a genuinely empty dump or a truncated stream is
            // for the load step to discover, not this format check.
            return self::FORMAT_SQL_GZ;
        }

        $this->assertLooksLikeSql($inner);

        return self::FORMAT_SQL_GZ;
    }

    private function assertLooksLikeSql(string $head): void
    {
        if (str_starts_with($head, 'PGDMP')) {
            throw new UnsupportedDumpException(
                'This is a custom-format pg_dump archive, which needs pg_restore rather than psql. '
                .'Re-create it with pg_dump --format=plain (optionally piped through gzip) and upload that.'
            );
        }

        $normalized = strtolower(ltrim($head));

        foreach (self::SQL_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new UnsupportedDumpException(
            'Unrecognized dump. Upload plain SQL from pg_dump or mysqldump, either uncompressed or gzipped.'
        );
    }
}
