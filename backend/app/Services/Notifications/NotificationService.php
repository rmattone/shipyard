<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;

class NotificationService
{
    /**
     * @throws NotificationSendException
     */
    public function send(NotificationChannel $channel, DeploymentNotificationPayload $payload): void
    {
        $this->driverFor($channel)->send($channel, $payload);
    }

    /**
     * @throws NotificationSendException
     */
    public function sendTest(NotificationChannel $channel): void
    {
        $this->send($channel, DeploymentNotificationPayload::test());
    }

    private function driverFor(NotificationChannel $channel): NotificationDriverInterface
    {
        return match ($channel->type) {
            NotificationChannel::TYPE_DISCORD => new DiscordDriver,
            NotificationChannel::TYPE_TELEGRAM => new TelegramDriver,
            NotificationChannel::TYPE_EMAIL => new ResendEmailDriver,
            default => throw new NotificationSendException("Unknown channel type: {$channel->type}"),
        };
    }
}
