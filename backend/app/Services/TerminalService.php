<?php

namespace App\Services;

use App\Models\Server;
use App\Models\TerminalSession;
use App\Support\Ssh\InteractiveSSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

/**
 * Opens interactive PTY sessions for the web terminal. Deliberately
 * separate from SSHService: that service is exec-only, reuses live
 * sessions across connect() calls, and disconnects in __destruct,
 * all of which fight a long-lived shell channel.
 */
class TerminalService
{
    public const MAX_CONCURRENT = 3;

    public const IDLE_TIMEOUT = 900; // 15 min without input or output

    public const MAX_DURATION = 7200; // 2 h hard cap per session

    public const PENDING_TTL = 60; // seconds an unattached session may wait

    public const STALE_AFTER = 60; // seconds without last_seen_at touch

    public const READ_TIMEOUT = 0.05; // the stream loop's poll clock

    /**
     * Connect, authenticate and open a login shell with a PTY sized to
     * the client's terminal. Returns a connection ready for the 50ms
     * read-poll loop.
     */
    public function open(Server $server, int $cols, int $rows): InteractiveSSH2
    {
        if ($server->isLocal()) {
            throw new RuntimeException('The web terminal is not available for the local server.');
        }

        $ssh = new InteractiveSSH2($server->host, $server->port);
        $ssh->setTimeout(20); // login phase

        if (! $ssh->login($server->username, PublicKeyLoader::load($server->private_key))) {
            throw new RuntimeException("SSH authentication failed for {$server->host}");
        }

        // Window size must be set before openShell(): it is consumed by
        // the pty-req. openShell() requests a login shell from sshd.
        $ssh->setWindowSize($cols, $rows);
        $ssh->openShell();
        $ssh->setTimeout(self::READ_TIMEOUT);

        return $ssh;
    }

    public function inputKey(int $sessionId): string
    {
        return "terminal:{$sessionId}:input";
    }

    /**
     * Ends sessions that will never produce traffic again: pending rows
     * whose stream never attached, and active rows whose FPM worker died
     * without running its cleanup (last_seen_at is touched every 15s by
     * the stream loop). Called before the concurrency cap is checked so
     * dead sessions can never wedge the cap shut.
     */
    public function reapStale(): void
    {
        TerminalSession::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds(self::PENDING_TTL))
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
                'ended_reason' => 'never_attached',
            ]);

        TerminalSession::query()
            ->where('status', 'active')
            ->where('last_seen_at', '<', now()->subSeconds(self::STALE_AFTER))
            ->update([
                'status' => 'ended',
                'ended_at' => now(),
                'ended_reason' => 'stale',
            ]);
    }
}
