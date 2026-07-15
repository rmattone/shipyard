<?php

namespace Tests\Unit;

use App\Support\EnvFile;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    /**
     * @dataProvider valueProvider
     */
    public function test_values_round_trip(string $value): void
    {
        $line = EnvFile::line('KEY', $value);
        $parsed = EnvFile::parse($line);

        $this->assertSame($value, $parsed['KEY'], "Value did not round-trip: {$line}");
    }

    public static function valueProvider(): array
    {
        return [
            'simple' => ['simple'],
            'with space' => ['a value with spaces'],
            'single quote' => ["it's a secret"],
            'double quote' => ['say "hi"'],
            'dollar sign' => ['pa$$word'],
            'variable-like' => ['${NOT_INTERPOLATED}'],
            'backslash' => ['C:\\path\\to'],
            'hash' => ['value # with hash'],
            'empty' => [''],
            'literal backslash n' => ['back\\nslash'],
        ];
    }

    public function test_dollar_sign_is_escaped_to_prevent_interpolation(): void
    {
        $line = EnvFile::line('DB_PASSWORD', 'pa$word');

        $this->assertStringContainsString('\\$', $line, 'A literal $ must be escaped so phpdotenv does not interpolate it.');
    }

    public function test_single_quotes_are_not_backslash_escaped(): void
    {
        // The old addslashes() approach produced \' which phpdotenv does not
        // unescape, corrupting the value on the server.
        $line = EnvFile::line('KEY', "it's");

        $this->assertStringNotContainsString("\\'", $line);
    }

    public function test_simple_values_are_not_quoted(): void
    {
        $this->assertSame('KEY=simplevalue', EnvFile::line('KEY', 'simplevalue'));
    }

    public function test_parse_skips_comments_and_blank_lines(): void
    {
        $parsed = EnvFile::parse("# a comment\n\nFOO=bar\n  \nBAZ=qux");

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $parsed);
    }
}
