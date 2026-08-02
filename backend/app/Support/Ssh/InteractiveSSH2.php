<?php

namespace App\Support\Ssh;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

/**
 * SSH2 with live PTY resizing. phpseclib 3.0 consumes the window size
 * once at pty-req time (openShell) and offers no public API for the
 * RFC 4254 section 6.7 window-change request, so this sends it by hand
 * through the protected channel internals.
 */
class InteractiveSSH2 extends SSH2
{
    public function sendWindowChange(int $columns, int $rows): void
    {
        if (! $this->isInteractiveChannelOpen(self::CHANNEL_SHELL)) {
            return;
        }

        // Keep getWindowColumns()/getWindowRows() truthful.
        $this->setWindowSize($columns, $rows);

        $packet = Strings::packSSH2(
            'CNsbN4',
            NET_SSH2_MSG_CHANNEL_REQUEST,
            $this->server_channels[self::CHANNEL_SHELL],
            'window-change',
            false, // want_reply
            $columns,
            $rows,
            0, // width in pixels
            0  // height in pixels
        );

        $this->send_binary_packet($packet);
    }
}
