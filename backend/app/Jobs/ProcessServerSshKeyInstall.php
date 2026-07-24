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

class ProcessServerSshKeyInstall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // High tries with maxExceptions = 1: lock-blocked releases from
    // WithoutOverlapping count as attempts, but a real exception still
    // fails the job immediately.
    public int $tries = 60;

    public int $maxExceptions = 1;

    public int $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public ServerSshKey $sshKey
    ) {}

    public function middleware(): array
    {
        // Shared with the removal job: an install and a removal for the
        // same server+username must never race on the same authorized_keys
        // file.
        return [
            (new WithoutOverlapping("authorized-keys:{$this->sshKey->server_id}:{$this->sshKey->username}"))
                ->shared()
                ->expireAfter(180)
                ->releaseAfter(10),
        ];
    }

    public function handle(AuthorizedKeysService $authorizedKeysService): void
    {
        $authorizedKeysService->installKey($this->sshKey);

        $this->sshKey->markAsInstalled();
    }

    public function failed(Throwable $exception): void
    {
        $sshKey = $this->sshKey->fresh();

        if ($sshKey === null) {
            return;
        }

        $sshKey->markAsFailed($exception->getMessage());
    }
}
