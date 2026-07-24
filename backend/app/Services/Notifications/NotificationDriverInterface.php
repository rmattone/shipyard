<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;

interface NotificationDriverInterface
{
    /**
     * @throws NotificationSendException on any delivery failure
     */
    public function send(NotificationChannel $channel, DeploymentNotificationPayload $payload): void;
}
