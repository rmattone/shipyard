<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()->organizations()->get()
            ->map(fn (Organization $organization) => $this->payload($organization));

        return response()->json($organizations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $organization = Organization::create($validated);

        $request->user()->organizations()->attach($organization, [
            'role' => Organization::ROLE_OWNER,
        ]);

        // Creating an organization switches you into it.
        $request->user()->forceFill(['current_organization_id' => $organization->id])->save();

        return response()->json(
            $this->payload($organization, Organization::ROLE_OWNER),
            201,
        );
    }

    public function current(Request $request): JsonResponse
    {
        $organization = $request->user()->currentOrganization;

        if (! $organization) {
            return response()->json(['message' => 'No current organization.'], 404);
        }

        return response()->json(
            $this->payload($organization, $request->user()->roleIn($organization)),
        );
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $organization->update($validated);

        return response()->json($this->payload($organization, Organization::ROLE_OWNER));
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        if ($organization->servers()->withoutGlobalScopes()->exists()
            || $organization->gitProviders()->withoutGlobalScopes()->exists()
            || $organization->notificationChannels()->withoutGlobalScopes()->exists()) {
            return response()->json([
                'message' => 'Remove all servers, git providers and notification channels before deleting the organization.',
            ], 422);
        }

        // users.current_organization_id nulls via FK; memberships and
        // invitations cascade.
        $organization->delete();

        return response()->json(['message' => 'Organization deleted.']);
    }

    public function switch(Request $request, Organization $organization): JsonResponse
    {
        $role = $request->user()->roleIn($organization);

        if (! $role) {
            // 404, not 403: don't confirm the organization exists.
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $request->user()->forceFill(['current_organization_id' => $organization->id])->save();

        return response()->json($this->payload($organization, $role));
    }

    private function payload(Organization $organization, ?string $role = null): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'role' => $role ?? $organization->pivot?->role,
            'created_at' => $organization->created_at,
        ];
    }

    private function authorizeOwner(Request $request, Organization $organization): void
    {
        $role = $request->user()->roleIn($organization);

        // Non-members get a 404 (no existence leak); members without the
        // owner role get a 403.
        abort_if($role === null, 404, 'Organization not found.');
        abort_if($role !== Organization::ROLE_OWNER, 403, 'This action requires the owner role.');
    }
}
