<?php

namespace App\Support;

/**
 * Serializes and parses .env content in a way that round-trips through
 * phpdotenv (the reader Laravel apps use on the target server).
 *
 * The previous ad-hoc `addslashes()` approach was wrong on two counts:
 * phpdotenv does not unescape `\'`, and an unquoted `$` triggers variable
 * interpolation, both of which silently corrupted secrets on the server.
 */
class EnvFile
{
    /**
     * Build .env content from a collection of models exposing `key`/`value`.
     *
     * @param  iterable<object{key: string, value: string}>  $variables
     */
    public static function serialize(iterable $variables): string
    {
        $lines = [];

        foreach ($variables as $var) {
            $lines[] = self::line($var->key, (string) ($var->value ?? ''));
        }

        return implode("\n", $lines);
    }

    /**
     * Render a single KEY=VALUE line.
     */
    public static function line(string $key, string $value): string
    {
        return $key.'='.self::formatValue($value);
    }

    /**
     * Quote and escape a value only when needed, so simple values stay
     * readable. Escapes are chosen to match phpdotenv's unescaping inside
     * double quotes.
     */
    public static function formatValue(string $value): string
    {
        $needsQuoting = $value === '' || preg_match('/[\s#"\'$\\\\`]/', $value) === 1
            || preg_match('/[\x00-\x1F]/', $value) === 1;

        if (! $needsQuoting) {
            return $value;
        }

        // strtr applies each mapping once (no cascading), so a backslash does
        // not get re-escaped after becoming `\\`.
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        return '"'.$escaped.'"';
    }

    /**
     * Parse .env content keeping its layout: comments, blank lines, and the
     * order keys appear in. `variables` is the key => value map; `layout` is
     * the line sequence (`raw` entries verbatim, `var` entries by key) used
     * by render() to write the file back without compacting it.
     *
     * @return array{variables: array<string, string>, layout: array<int, array{type: string, key?: string, text?: string}>}
     */
    public static function parseDocument(string $content): array
    {
        $variables = [];
        $layout = [];

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '' && ! str_starts_with($trimmed, '#')
                && preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $trimmed, $matches)) {
                $key = strtoupper($matches[1]);
                if (! array_key_exists($key, $variables)) {
                    $layout[] = ['type' => 'var', 'key' => $key];
                }
                $variables[$key] = self::parseValue($matches[2]);

                continue;
            }

            $layout[] = ['type' => 'raw', 'text' => $line];
        }

        return ['variables' => $variables, 'layout' => $layout];
    }

    /**
     * Render .env content through a stored layout: values update in place,
     * keys no longer present are dropped, new keys append at the end, and
     * comments/blank lines survive verbatim. A null layout degrades to
     * serialize().
     *
     * @param  array<int, array{type: string, key?: string, text?: string}>|null  $layout
     * @param  iterable<object{key: string, value: string}>  $variables
     */
    public static function render(?array $layout, iterable $variables): string
    {
        $values = [];
        foreach ($variables as $var) {
            $values[$var->key] = (string) ($var->value ?? '');
        }

        if ($layout === null || $layout === []) {
            $lines = [];
            foreach ($values as $key => $value) {
                $lines[] = self::line($key, $value);
            }

            return implode("\n", $lines);
        }

        $lines = [];
        foreach ($layout as $entry) {
            if (($entry['type'] ?? '') === 'var') {
                $key = $entry['key'] ?? '';
                if (array_key_exists($key, $values)) {
                    $lines[] = self::line($key, $values[$key]);
                    unset($values[$key]);
                }

                continue;
            }

            $lines[] = $entry['text'] ?? '';
        }

        foreach ($values as $key => $value) {
            $lines[] = self::line($key, $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Parse .env content into a key => value map, reversing formatValue().
     *
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $variables = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/s', $line, $matches)) {
                continue;
            }

            $key = strtoupper($matches[1]);
            $variables[$key] = self::parseValue($matches[2]);
        }

        return $variables;
    }

    private static function parseValue(string $raw): string
    {
        // Double-quoted: unescape the sequences formatValue() produces
        if (preg_match('/^"(.*)"$/s', $raw, $m)) {
            return strtr($m[1], [
                '\\n' => "\n",
                '\\r' => "\r",
                '\\t' => "\t",
                '\\"' => '"',
                '\\$' => '$',
                '\\\\' => '\\',
            ]);
        }

        // Single-quoted: literal
        if (preg_match("/^'(.*)'$/s", $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }
}
