<?php

namespace App\Services\Terminal;

/**
 * Codec for the terminal input channel through Redis. Keystrokes are
 * base64 inside a JSON envelope so control bytes and UTF-8 survive
 * byte-for-byte; the stream loop decodes and writes them to the PTY.
 *
 * Types: 'i' = input (d: base64 bytes), 'r' = resize (c/r: cols/rows),
 * 'c' = close.
 */
final class TerminalMessage
{
    public static function input(string $base64Data): string
    {
        return json_encode(['t' => 'i', 'd' => $base64Data]);
    }

    public static function resize(int $columns, int $rows): string
    {
        return json_encode(['t' => 'r', 'c' => $columns, 'r' => $rows]);
    }

    public static function close(): string
    {
        return json_encode(['t' => 'c']);
    }

    public static function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
