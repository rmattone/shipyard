<?php

namespace App\Services\Webhooks;

use App\Models\Application;

class WebhookHandlerFactory
{
    /**
     * Resolve the webhook handler for an application based on its git
     * provider. Applications without a provider (SSH-only) default to the
     * GitLab token scheme, preserving the original behavior.
     */
    public function for(Application $app): WebhookHandler
    {
        return match ($app->gitProvider?->type) {
            'github' => new GitHubWebhookHandler,
            'bitbucket' => new BitbucketWebhookHandler,
            default => new GitLabWebhookHandler,
        };
    }
}
