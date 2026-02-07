<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SSHKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SSHKeyController extends Controller
{
    public function __construct(
        private SSHKeyService $sshKeyService
    ) {}

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'comment' => 'nullable|string|max:255',
        ]);

        $comment = $request->input('comment', 'shipyard@' . gethostname());

        $keys = $this->sshKeyService->generateKeyPair($comment);

        return response()->json($keys);
    }
}
