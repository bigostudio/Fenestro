<?php

namespace App\Http\Middleware;

use App\Support\PartnerAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        // A Partner-login session never has admin access, even when the account is also an
        // administrator (dual role): admin pages need an Admin-login session.
        if (PartnerAccount::isPartnerSession()) {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        // Role and active flag are read from the database on every request,
        // so demoting or deactivating an admin takes effect immediately.
        if (! $user || ! $user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        // Tokens issued before the admin's password last changed are refused.
        if (! $user->tokenMatchesCurrentPassword()) {
            return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }

        return $next($request);
    }
}
