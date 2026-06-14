<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPartnerApprovedJob;
use App\Jobs\SendPartnerRejectedJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    public function pending(): JsonResponse
    {
        $users = User::where('role', 'referral_partner')
            ->whereNull('is_active')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'phone', 'company', 'is_active', 'created_at']);

        return response()->json([
            'data'  => $users,
            'total' => $users->count(),
        ]);
    }

    public function updateStatus(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);

        if ($user->is_active !== null) {
            return response()->json([
                'message' => 'Only pending registrations can be updated.',
            ], 422);
        }

        $isActive = (int) $request->boolean('is_active');
        $user->update(['is_active' => $isActive]);

        if ($isActive === 1) {
            SendPartnerApprovedJob::dispatch($user);
        } else {
            SendPartnerRejectedJob::dispatch($user);
        }

        $label = $isActive ? 'approved' : 'rejected';

        return response()->json(['message' => "Partner {$label} successfully."]);
    }
}
