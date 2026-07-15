<?php

namespace App\Services\Webhooks;

use App\Models\Application;
use Illuminate\Http\Request;
use RuntimeException;

class GitLabWebhookHandler implements WebhookHandler
{
    public function validate(Request $request, Application $app): bool
    {
        $token = (string) $request->header('X-Gitlab-Token');

        return $token !== '' && hash_equals((string) $app->webhook_secret, $token);
    }

    public function parse(Request $request): array
    {
        $payload = $request->all();

        if (($payload['object_kind'] ?? null) !== 'push') {
            throw new RuntimeException('Unsupported GitLab webhook event; only push events trigger deployments.');
        }

        $branch = str_replace('refs/heads/', '', $payload['ref'] ?? '');
        $commits = $payload['commits'] ?? [];
        $latestCommit = ! empty($commits) ? end($commits) : null;

        return [
            'branch' => $branch !== '' ? $branch : null,
            'commit_hash' => $payload['after'] ?? $payload['checkout_sha'] ?? null,
            'commit_message' => $latestCommit['message'] ?? null,
            'author' => $latestCommit['author']['name'] ?? null,
            'repository' => $payload['repository']['url'] ?? null,
        ];
    }
}
