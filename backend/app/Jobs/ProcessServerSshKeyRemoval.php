<?php

namespace App\Jobs;

use App\Models\ServerSshKey;
use App\Services\AuthorizedKeysService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessServerSshKeyRemoval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public ServerSshKey $sshKey
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("authorized-keys:{$this->sshKey->server_id}:{$this->sshKey->username}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(AuthorizedKeysService $authorizedKeysService): void
    {
        $authorizedKeysService->removeKey($this->sshKey);

        $this->sshKey->delete();
    }

    public function failed(Throwable $exception): void
    {
        $sshKey = $this->sshKey->fresh();

        if ($sshKey === null) {
            return;
        }

        // Keep the row: the key may still be present on the server, and a
        // retried DELETE can finish the cleanup.
        $sshKey->markAsFailed($exception->getMessage());
    }
}
