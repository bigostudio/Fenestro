<?php

namespace App\Http\Middleware;

use App\Support\PartnerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        // Partner-login sessions (including a dual-role admin's) never get internal access.
        if (! $user || PartnerAccount::isPartnerSession() || (! $user->isAdmin() && ! $user->isInternalTeam())) {
            return response()->json(['message' => 'Unauthorized. Internal access required.'], 403);
        }

        if ($user->isAdmin() && ! $user->tokenMatchesCurrentPassword()) {
            return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }

        return $next($request);
    }
}
