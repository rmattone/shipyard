<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class TelegramDriver implements NotificationDriverInterface
{
    public function send(NotificationChannel $channel, DeploymentNotificationPayload $payload): void
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post("https://api.telegram.org/bot{$channel->config['bot_token']}/sendMessage", [
                'chat_id' => $channel->config['chat_id'],
                'text' => $this->text($payload),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed() || $response->json('ok') !== true) {
            $description = $response->json('description') ?? "HTTP {$response->status()}";

            throw new NotificationSendException("Telegram API error: {$description}");
        }
    }

    private function text(DeploymentNotificationPayload $payload): string
    {
        // Telegram's HTML parse mode rejects the whole message on any
        // unescaped < or &, so every interpolated value gets escaped.
        $e = fn (?string $v) => htmlspecialchars($v ?? '', ENT_QUOTES);

        $lines = [
            ($payload->succeeded ? '✅' : '❌')." <b>{$e($payload->title())}</b>",
        ];

        $details = [];
        if ($payload->branch !== null) {
            $details[] = "branch {$e($payload->branch)}";
        }
        if ($payload->commitShort !== null) {
            $details[] = "commit <code>{$e($payload->commitShort)}</code>".
                ($payload->commitMessage !== null ? " {$e($payload->commitMessage)}" : '');
        }
        if ($payload->durationHuman() !== null) {
            $details[] = "took {$e($payload->durationHuman())}";
        }
        if ($details !== []) {
            $lines[] = implode(' · ', $details);
        }

        if ($payload->failureExcerpt !== null) {
            $lines[] = "<pre>{$e($payload->failureExcerpt)}</pre>";
        }

        $lines[] = "<a href=\"{$e($payload->url)}\">View deployment</a>";

        return implode("\n", $lines);
    }
}
