<?php

namespace App\Support\Ssh;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

final class HostKey
{
    public static function normalize(?string $key): string
    {
        if (! $key || ! preg_match('/\A(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+\/=]+(?: [^\r\n]*)?\z/', trim($key))) {
            throw new RuntimeException('Add a trusted SSH public host key from the server console or provider documentation before connecting.');
        }

        try {
            $public = PublicKeyLoader::loadPublicKey(trim($key))->toString('OpenSSH');
            $parts = explode(' ', $public);

            return $parts[0].' '.$parts[1];
        } catch (Throwable $e) {
            throw new RuntimeException('Invalid SSH public host key.', 0, $e);
        }
    }

    public static function verify(SSH2 $client, ?string $trusted): void
    {
        try {
            $expected = self::normalize($trusted);
            $type = explode(' ', $expected)[0];
            $client->setPreferredAlgorithms([
                'hostkey' => $type === 'ssh-rsa' ? ['rsa-sha2-512', 'rsa-sha2-256'] : [$type],
            ]);
            $actual = $client->getServerPublicHostKey();
            // phpseclib labels RSA keys with the negotiated signature algorithm.
            if (is_string($actual)) {
                $actual = preg_replace('/^rsa-sha2-(256|512) /', 'ssh-rsa ', $actual);
            }
            if (! is_string($actual) || ! hash_equals($expected, self::normalize($actual))) {
                throw new RuntimeException('SSH host key mismatch. Connection refused. Verify the server identity through a trusted channel before replacing its saved key.');
            }
        } catch (Throwable $e) {
            $client->disconnect();
            throw $e;
        }
    }
}
