<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannel;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationChannelController extends Controller
{
    private const SECRET_KEYS = ['webhook_url', 'bot_token', 'api_key'];

    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(NotificationChannel::orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:'.implode(',', NotificationChannel::TYPES)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['distinct', 'in:'.implode(',', NotificationChannel::EVENTS)],
            'is_enabled' => ['nullable', 'boolean'],
            'config' => ['required', 'array'],
            ...$this->configRulesFor($type),
        ]);

        $channel = NotificationChannel::create([
            ...$validated,
            'is_enabled' => $validated['is_enabled'] ?? true,
        ]);

        return response()->json($channel, 201);
    }

    public function show(NotificationChannel $notificationChannel): JsonResponse
    {
        return response()->json($notificationChannel);
    }

    public function update(Request $request, NotificationChannel $notificationChannel): JsonResponse
    {
        // Type is immutable: changing transport means delete + recreate,
        // which keeps the secret-merge semantics unambiguous.
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'events' => ['sometimes', 'required', 'array', 'min:1'],
            'events.*' => ['distinct', 'in:'.implode(',', NotificationChannel::EVENTS)],
            'is_enabled' => ['nullable', 'boolean'],
            'config' => ['sometimes', 'array'],
            ...$this->configRulesFor($notificationChannel->type, forUpdate: true),
        ]);

        if (array_key_exists('config', $validated)) {
            // Blank/absent secrets keep the stored value (GitProvider
            // precedent); non-secret keys are replaced.
            $config = array_filter(
                $validated['config'],
                fn ($value, $key) => ! in_array($key, self::SECRET_KEYS, true) || filled($value),
                ARRAY_FILTER_USE_BOTH
            );
            $validated['config'] = array_merge($notificationChannel->config ?? [], $config);
        }

        $notificationChannel->update($validated);

        return response()->json($notificationChannel);
    }

    public function destroy(NotificationChannel $notificationChannel): JsonResponse
    {
        $notificationChannel->delete();

        return response()->json(null, 204);
    }

    public function test(NotificationChannel $notificationChannel): JsonResponse
    {
        try {
            $this->notificationService->sendTest($notificationChannel);

            return response()->json([
                'success' => true,
                'message' => 'Test notification sent.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * The per-type config rules are the injection/misconfiguration defense
     * for values that end up in outbound requests. On update, secrets are
     * optional (blank keeps the stored value).
     */
    private function configRulesFor(?string $type, bool $forUpdate = false): array
    {
        $secret = fn (array $rules) => $forUpdate
            ? ['nullable', ...array_diff($rules, ['required'])]
            : $rules;

        return match ($type) {
            NotificationChannel::TYPE_DISCORD => [
                'config.webhook_url' => $secret([
                    'required', 'string', 'url', 'max:500',
                    'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/',
                ]),
            ],
            NotificationChannel::TYPE_TELEGRAM => [
                'config.bot_token' => $secret(['required', 'string', 'regex:/^\d+:[A-Za-z0-9_-]{20,}$/']),
                'config.chat_id' => ['sometimes', 'required', 'string', 'regex:/^(@[A-Za-z0-9_]{4,}|-?\d+)$/'],
            ],
            NotificationChannel::TYPE_EMAIL => [
                'config.api_key' => $secret(['required', 'string', 'starts_with:re_', 'max:255']),
                'config.from_email' => ['sometimes', 'required', 'email'],
                'config.to_email' => ['sometimes', 'required', 'email'],
            ],
            default => [],
        };
    }
}
