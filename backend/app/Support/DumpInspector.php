<?php

namespace App\Support;

use App\Exceptions\UnsupportedDumpException;

/**
 * Resolves a dump's format from its leading bytes. Extensions are not
 * trustworthy here: .sql.gz has no reliable MIME type and browsers report it
 * inconsistently, so the file itself is the only real evidence.
 */
class DumpInspector
{
    public const FORMAT_SQL = 'sql';

    public const FORMAT_SQL_GZ = 'sql_gz';

    /**
     * Prefixes that mark the start of a plain SQL dump produced by pg_dump or
     * mysqldump, compared case insensitively.
     */
    private const SQL_PREFIXES = ['--', '/*', 'set ', 'begin', 'create', 'drop', 'use ', 'start transaction'];

    public function detect(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new UnsupportedDumpException('The uploaded file could not be read.');
        }

        $head = fread($handle, 512);
        fclose($handle);

        if ($head === false || $head === '') {
            throw new UnsupportedDumpException('The uploaded file is empty.');
        }

        if (str_starts_with($head, "\x1f\x8b")) {
            return self::FORMAT_SQL_GZ;
        }

        if (str_starts_with($head, 'PGDMP')) {
            throw new UnsupportedDumpException(
                'This is a custom-format pg_dump archive, which needs pg_restore rather than psql. '
                .'Re-create it with pg_dump --format=plain (optionally piped through gzip) and upload that.'
            );
        }

        $normalized = strtolower(ltrim($head));

        foreach (self::SQL_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return self::FORMAT_SQL;
            }
        }

        throw new UnsupportedDumpException(
            'Unrecognized dump. Upload plain SQL from pg_dump or mysqldump, either uncompressed or gzipped.'
        );
    }
}
