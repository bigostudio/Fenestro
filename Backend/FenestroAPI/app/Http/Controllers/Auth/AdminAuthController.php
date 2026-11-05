<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PartnerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Admin email/password login.
 *
 * There is intentionally no public registration or self-service password
 * reset here. Admin accounts are provisioned on the server with
 * `php artisan admin:create` and passwords are reset with
 * `php artisan admin:reset-password`.
 */
class AdminAuthController extends Controller
{
    private const INVALID_CREDENTIALS = 'Invalid email or password.';

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        $user = User::where('email', $request->email)->first();

        // Always run a hash check so response time doesn't reveal whether the email exists.
        $passwordOk = Hash::check(
            $request->password,
            $user?->password ?? '$2y$12$7yB7n2r8m1fWQvZ3k8b8A.4qkKz5hM3mJk0s1lL9i2kQy6l0uY1rW'
        );

        // Same message for unknown email, wrong password, non-admin and
        // deactivated accounts, so the endpoint can't be used to probe accounts.
        if (! $user || ! $passwordOk || ! $user->isAdmin() || (int) $user->is_active !== 1) {
            return response()->json(['message' => self::INVALID_CREDENTIALS], 401);
        }

        // An admin token never carries partner-session claims (see PartnerAccount::resetTokenClaims).
        PartnerAccount::resetTokenClaims();
        $token = auth('api')->login($user);

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => $this->userPayload($user),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role'  => $user->role,
        ];
    }
}
