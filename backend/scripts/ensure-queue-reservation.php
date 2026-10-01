<?php

// Run before config:clear/queue:restart during updates. Do not load or
// execute the environment file: change only the two reservation settings.
$path = $argv[1] ?? dirname(__DIR__).'/.env';
$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Cannot read backend environment configuration.\n");
    exit(1);
}

foreach (['REDIS_QUEUE_RETRY_AFTER', 'DB_QUEUE_RETRY_AFTER'] as $key) {
    $count = 0;
    $contents = preg_replace_callback(
        '/^[\t ]*(?:export[\t ]+)?'.preg_quote($key, '/').'[\t ]*=[^\r\n]*/m',
        static function (array $match) use ($key): string {
            $value = trim(explode('=', $match[0], 2)[1]);
            // Preserve larger custom values, including quoted values and comments.
            if (preg_match('/^[\'"]?(\d+)[\'"]?[\t ]*(?:#.*)?$/', $value, $number)
                && (float) $number[1] >= 3600) {
                return $match[0];
            }

            return $key.'=3600';
        },
        $contents,
        -1,
        $count
    );
    if ($count === 0) {
        $contents = rtrim($contents, "\r\n")."\n{$key}=3600\n";
    }
}

if (file_put_contents($path, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Cannot update queue reservation settings.\n");
    exit(1);
}
