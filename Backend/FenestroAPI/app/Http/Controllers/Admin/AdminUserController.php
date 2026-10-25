<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Admin account management for the unified admin portal (Settings → Admin users)
 * and the signed-in admin's own profile (Settings → Account).
 * Every route here sits behind `auth:api` + `admin` middleware.
 *
 * Only an existing, active admin can create or change admin accounts. There is
 * no endpoint that changes a user's role, so a partner can never be promoted
 * and nobody can grant themselves admin access.
 */
class AdminUserController extends Controller
{
    private function passwordRule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }

    private function payload(User $user, ?User $viewer = null): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'role'       => $user->role,
            'is_active'  => (int) $user->is_active === 1,
            'created_at' => $user->created_at,
            'is_self'    => $viewer?->id === $user->id,
        ];
    }

    // ── Admin users ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        return response()->json([
            'data' => User::where('role', 'admin')->orderBy('name')->get()
                ->map(fn (User $u) => $this->payload($u, $viewer))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'phone'    => 'required|digits:10|unique:users,phone',
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ], [
            'email.unique' => 'An account with this email already exists.',
            'phone.unique' => 'An account with this phone number already exists.',
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'],
            'password'  => $data['password'], // hashed by the model cast
            'role'      => 'admin',
            'is_active' => 1,
        ]);

        return response()->json(['data' => $this->payload($user, $request->user())], 201);
    }

    /** Activate/deactivate another admin, or set a new password for them. */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'admin', 404);

        $data = $request->validate([
            'is_active' => 'sometimes|boolean',
            'password'  => ['sometimes', 'confirmed', $this->passwordRule()],
        ]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => 'You cannot change your own access here. Use Settings → Account to change your password.',
            ]);
        }

        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'] ? 1 : 0; // deactivation takes effect on their next request
        }
        if (array_key_exists('password', $data)) {
            $user->password = $data['password']; // ends their existing sessions (token fingerprint changes)
        }
        $user->save();

        return response()->json(['data' => $this->payload($user->fresh(), $request->user())]);
    }

    /**
     * Change an administrator's mobile number — any admin's, including your own.
     * Only the phone number changes: name, email, password, status and sessions are untouched.
     */
    public function updatePhone(Request $request, User $user): JsonResponse
    {
        abort_unless($user->role === 'admin', 404);

        $data = $request->validate([
            'phone' => ['required', 'digits:10', Rule::unique('users', 'phone')->ignore($user->id)],
        ], [
            'phone.digits' => 'The mobile number must be exactly 10 digits.',
            'phone.unique' => 'Another account already uses this mobile number.',
        ]);

        $user->phone = $data['phone'];
        $user->save();

        return response()->json(['data' => $this->payload($user->fresh(), $request->user())]);
    }

    // ── Signed-in admin's own account ──────────────────────────────────

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name'  => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json(['message' => 'Profile updated.', 'user' => $this->payload($user->fresh(), $user)]);
    }

    /** Change own password; returns a fresh token because older tokens stop working. */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => 'required|string',
            'password'         => ['required', 'confirmed', $this->passwordRule()],
        ]);

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }

        $user->update(['password' => $request->password]);
        auth('api')->invalidate();                 // retire the token used for this request
        $token = auth('api')->login($user->fresh()); // new token carries the new password fingerprint

        return response()->json([
            'message'    => 'Password updated.',
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
