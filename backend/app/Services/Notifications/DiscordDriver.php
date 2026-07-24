<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class DiscordDriver implements NotificationDriverInterface
{
    private const COLOR_SUCCESS = 0x2ECC71;

    private const COLOR_FAILURE = 0xE74C3C;

    public function send(NotificationChannel $channel, DeploymentNotificationPayload $payload): void
    {
        $embed = [
            'title' => ($payload->succeeded ? '✅ ' : '❌ ').$payload->title(),
            'url' => $payload->url,
            'color' => $payload->succeeded ? self::COLOR_SUCCESS : self::COLOR_FAILURE,
            'fields' => $this->fields($payload),
        ];

        if ($payload->finishedAt !== null) {
            $embed['timestamp'] = $payload->finishedAt;
        }

        if ($payload->failureExcerpt !== null) {
            $excerpt = str_replace('`', "'", $payload->failureExcerpt);
            $embed['description'] = "```\n{$excerpt}\n```";
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post($channel->config['webhook_url'], ['embeds' => [$embed]]);

        if (! $response->successful()) {
            throw new NotificationSendException("Discord webhook returned HTTP {$response->status()}");
        }
    }

    private function fields(DeploymentNotificationPayload $payload): array
    {
        $fields = [];

        if ($payload->branch !== null) {
            $fields[] = ['name' => 'Branch', 'value' => $payload->branch, 'inline' => true];
        }

        if ($payload->commitShort !== null) {
            $value = "`{$payload->commitShort}`".($payload->commitMessage !== null ? " {$payload->commitMessage}" : '');
            $fields[] = ['name' => 'Commit', 'value' => $value, 'inline' => true];
        }

        if ($payload->durationHuman() !== null) {
            $fields[] = ['name' => 'Duration', 'value' => $payload->durationHuman(), 'inline' => true];
        }

        return $fields;
    }
}
