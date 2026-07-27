<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationMemberController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeMember($request, $organization);

        $members = $organization->users()->orderBy('name')->get()
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->pivot->role,
            ]);

        return response()->json($members);
    }

    public function update(Request $request, Organization $organization, User $user): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        $validated = $request->validate([
            'role' => ['required', Rule::in(Organization::ROLES)],
        ]);

        $currentRole = $user->roleIn($organization);

        if (! $currentRole) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        if ($currentRole === Organization::ROLE_OWNER
            && $validated['role'] !== Organization::ROLE_OWNER
            && $this->isLastOwner($organization)) {
            return response()->json([
                'message' => 'An organization must keep at least one owner.',
            ], 422);
        }

        $organization->users()->updateExistingPivot($user->id, ['role' => $validated['role']]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $validated['role'],
        ]);
    }

    public function destroy(Request $request, Organization $organization, User $user): JsonResponse
    {
        $isSelf = $request->user()->is($user);

        // Owners can remove anyone; everyone can remove themselves (leave).
        if ($isSelf) {
            $this->authorizeMember($request, $organization);
        } else {
            $this->authorizeOwner($request, $organization);
        }

        $role = $user->roleIn($organization);

        if (! $role) {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        if ($role === Organization::ROLE_OWNER && $this->isLastOwner($organization)) {
            return response()->json([
                'message' => 'An organization must keep at least one owner. Transfer ownership first.',
            ], 422);
        }

        $organization->users()->detach($user->id);

        // Point their session at another organization (or none).
        if ($user->current_organization_id === $organization->id) {
            $user->forceFill([
                'current_organization_id' => $user->organizations()->first()?->id,
            ])->save();
        }

        return response()->json(['message' => $isSelf ? 'You left the organization.' : 'Member removed.']);
    }

    private function isLastOwner(Organization $organization): bool
    {
        return $organization->owners()->count() <= 1;
    }

    private function authorizeMember(Request $request, Organization $organization): void
    {
        abort_if($request->user()->roleIn($organization) === null, 404, 'Organization not found.');
    }

    private function authorizeOwner(Request $request, Organization $organization): void
    {
        $role = $request->user()->roleIn($organization);

        abort_if($role === null, 404, 'Organization not found.');
        abort_if($role !== Organization::ROLE_OWNER, 403, 'This action requires the owner role.');
    }
}
