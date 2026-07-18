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

    // The panel editor and server sync must not compact the file: comments,
    // blank lines, and the order the user wrote survive the round-trip.
    public function test_document_round_trips_comments_blank_lines_and_order(): void
    {
        $content = "# App settings\nAPP_NAME=shipyard\n\n# Database\nDB_HOST=localhost\nDB_PASSWORD=secret";

        $doc = EnvFile::parseDocument($content);
        $vars = collect($doc['variables'])->map(fn ($v, $k) => (object) ['key' => $k, 'value' => $v]);

        $this->assertSame($content, EnvFile::render($doc['layout'], $vars));
    }

    public function test_render_updates_values_in_place(): void
    {
        $doc = EnvFile::parseDocument("# db\nDB_HOST=old\n\nAPP_NAME=x");
        $vars = [
            (object) ['key' => 'DB_HOST', 'value' => 'new'],
            (object) ['key' => 'APP_NAME', 'value' => 'x'],
        ];

        $this->assertSame("# db\nDB_HOST=new\n\nAPP_NAME=x", EnvFile::render($doc['layout'], $vars));
    }

    public function test_render_drops_deleted_keys_and_appends_new_ones(): void
    {
        $doc = EnvFile::parseDocument("# kept\nGONE=1\nKEPT=2");
        $vars = [
            (object) ['key' => 'KEPT', 'value' => '2'],
            (object) ['key' => 'ADDED', 'value' => '3'],
        ];

        $this->assertSame("# kept\nKEPT=2\nADDED=3", EnvFile::render($doc['layout'], $vars));
    }

    public function test_render_without_layout_matches_serialize(): void
    {
        $vars = [(object) ['key' => 'B', 'value' => '2'], (object) ['key' => 'A', 'value' => '1']];

        $this->assertSame("B=2\nA=1", EnvFile::render(null, $vars));
    }
}
