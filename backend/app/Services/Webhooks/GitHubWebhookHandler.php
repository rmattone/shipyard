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

        $payload = $request->all();

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
}
