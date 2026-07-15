<?php

namespace App\Services\Webhooks;

use App\Models\Application;
use Illuminate\Http\Request;

interface WebhookHandler
{
    /**
     * Verify the request genuinely came from the configured provider for
     * this application (signature or shared secret).
     */
    public function validate(Request $request, Application $app): bool;

    /**
     * Extract the normalized push data.
     *
     * @return array{branch: ?string, commit_hash: ?string, commit_message: ?string, author: ?string, repository: ?string}
     */
    public function parse(Request $request): array;
}
