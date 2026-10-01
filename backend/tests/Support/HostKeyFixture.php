<?php

namespace Tests\Support;

use phpseclib3\Crypt\EC;

final class HostKeyFixture
{
    public static function key(): string
    {
        static $key;

        return $key ??= EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');
    }
}
