<?php

namespace App\Services\Concerns;

/**
 * Wraps a shell pipeline (e.g. `pg_dump ... | gzip > out` or
 * `gunzip -c dump.gz | psql ...`) so the command's exit status reflects a
 * failure anywhere in the pipe, not just in the last stage. Without this,
 * the shell reports only the last command's exit status: a failing
 * pg_dump/mysqldump piped into a succeeding gzip would report success while
 * writing a truncated archive, and a corrupt archive failing gunzip could
 * hide behind a clean psql/mysql exit. `set -o pipefail` is not POSIX and
 * dash does not support it, so bash is invoked explicitly rather than
 * relying on whatever default shell phpseclib's exec() lands on.
 *
 * The `{ <pipeline>; } 2>&1` group redirect captures stderr from every
 * stage of the pipeline (SSHService/phpseclib's exec() only surfaces
 * stdout) without writing it into the archive the way appending a trailing
 * `2>&1` after `> $path` would (that duplicates the file descriptor and
 * mixes stderr into the file). The group's own stdout is untouched by the
 * redirect, so a piped command's normal stdout output still flows through
 * unmodified; only stderr is merged into it.
 */
trait WrapsShellPipeline
{
    protected function withPipefail(string $pipeline): string
    {
        return 'bash -c '.escapeshellarg('set -o pipefail; { '.$pipeline.'; } 2>&1');
    }
}
