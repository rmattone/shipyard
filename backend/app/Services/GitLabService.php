<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Http\Request;
use RuntimeException;

class GitLabService
{
    public function validateWebhook(Request $request, Application $app): bool
    {
        $token = $request->header('X-Gitlab-Token');

        return $token === $app->webhook_secret;
    }

    public function parseWebhookPayload(Request $request): array
    {
        $payload = $request->all();

        if (!isset($payload['object_kind']) || $payload['object_kind'] !== 'push') {
            throw new RuntimeException('Invalid webhook event type');
        }

        $ref = $payload['ref'] ?? '';
        $branch = str_replace('refs/heads/', '', $ref);

        $commits = $payload['commits'] ?? [];
        $latestCommit = !empty($commits) ? end($commits) : null;

        return [
            'branch' => $branch,
            'commit_hash' => $payload['after'] ?? $payload['checkout_sha'] ?? null,
            'commit_message' => $latestCommit['message'] ?? null,
            'author' => $latestCommit['author']['name'] ?? null,
            'repository' => $payload['repository']['url'] ?? null,
        ];
    }

    public function shouldTriggerDeploy(Application $app, array $webhookData): bool
    {
        return $webhookData['branch'] === $app->branch;
    }
}
