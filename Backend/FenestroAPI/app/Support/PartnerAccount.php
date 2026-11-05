<?php

namespace App\Support;

use App\Models\PartnerProfile;
use App\Models\User;
use Throwable;

/**
 * Who is a referral partner, and with what status — the single answer used by partner login,
 * temporary access codes, partner sessions and admin approval.
 *
 *  - Ordinary partner: users.role = referral_partner; status from users.is_active
 *    (null = pending, 1 = approved, anything else = rejected) — unchanged.
 *  - Dual role (an administrator who is also a partner): users.role stays 'admin'; the partner
 *    status lives in partner_profiles (one row per user). The admin role, credentials and
 *    permissions are never touched by anything partner-side.
 *
 * Partner sessions carry the JWT claim ctx = 'partner'. Such a token is a partner session even
 * when the account is an administrator: admin/internal middleware refuse it, and partner
 * endpoints treat it strictly as a partner (own leads only).
 */
final class PartnerAccount
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CTX_CLAIM = 'ctx';

    public const CTX_PARTNER = 'partner';

    /** Staff roles that may also hold a partner profile. */
    public const DUAL_ROLE_ROLES = ['admin'];

    /** The account that signs in to the Partner portal with this number, or null. */
    public static function forPhone(string $phone): ?User
    {
        $user = User::where('phone', $phone)->first();

        return $user && self::status($user) !== null ? $user : null;
    }

    /** pending | approved | rejected, or null when the account is not a referral partner at all. */
    public static function status(User $user): ?string
    {
        if ($user->role === 'referral_partner') {
            return $user->is_active === null ? self::PENDING : ((int) $user->is_active === 1 ? self::APPROVED : self::REJECTED);
        }
        if (in_array($user->role, self::DUAL_ROLE_ROLES, true)) {
            return self::profile($user)?->status;
        }

        return null;
    }

    public static function isApproved(User $user): bool
    {
        return self::status($user) === self::APPROVED;
    }

    /** A staff account with a partner profile (as opposed to an ordinary partner). */
    public static function isDualRole(User $user): bool
    {
        return in_array($user->role, self::DUAL_ROLE_ROLES, true) && self::profile($user) !== null;
    }

    public static function profile(User $user): ?PartnerProfile
    {
        return PartnerProfile::where('user_id', $user->id)->first();
    }

    /** Partner-portal display: dual-role accounts show their profile's name/company. */
    public static function present(User $user): array
    {
        $p = self::isDualRole($user) ? self::profile($user) : null;

        return [
            'id'        => $user->id,
            'name'      => $p?->name ?? $user->name,
            'phone'     => $user->phone,
            'company'   => $p ? $p->company : $user->company,
            'role'      => 'referral_partner', // the role of THIS session, never the admin role
            'is_active' => self::isApproved($user) ? 1 : (self::status($user) === self::PENDING ? null : 0),
        ];
    }

    /**
     * Call before issuing or refreshing ANY token. tymon/jwt-auth's payload factory is a singleton
     * that keeps the claims of the previous token it built in the same app instance (make() does
     * not reset them), so in a long-lived process an admin token could otherwise inherit a
     * partner token's ctx/pac claims. Token claims decide authorization here, so issuance must
     * not depend on what was built before.
     */
    public static function resetTokenClaims(): void
    {
        app('tymon.jwt.payload.factory')->emptyClaims(); // claims left by the last token built or decoded
        auth('api')->claims([]);                         // inline claims left on the JWT instance by a previous claims()->login()
    }

    /**
     * One claim of the current request's token, read from the signature-verified raw token —
     * NOT from auth('api')->payload(): tymon's shared payload factory also keeps the claims of
     * the previously DECODED token, so in a long-lived process (and in tests) an admin token
     * decoded after a partner token would appear to carry ctx/pac. Null when absent / no token.
     */
    public static function tokenClaim(string $name): mixed
    {
        try {
            $token = auth('api')->getToken();
            if (! $token) {
                return null;
            }
            $claims = app('tymon.jwt.provider.jwt')->decode((string) $token);

            return $claims[$name] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** True when the current request's token was issued by Partner login. */
    public static function isPartnerSession(): bool
    {
        return auth('api')->check() && self::tokenClaim(self::CTX_CLAIM) === self::CTX_PARTNER;
    }
}
