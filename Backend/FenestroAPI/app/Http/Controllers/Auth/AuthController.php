<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendRegistrationAlertJob;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\PartnerCommonCode;
use App\Support\PartnerAccount;
use App\Support\PartnerOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * One answer for a number with no account AND for a number whose account is not a referral
     * partner (e.g. an admin): OTP login is for partners only, and the same text for both never
     * reveals which numbers belong to staff. It tells staff where they do sign in.
     */
    private const NOT_A_PARTNER = 'This number is not registered as a referral partner. Please register first. Prestige Ventures staff sign in at Admin Login (/admin/login).';

    private const OTP_TTL_MINUTES = PartnerOtp::TTL_MINUTES;

    private const TEMP_CODE_PROMPT = 'Enter the 6-digit partner access code shared by Prestige Ventures. No WhatsApp code is sent.';

    private const OTP_UNAVAILABLE = 'We could not send a login code to your WhatsApp right now. Please try again in a few minutes or contact Prestige Ventures support.';

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|digits:10|unique:users,phone',
            'company' => 'nullable|string|max:255',
        ], [
            // One account per number (partner or staff). Same text whichever kind of account has it.
            'phone.unique' => 'This number is already registered. Partners: use Login to sign in with an OTP. Prestige Ventures staff: sign in at Admin Login (/admin/login); to also become a referral partner, use Settings → Partner Access there.',
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

    public function sendOtp(Request $request, WhatsAppService $whatsApp): JsonResponse
    {
        $request->validate([
            'phone' => 'required|digits:10',
        ]);

        // OTP login is for referral partners only: ordinary partners, and staff accounts that hold
        // a partner profile (dual role). Admins sign in to the admin portal with email + password;
        // a staff number without a partner profile gets the same answer as an unknown number, so
        // this endpoint can't be used to find admin phones.
        $user = PartnerAccount::forPhone((string) $request->phone);

        if (! $user) {
            return response()->json(['message' => self::NOT_A_PARTNER], 404);
        }

        // Only approved partners get a code (same answers as verify-otp).
        if ($denied = $this->partnerStatusDenied($user)) {
            return $denied;
        }

        // Common partner access code active: every approved partner signs in with it (nothing is
        // generated or sent; the code is checked in verifyOtp).
        if (PartnerCommonCode::active()) {
            return response()->json([
                'message'  => self::TEMP_CODE_PROMPT,
                'delivery' => 'temporary_code',
            ]);
        }

        $otp = PartnerOtp::generate();

        // WhatsApp OTP delivery not set up (no credentials/template, or sending switched off):
        // never claim a code was sent. Local/test servers keep the development code (see verifyOtp);
        // everywhere else the request fails honestly and no code is stored.
        if (! $whatsApp->canSendOtp()) {
            if (! app()->environment(['local', 'testing'])) {
                Log::warning('Partner login code not sent: WhatsApp OTP delivery is not set up', ['to' => WhatsAppService::mask($user->phone)]);

                return response()->json(['message' => self::OTP_UNAVAILABLE], 503);
            }
            PartnerOtp::issue($user, $otp); // stored hashed only

            return response()->json([
                'message'  => 'WhatsApp delivery is not set up on this development server, so no code was sent. Use the development code.',
                'delivery' => 'not_sent',
            ]);
        }

        PartnerOtp::issue($user, $otp); // stored hashed only
        $sent = $whatsApp->sendOtpCode($user->phone, $otp); // logs the masked number only, never the code

        if (! $sent['accepted']) {
            PartnerOtp::clear($user); // a code nobody received must not stay valid

            return response()->json(['message' => self::OTP_UNAVAILABLE], 503);
        }

        return response()->json([
            'message'  => 'OTP sent to your WhatsApp number. It expires in '.self::OTP_TTL_MINUTES.' minutes.',
            'delivery' => 'sent',
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|digits:10',
            'otp'   => 'required|digits:6',
        ]);

        // Partners only — an OTP (or the bypass below) must never yield an admin session. For a
        // dual-role account the token is a PARTNER session (ctx claim, see partnerLogin), which
        // admin and internal endpoints refuse.
        $user = PartnerAccount::forPhone((string) $request->phone);

        if (! $user) {
            return response()->json(['message' => self::NOT_A_PARTNER], 404);
        }

        // Only approved partners may sign in (same answers as send-otp).
        if ($denied = $this->partnerStatusDenied($user)) {
            return $denied;
        }

        // Common partner access code (approved partners only — checked above). While it is active it
        // is the only way in: no development shortcut, no WhatsApp code. It opens a PARTNER session
        // that remembers the code id, so changing or revoking the code ends it (partner_session).
        $common = PartnerCommonCode::active();
        if ($common) {
            $result = PartnerCommonCode::verify($common, $user, (string) $request->otp);
            if ($result === PartnerCommonCode::LOCKED) {
                return response()->json(['message' => 'Too many incorrect codes for this number. Try again in '.max(1, PartnerCommonCode::lockedMinutes($user)).' minutes, or contact Prestige Ventures support.'], 429);
            }
            if ($result === PartnerCommonCode::REVOKED) {
                return response()->json(['message' => 'The partner access code has been withdrawn after too many incorrect attempts. Please contact Prestige Ventures support for a new code.'], 422);
            }
            if ($result !== PartnerCommonCode::OK) {
                return response()->json(['message' => 'Invalid code.'], 422);
            }

            return $this->partnerLogin($user, [PartnerCommonCode::CLAIM => (int) $common->id]);
        }

        // Development shortcut: the fixed code works on local/test servers only — never in production.
        if (app()->environment(['local', 'testing']) && $request->otp === '123456') {
            PartnerOtp::clear($user);
        } else {
            // Hashed, single-use, expiring code; MAX_ATTEMPTS wrong guesses invalidate it (atomic — see PartnerOtp).
            $result = PartnerOtp::verify($user, (string) $request->otp);
            if ($result === PartnerOtp::LOCKED) {
                return response()->json(['message' => 'Too many incorrect codes. This code no longer works — please request a new one.'], 422);
            }
            if ($result !== PartnerOtp::OK) {
                return response()->json(['message' => 'Invalid or expired OTP.'], 422);
            }
        }

        return $this->partnerLogin($user);
    }

    /** 403 for a pending / rejected partner (ordinary or dual role), null when approved. */
    private function partnerStatusDenied(User $user): ?JsonResponse
    {
        return match (PartnerAccount::status($user)) {
            PartnerAccount::APPROVED => null,
            PartnerAccount::PENDING  => response()->json(['message' => 'Your registration is pending approval by the admin.'], 403),
            default                  => response()->json(['message' => 'Your registration has been rejected. Please contact support.'], 403),
        };
    }

    /**
     * Issue a PARTNER session: ctx = partner on every partner token (plus 'pcc' when opened with the
     * common partner access code). For a dual-role account this token never carries admin rights — the admin
     * and internal middleware refuse it — and the user shown is the partner view.
     */
    private function partnerLogin(User $user, array $claims = []): JsonResponse
    {
        PartnerAccount::resetTokenClaims();
        $token = auth('api')->claims([PartnerAccount::CTX_CLAIM => PartnerAccount::CTX_PARTNER] + $claims)->login($user);

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user'       => PartnerAccount::present($user),
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

        // Partner session: the partner view of the account (role referral_partner), even for an admin.
        if (PartnerAccount::isPartnerSession()) {
            return response()->json(['user' => PartnerAccount::present($user)]);
        }

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
        // Common-code id of this session (still the active code — checked by the partner_session middleware).
        $pcc = PartnerAccount::tokenClaim(PartnerCommonCode::CLAIM);
        $claims = $pcc !== null ? [PartnerCommonCode::CLAIM => (int) $pcc] : [];

        if (PartnerAccount::isPartnerSession()) {
            // Partner session (also for a dual-role admin): renewed only while the partner side is
            // approved; the admin password/active checks do not apply to it, and it stays a
            // partner session.
            if (! $user || ! PartnerAccount::isApproved($user)) {
                return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
            }
            $claims = [PartnerAccount::CTX_CLAIM => PartnerAccount::CTX_PARTNER] + $claims;
        } elseif ($user?->role === 'admin' && (! $user->isAdmin() || ! $user->tokenMatchesCurrentPassword())) {
            return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }
        // (else: a partner token issued before session marking keeps only its common-code id, if any.)

        // refresh() keeps no custom claims unless given again; start clean, then carry exactly these.
        PartnerAccount::resetTokenClaims();
        auth('api')->claims($claims);
        $token = auth('api')->refresh();

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ]);
    }
}
