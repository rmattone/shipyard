<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
