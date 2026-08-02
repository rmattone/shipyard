<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Resolves the user for the SSE routes, which sit outside auth:sanctum
 * because EventSource cannot send headers: the token rides in a ?token=
 * query param instead. Applies the same expiry rules as Sanctum's guard,
 * which these routes bypass: the per-token expires_at column and the
 * global sanctum.expiration window over created_at (the latter is what
 * bounds legacy tokens issued before expires_at was stamped).
 */
final class QueryTokenAuth
{
    public static function resolveUser(Request $request): ?User
    {
        $token = $request->query('token');

        if (is_string($token) && $token !== '') {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && self::isValid($accessToken)) {
                $request->setUserResolver(fn () => $accessToken->tokenable);
            }
        }

        return $request->user();
    }

    private static function isValid(PersonalAccessToken $accessToken): bool
    {
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return false;
        }

        $window = config('sanctum.expiration');

        return ! $window
            || $accessToken->created_at->gt(now()->subMinutes((int) $window));
    }
}
