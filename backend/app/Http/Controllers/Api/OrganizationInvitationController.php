<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationInvitationController extends Controller
{
    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        return response()->json(
            $organization->invitations()->orderByDesc('created_at')->get(),
        );
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            // Ownership is granted by promoting a member, never by invite.
            'role' => ['required', Rule::in([Organization::ROLE_ADMIN, Organization::ROLE_MEMBER])],
        ]);

        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser && $existingUser->belongsToOrganization($organization)) {
            return response()->json([
                'message' => 'That user is already a member of this organization.',
            ], 422);
        }

        // Re-inviting replaces the pending invitation (fresh token/expiry).
        $invitation = $organization->invitations()->updateOrCreate(
            ['email' => $validated['email']],
            [
                'role' => $validated['role'],
                'token' => OrganizationInvitation::generateToken(),
                'expires_at' => now()->addDays(OrganizationInvitation::EXPIRES_AFTER_DAYS),
            ],
        );

        // No mailer is configured; the owner shares the accept URL. The
        // token is normally hidden, expose it only on this response.
        return response()->json([
            ...$invitation->makeVisible('token')->toArray(),
            'accept_url' => rtrim(config('app.frontend_url', config('app.url')), '/')
                ."/app/invitations/accept?token={$invitation->token}",
        ], 201);
    }

    public function destroy(Request $request, Organization $organization, OrganizationInvitation $invitation): JsonResponse
    {
        $this->authorizeOwner($request, $organization);

        if ($invitation->organization_id !== $organization->id) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        $invitation->delete();

        return response()->json(['message' => 'Invitation revoked.']);
    }

    private function authorizeOwner(Request $request, Organization $organization): void
    {
        $role = $request->user()->roleIn($organization);

        abort_if($role === null, 404, 'Organization not found.');
        abort_if($role !== Organization::ROLE_OWNER, 403, 'This action requires the owner role.');
    }
}
