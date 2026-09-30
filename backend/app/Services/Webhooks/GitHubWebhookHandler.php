<?php

namespace App\Services\Webhooks;

use App\Models\Application;
use Illuminate\Http\Request;
use RuntimeException;

class GitHubWebhookHandler implements WebhookHandler
{
    public function validate(Request $request, Application $app): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $app->webhook_secret);

        return hash_equals($expected, $signature);
    }

    public function parse(Request $request): array
    {
        // Ping events are sent when the webhook is first configured
        if ($request->header('X-GitHub-Event') === 'ping') {
            throw new RuntimeException('GitHub ping event received; webhook configured successfully.');
        }

        if ($request->header('X-GitHub-Event') !== 'push') {
            throw new RuntimeException('Unsupported GitHub webhook event; only push events trigger deployments.');
        }

        $payload = $this->payload($request);

        $branch = str_replace('refs/heads/', '', $payload['ref'] ?? '');
        $headCommit = $payload['head_commit'] ?? null;

        return [
            'branch' => $branch !== '' ? $branch : null,
            'commit_hash' => $payload['after'] ?? $headCommit['id'] ?? null,
            'commit_message' => $headCommit['message'] ?? null,
            'author' => $headCommit['author']['name'] ?? null,
            'repository' => $payload['repository']['clone_url'] ?? null,
        ];
    }

    /**
     * GitHub webhooks can be configured with either content type:
     * application/json sends the payload as the raw body, while
     * application/x-www-form-urlencoded wraps it in a "payload" form field.
     */
    private function payload(Request $request): array
    {
        $formPayload = $request->input('payload');

        if (is_string($formPayload)) {
            return json_decode($formPayload, true) ?? [];
        }

        return $request->all();
    }
}
