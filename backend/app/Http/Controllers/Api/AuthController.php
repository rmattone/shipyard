<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->issueAuthToken();

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->update($validated);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        // The hashed cast takes care of hashing the plaintext.
        $user->update(['password' => $request->password]);

        $this->deleteOtherTokens($request);

        return response()->json([
            'message' => 'Password updated. Other sessions have been signed out.',
        ]);
    }

    public function logoutOthers(Request $request): JsonResponse
    {
        $this->deleteOtherTokens($request);

        return response()->json([
            'message' => 'Other sessions have been signed out.',
        ]);
    }

    /**
     * Revoke every token except the one authenticating this request. The
     * guard matters: under cookie auth currentAccessToken() is a
     * TransientToken with no id, in which case all rows go.
     */
    private function deleteOtherTokens(Request $request): void
    {
        $current = $request->user()->currentAccessToken();

        $query = $request->user()->tokens();

        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->id);
        }

        $query->delete();
    }

    /**
     * The user plus their organization memberships; the SPA reads
     * organizations/current_organization straight off the user object.
     */
    private function userPayload(User $user): array
    {
        $organizations = $user->organizations()->get();
        $current = $organizations->firstWhere('id', $user->current_organization_id);

        $shape = fn ($organization) => [
            'id' => $organization->id,
            'name' => $organization->name,
            'role' => $organization->pivot->role,
        ];

        return [
            ...$user->toArray(),
            'organizations' => $organizations->map($shape)->values(),
            'current_organization' => $current ? $shape($current) : null,
        ];
    }
}
