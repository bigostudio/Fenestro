<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApprovedPartnerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if (! $user || (! $user->isAdmin() && ! $user->isApprovedPartner())) {
            return response()->json(['message' => 'Unauthorized. Approved referral partner access required.'], 403);
        }

        return $next($request);
    }
}
