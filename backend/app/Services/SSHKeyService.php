<?php

namespace App\Services;

use phpseclib3\Crypt\RSA;

class SSHKeyService
{
    public function generateKeyPair(string $comment = ''): array
    {
        $key = RSA::createKey(4096);

        $privateKey = $key->toString('OpenSSH');
        $publicKey = $key->getPublicKey()->toString('OpenSSH');

        if ($comment) {
            $publicKey = trim($publicKey) . ' ' . $comment;
        }

        return [
            'private_key' => $privateKey,
            'public_key' => $publicKey,
        ];
    }
}
