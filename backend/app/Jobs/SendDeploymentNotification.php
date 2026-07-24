<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\NotificationChannel;
use App\Services\Notifications\DeploymentNotificationPayload;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendDeploymentNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Best-effort: per-channel errors are caught in handle(), so a retry
    // could only re-send already-delivered pings.
    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Deployment $deployment,
        public string $event,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $channels = NotificationChannel::query()
            ->enabled()
            ->subscribedTo($this->event)
            ->get();

        if ($channels->isEmpty()) {
            return;
        }

        $payload = DeploymentNotificationPayload::fromDeployment($this->deployment, $this->event);

        foreach ($channels as $channel) {
            try {
                $notificationService->send($channel, $payload);
            } catch (\Throwable $e) {
                // One bad channel never blocks the rest.
                Log::warning("Notification channel '{$channel->name}' ({$channel->type}) failed for deployment {$this->deployment->id}: {$e->getMessage()}");
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning("Deployment notification job failed for deployment {$this->deployment->id}: {$exception->getMessage()}");
    }
}
