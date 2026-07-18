<?php

namespace App\Services\Webhooks;

use App\Models\Application;
use Illuminate\Http\Request;
use RuntimeException;

class BitbucketWebhookHandler implements WebhookHandler
{
    /**
     * Bitbucket Cloud cannot send custom headers, and its optional secret
     * feature is not universally available, so authenticate with a token
     * carried in the webhook URL (?token=...). When a secret-signed
     * X-Hub-Signature is present (Bitbucket Server / newer setups), verify
     * that instead.
     */
    public function validate(Request $request, Application $app): bool
    {
        $secret = (string) $app->webhook_secret;

        $signature = (string) $request->header('X-Hub-Signature');
        if (str_starts_with($signature, 'sha256=')) {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            return hash_equals($expected, $signature);
        }

        $token = (string) $request->query('token', '');

        return $token !== '' && hash_equals($secret, $token);
    }

    public function parse(Request $request): array
    {
        if ($request->header('X-Event-Key') !== 'repo:push') {
            throw new RuntimeException('Unsupported Bitbucket webhook event; only push events trigger deployments.');
        }

        $payload = $request->all();

        // Bitbucket batches ref updates under push.changes; use the first
        // branch change.
        $change = collect($payload['push']['changes'] ?? [])
            ->first(fn ($change) => ($change['new']['type'] ?? null) === 'branch');

        $target = $change['new']['target'] ?? null;

        return [
            'branch' => $change['new']['name'] ?? null,
            'commit_hash' => $target['hash'] ?? null,
            'commit_message' => $target['message'] ?? null,
            'author' => $target['author']['raw'] ?? null,
            'repository' => $payload['repository']['links']['html']['href'] ?? null,
        ];
    }
}
