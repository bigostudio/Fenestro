<?php

namespace App\Http\Middleware;

use App\Support\PartnerAccount;
use App\Support\PartnerCommonCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessions opened with the common partner access code carry that code's id in the JWT ('pcc'
 * claim). Such a session ends — on the next request, not at token expiry — when the code is
 * changed or revoked, the feature is switched off, or the account is no longer an approved partner.
 *
 * Sessions opened with the retired per-partner codes ('pac' claim) are always treated as ended.
 * Tokens without either claim (normal WhatsApp OTP logins, admins, staff) pass straight through.
 * Alias 'partner_session'; it runs after auth:api and must be on every route a partner token can
 * reach (guarded by tests/Feature/PartnerCommonCodeTest::test_every_partner_reachable_route_checks_the_session).
 */
class EnsureTemporaryAccessIsLive
{
    public const ENDED = 'Your partner access code session has ended. Please sign in again.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();
        if ($user) {
            // Claims read from the raw token (see PartnerAccount::tokenClaim).
            if (PartnerAccount::tokenClaim('pac') !== null) {
                return response()->json(['message' => self::ENDED], 401);
            }
            $pcc = PartnerAccount::tokenClaim(PartnerCommonCode::CLAIM);
            if ($pcc !== null && (! PartnerCommonCode::isLive((int) $pcc) || ! PartnerAccount::isApproved($user))) {
                return response()->json(['message' => self::ENDED], 401);
            }
        }

        return $next($request);
    }
}
