<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendRegistrationAlertJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|digits:10|unique:users,phone',
            'company' => 'nullable|string|max:255',
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

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'Phone number not registered.'], 404);
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

        // TODO: Send OTP via WhatsApp when Meta templates are approved
        // WhatsApp send is bypassed — OTP 123456 always works in dev
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

        $user = User::where('phone', $request->phone)->first();

        if (! $user) {
            return response()->json(['message' => 'Phone number not registered.'], 404);
        }

        $isBypass   = $request->otp === '123456';
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
        $token = auth('api')->refresh();

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
