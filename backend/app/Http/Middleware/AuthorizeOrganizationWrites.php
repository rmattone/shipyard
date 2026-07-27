<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The write gate for the org-scoped API group: members read everything
 * but may only write through an explicit allowlist; every other write
 * requires admin or owner. Runs after org.context.
 *
 * MAINTENANCE HAZARD: a new route that members must be able to POST to
 * (e.g. a new "trigger" style action) has to be named and added to
 * MEMBER_ALLOWED_ROUTES consciously, otherwise members get a 403.
 */
class AuthorizeOrganizationWrites
{
    private const MEMBER_ALLOWED_ROUTES = [
        // Members can trigger deployments.
        'applications.deploy',
        // Anyone can switch between their own organizations.
        'organizations.switch',
        // Leaving an organization is self-service (the controller
        // restricts members to removing only themselves).
        'organizations.members.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $role = CurrentOrganization::role();

        if (in_array($role, [Organization::ROLE_ADMIN, Organization::ROLE_OWNER], true)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::MEMBER_ALLOWED_ROUTES, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This action requires the admin role.',
        ], 403);
    }
}
