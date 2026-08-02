<?php

namespace Tests\Unit;

use App\Services\Terminal\TerminalMessage;
use PHPUnit\Framework\TestCase;

/**
 * The terminal Redis protocol: JSON envelopes with base64 keystroke data
 * so control bytes (Ctrl-C, arrow escapes) and UTF-8 survive the trip
 * from the browser through Redis to the SSH channel byte-for-byte.
 */
class TerminalMessageTest extends TestCase
{
    public function test_input_round_trips_control_bytes_and_utf8(): void
    {
        foreach (["\x03", "\x1b[A", 'café ☕', 'plain', "\r"] as $keystrokes) {
            $decoded = TerminalMessage::decode(TerminalMessage::input(base64_encode($keystrokes)));

            $this->assertSame('i', $decoded['t']);
            $this->assertSame($keystrokes, base64_decode($decoded['d'], true));
        }
    }

    public function test_resize_carries_columns_and_rows(): void
    {
        $decoded = TerminalMessage::decode(TerminalMessage::resize(120, 32));

        $this->assertSame(['t' => 'r', 'c' => 120, 'r' => 32], $decoded);
    }

    public function test_close_message(): void
    {
        $this->assertSame(['t' => 'c'], TerminalMessage::decode(TerminalMessage::close()));
    }

    public function test_decode_rejects_garbage(): void
    {
        $this->assertNull(TerminalMessage::decode('not json'));
        $this->assertNull(TerminalMessage::decode('"a bare string"'));
        $this->assertNull(TerminalMessage::decode('42'));
    }
}
