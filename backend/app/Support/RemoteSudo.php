<?php

namespace App\Support;

use App\Models\Server;

/**
 * Wraps commands that need root on a managed server. Servers accessed as
 * root run the command unchanged (sudo may not even be installed there);
 * any other SSH user gets a non-interactive sudo prefix, which requires
 * passwordless sudo for that user (documented in the README). Local
 * servers run commands as the panel process user, unchanged.
 */
class RemoteSudo
{
    public static function wrap(Server $server, string $command): string
    {
        if ($server->isLocal() || $server->username === 'root') {
            return $command;
        }

        return "sudo -n {$command}";
    }
}
