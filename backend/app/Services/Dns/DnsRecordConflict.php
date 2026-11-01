<?php

namespace App\Services\Dns;

use RuntimeException;

/**
 * The hostname already has address records that do not point at the
 * server. Replacing them could take a live site offline, so the caller has
 * to confirm explicitly (overwrite) before ShipYard touches them.
 */
class DnsRecordConflict extends RuntimeException
{
    /**
     * @param  array<int, array{type: string, content: string}>  $records
     */
    public function __construct(public readonly string $hostname, public readonly array $records)
    {
        $list = implode(', ', array_map(fn ($r) => "{$r['type']} {$r['content']}", $records));

        parent::__construct("{$hostname} already has DNS records ({$list}). Confirm to replace them with a record for this server.");
    }
}
