<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class ResendEmailDriver implements NotificationDriverInterface
{
    public function send(NotificationChannel $channel, DeploymentNotificationPayload $payload): void
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->withToken($channel->config['api_key'])
            ->post('https://api.resend.com/emails', [
                'from' => $channel->config['from_email'],
                'to' => [$channel->config['to_email']],
                'subject' => "[ShipYard] {$payload->appName}: {$payload->eventLabel()} {$payload->resultLabel()}",
                'html' => $this->html($payload),
                'text' => $this->text($payload),
            ]);

        if ($response->failed()) {
            $message = $response->json('message') ?? "HTTP {$response->status()}";

            throw new NotificationSendException("Resend API error: {$message}");
        }
    }

    private function html(DeploymentNotificationPayload $payload): string
    {
        $e = fn (?string $v) => htmlspecialchars($v ?? '', ENT_QUOTES);
        $color = $payload->succeeded ? '#2ecc71' : '#e74c3c';

        $rows = '';
        foreach ($this->details($payload) as $label => $value) {
            $rows .= "<tr><td style=\"padding:4px 12px 4px 0;color:#666;\">{$e($label)}</td>"
                ."<td style=\"padding:4px 0;\">{$e($value)}</td></tr>";
        }

        $excerpt = '';
        if ($payload->failureExcerpt !== null) {
            $excerpt = "<pre style=\"background:#f5f5f5;padding:12px;border-radius:6px;overflow:auto;\">{$e($payload->failureExcerpt)}</pre>";
        }

        return <<<HTML
<div style="font-family:sans-serif;max-width:600px;">
  <h2 style="color:{$color};">{$e($payload->title())}</h2>
  <table style="border-collapse:collapse;">{$rows}</table>
  {$excerpt}
  <p><a href="{$e($payload->url)}">View deployment</a></p>
</div>
HTML;
    }

    private function text(DeploymentNotificationPayload $payload): string
    {
        $lines = [$payload->title()];

        foreach ($this->details($payload) as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if ($payload->failureExcerpt !== null) {
            $lines[] = '';
            $lines[] = $payload->failureExcerpt;
        }

        $lines[] = '';
        $lines[] = $payload->url;

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    private function details(DeploymentNotificationPayload $payload): array
    {
        $details = [];

        if ($payload->branch !== null) {
            $details['Branch'] = $payload->branch;
        }

        if ($payload->commitShort !== null) {
            $details['Commit'] = $payload->commitShort.($payload->commitMessage !== null ? " {$payload->commitMessage}" : '');
        }

        if ($payload->durationHuman() !== null) {
            $details['Duration'] = $payload->durationHuman();
        }

        return $details;
    }
}
