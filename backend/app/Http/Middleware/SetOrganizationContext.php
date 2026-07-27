<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's current organization (and their role
 * in it) into CurrentOrganization, activating tenant scoping for the
 * rest of the request. Must run after auth:sanctum.
 */
class SetOrganizationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $membership = $user->organizations()
            ->whereKey($user->current_organization_id)
            ->first();

        // Stale or missing current org (e.g. removed from it, or it was
        // deleted): fall back to the first membership and persist.
        if (! $membership) {
            $membership = $user->organizations()->first();

            if ($membership) {
                $user->forceFill(['current_organization_id' => $membership->id])->save();
            }
        }

        if (! $membership) {
            return response()->json([
                'message' => 'You do not belong to any organization.',
            ], 403);
        }

        CurrentOrganization::set($membership, $membership->pivot->role);

        return $next($request);
    }
}
