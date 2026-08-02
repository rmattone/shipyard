<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Public endpoints for the invitation accept flow: the token in the URL
 * is the shared secret. Both routes are throttled (see routes/api.php).
 */
class InvitationController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invitation = OrganizationInvitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        return response()->json([
            'organization' => $invitation->organization->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expired' => $invitation->isExpired(),
            'existing_user' => User::where('email', $invitation->email)->exists(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = OrganizationInvitation::where('token', $token)->first();

        if (! $invitation) {
            return response()->json(['message' => 'Invitation not found.'], 404);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'This invitation has expired.'], 410);
        }

        $organization = $invitation->organization;
        $existingUser = User::where('email', $invitation->email)->first();

        if ($existingUser) {
            // The invited address already has an account: only that
            // authenticated user may accept.
            $authenticated = auth('sanctum')->user();

            if (! $authenticated || ! $authenticated->is($existingUser)) {
                return response()->json([
                    'message' => 'Log in with the invited email address to accept this invitation.',
                ], 403);
            }

            if (! $existingUser->belongsToOrganization($organization)) {
                $existingUser->organizations()->attach($organization, ['role' => $invitation->role]);
            }

            $existingUser->forceFill(['current_organization_id' => $organization->id])->save();
            $invitation->delete();

            return response()->json([
                'message' => "You joined {$organization->name}.",
                'organization' => ['id' => $organization->id, 'name' => $organization->name],
            ]);
        }

        // New account: register through the invitation.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $invitation->email,
            'password' => Hash::make($validated['password']),
        ]);

        $user->organizations()->attach($organization, ['role' => $invitation->role]);
        $user->forceFill(['current_organization_id' => $organization->id])->save();
        $invitation->delete();

        // Mirror the login response so the frontend can sign in directly.
        return response()->json([
            'user' => $user,
            'token' => $user->issueAuthToken(),
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
        ], 201);
    }
}
