<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendRegistrationAlertJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * One answer for a number with no account AND for a number whose account is not a referral
     * partner (e.g. an admin): OTP login is for partners only, and the same text for both never
     * reveals which numbers belong to staff. It tells staff where they do sign in.
     */
    private const NOT_A_PARTNER = 'This number is not registered as a referral partner. Please register first. Prestige Ventures staff sign in at Admin Login (/admin/login).';

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|digits:10|unique:users,phone',
            'company' => 'nullable|string|max:255',
        ], [
            // One account per number (partner or staff). Same text whichever kind of account has it.
            'phone.unique' => 'This number is already registered. Partners: use Login to sign in with an OTP. Prestige Ventures staff: sign in at Admin Login (/admin/login).',
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'phone'     => $validated['phone'],
            'company'   => $validated['company'] ?? null,
            'role'      => 'referral_partner',
            'is_active' => null,
        ]);

        SendRegistrationAlertJob::dispatch($user);

        return response()->json([
            'message' => 'Registration submitted successfully. You will be notified once approved.',
        ], 201);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|digits:10',
        ]);

        // OTP login is for referral partners only. Admins sign in with email +
        // password at /admin/login; other accounts get the same answer as an
        // unknown number so this endpoint can't be used to find admin phones.
        $user = User::where('phone', $request->phone)->where('role', 'referral_partner')->first();

        if (! $user) {
            return response()->json(['message' => self::NOT_A_PARTNER], 404);
        }

        if ($user->role === 'referral_partner') {
            if ($user->is_active === null) {
                return response()->json(['message' => 'Your registration is pending approval by the admin.'], 403);
            }
            if ($user->is_active === 0) {
                return response()->json(['message' => 'Your registration has been rejected. Please contact support.'], 403);
            }
        }

        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $user->update([
            'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        // TODO: Send OTP via WhatsApp when Meta templates are approved.
        // Until then the code is only logged; the fixed code 123456 works on
        // local/test servers only (see verifyOtp), never in production.
        \Illuminate\Support\Facades\Log::info("OTP for {$user->phone}: {$otp}");

        return response()->json([
            'message' => 'OTP sent to your WhatsApp number.',
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|digits:10',
            'otp'   => 'required|digits:6',
        ]);

        // Partners only — an OTP (or the bypass below) must never yield an admin token.
        $user = User::where('phone', $request->phone)->where('role', 'referral_partner')->first();

        if (! $user) {
            return response()->json(['message' => self::NOT_A_PARTNER], 404);
        }

        // Only approved partners may sign in (same answers as send-otp).
        if ($user->is_active === null) {
            return response()->json(['message' => 'Your registration is pending approval by the admin.'], 403);
        }
        if ((int) $user->is_active !== 1) {
            return response()->json(['message' => 'Your registration has been rejected. Please contact support.'], 403);
        }

        // Development shortcut: the fixed code works on local/test servers only — never in production.
        $isBypass   = app()->environment(['local', 'testing']) && $request->otp === '123456';
        $isValidOtp = $user->otp === $request->otp
            && $user->otp_expires_at !== null
            && now()->lessThanOrEqualTo($user->otp_expires_at);

        if (! $isBypass && ! $isValidOtp) {
            return response()->json(['message' => 'Invalid or expired OTP.'], 422);
        }

        $user->update(['otp' => null, 'otp_expires_at' => null]);

        $token = auth('api')->login($user);

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => [
                'id'        => $user->id,
                'name'      => $user->name,
                'phone'     => $user->phone,
                'company'   => $user->company,
                'role'      => $user->role,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'phone'     => $user->phone,
                'company'   => $user->company,
                'role'      => $user->role,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function refresh(): JsonResponse
    {
        // An admin token from before a password change (or for a deactivated
        // admin) can't be renewed, so it dies at its normal expiry.
        $user = auth('api')->user();
        if ($user?->role === 'admin' && (! $user->isAdmin() || ! $user->tokenMatchesCurrentPassword())) {
            return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }

        $token = auth('api')->refresh();

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
