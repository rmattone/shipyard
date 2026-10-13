<?php

namespace App\Support\Ssh;

use App\Models\Server;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class ServerHostKey
{
    public static function prepare(Server $server): void
    {
        // Another worker may have learned the key since this model was loaded.
        if ($server->exists && blank($server->ssh_host_key)) {
            $server->ssh_host_key = self::query($server)->firstOrFail()->ssh_host_key;
        }

        if (filled($server->ssh_host_key)) {
            HostKey::normalize($server->ssh_host_key);
        }
    }

    public static function remember(Server $server, string $key): void
    {
        if (filled($server->ssh_host_key)) {
            return;
        }

        if ($server->exists) {
            // Only the first successful connection can enroll a key. Never
            // overwrite a pin saved by another request during authentication.
            $updated = self::query($server)
                ->where(fn (Builder $query) => $query->whereNull('ssh_host_key')->orWhere('ssh_host_key', ''))
                ->update(['ssh_host_key' => $key]);

            if (! $updated) {
                $saved = self::query($server)->firstOrFail()->ssh_host_key;
                if (! hash_equals(HostKey::normalize($saved), $key)) {
                    throw new RuntimeException('SSH host key mismatch. Connection refused because another connection saved a different host key.');
                }
            }
        }

        // Ad hoc tests return this key so the form can include it on creation.
        $server->ssh_host_key = $key;
        $server->syncOriginalAttribute('ssh_host_key');
    }

    private static function query(Server $server): Builder
    {
        return $server->newQuery()->whereKey($server->getKey())
            ->where('host', $server->host)->where('port', $server->port);
    }
}
