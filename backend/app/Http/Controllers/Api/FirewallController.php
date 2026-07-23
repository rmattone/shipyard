<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\UfwService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class FirewallController extends Controller
{
    public function show(Server $server, UfwService $ufwService): JsonResponse
    {
        try {
            return response()->json($ufwService->getStatus($server));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function storeRule(Request $request, Server $server, UfwService $ufwService): JsonResponse
    {
        $validated = $this->validateRule($request);

        try {
            $ufwService->addRule($server, $validated['port'], $validated['protocol'], $validated['source'] ?? null);

            return response()->json($ufwService->getStatus($server), 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function destroyRule(Request $request, Server $server, UfwService $ufwService): JsonResponse
    {
        $validated = $this->validateRule($request);

        try {
            $ufwService->deleteRule($server, $validated['port'], $validated['protocol'], $validated['source'] ?? null);

            return response()->json($ufwService->getStatus($server));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function enable(Server $server, UfwService $ufwService): JsonResponse
    {
        try {
            $ufwService->enable($server);

            return response()->json($ufwService->getStatus($server));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function disable(Server $server, UfwService $ufwService): JsonResponse
    {
        try {
            $ufwService->disable($server);

            return response()->json($ufwService->getStatus($server));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function install(Server $server, UfwService $ufwService): JsonResponse
    {
        try {
            $ufwService->install($server);

            return response()->json($ufwService->getStatus($server));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return array{port: string, protocol: string, source: ?string}
     */
    private function validateRule(Request $request): array
    {
        return $request->validate([
            'port' => [
                'required',
                'string',
                'regex:/^\d{1,5}(:\d{1,5})?$/',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $parts = explode(':', $value);

                    foreach ($parts as $part) {
                        $number = (int) $part;

                        if ($number < 1 || $number > 65535) {
                            $fail('The '.$attribute.' must be between 1 and 65535.');

                            return;
                        }
                    }

                    if (count($parts) === 2 && (int) $parts[0] >= (int) $parts[1]) {
                        $fail('The '.$attribute.' range must have a low value less than the high value.');
                    }
                },
            ],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'both'])],
            'source' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

                    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        $fail('The '.$attribute.' must be a valid IPv4 address.');

                        return;
                    }

                    if ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32)) {
                        $fail('The '.$attribute.' CIDR prefix must be between 0 and 32.');
                    }
                },
            ],
        ]);
    }
}
