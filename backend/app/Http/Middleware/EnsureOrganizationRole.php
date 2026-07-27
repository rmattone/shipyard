<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * org.role:{min} — requires at least the given role in the CURRENT
 * organization (set by org.context). For routes carrying an explicit
 * {organization} parameter the controllers check the role on that
 * organization instead, since it is not necessarily the current one.
 */
class EnsureOrganizationRole
{
    private const HIERARCHY = [
        Organization::ROLE_MEMBER => 1,
        Organization::ROLE_ADMIN => 2,
        Organization::ROLE_OWNER => 3,
    ];

    public function handle(Request $request, Closure $next, string $minimumRole): Response
    {
        $role = CurrentOrganization::role();

        if (! $role || self::HIERARCHY[$role] < self::HIERARCHY[$minimumRole]) {
            return response()->json([
                'message' => "This action requires the {$minimumRole} role.",
            ], 403);
        }

        return $next($request);
    }
}
