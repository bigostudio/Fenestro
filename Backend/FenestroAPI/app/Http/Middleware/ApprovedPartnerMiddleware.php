<?php

namespace App\Http\Middleware;

use App\Support\PartnerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApprovedPartnerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        // Partner-login session: judged only as a partner (approved partner side), never by an
        // admin role the same account may also hold.
        if ($user && PartnerAccount::isPartnerSession()) {
            if (! PartnerAccount::isApproved($user)) {
                return response()->json(['message' => 'Unauthorized. Approved referral partner access required.'], 403);
            }

            return $next($request);
        }

        if (! $user || (! $user->isAdmin() && ! $user->isApprovedPartner())) {
            return response()->json(['message' => 'Unauthorized. Approved referral partner access required.'], 403);
        }

        return $next($request);
    }
}
