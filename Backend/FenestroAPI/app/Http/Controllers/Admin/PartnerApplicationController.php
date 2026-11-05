<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendRegistrationAlertJob;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Support\PartnerAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Partner Access → "Register as a referral partner": the signed-in administrator applies
 * to become a referral partner with the SAME account and mobile number. Nothing about the admin
 * account changes (id, phone, role, credentials, permissions, session); a pending partner profile
 * is linked to it and goes through the normal approval by another administrator.
 */
class PartnerApplicationController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'phone'       => $user->phone,
            'name'        => $user->name,
            'eligible'    => in_array($user->role, PartnerAccount::DUAL_ROLE_ROLES, true),
            'application' => $this->present(PartnerAccount::profile($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        if (! in_array($user->role, PartnerAccount::DUAL_ROLE_ROLES, true)) {
            return response()->json(['message' => 'Only administrator accounts can register as a partner here.'], 403);
        }

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|digits:10',
            'company' => 'nullable|string|max:255',
        ]);
        // The partner side uses the account's own registered number — never another one (no second identity).
        if ($data['phone'] !== $user->phone) {
            return response()->json([
                'message' => 'Use the mobile number registered on your admin account.',
                'errors'  => ['phone' => ['Use the mobile number registered on your admin account.']],
            ], 422);
        }

        try {
            $profile = DB::transaction(function () use ($user, $data) {
                // One application per account (the unique index on user_id is the final guard).
                if (PartnerProfile::where('user_id', $user->id)->lockForUpdate()->exists()) {
                    return null;
                }

                return PartnerProfile::create([
                    'user_id' => $user->id,
                    'name'    => trim($data['name']),
                    'company' => isset($data['company']) && trim($data['company']) !== '' ? trim($data['company']) : null,
                    'status'  => PartnerProfile::PENDING,
                ]);
            });
        } catch (QueryException) {
            $profile = null; // a concurrent duplicate hit the unique index
        }

        if (! $profile) {
            $existing = PartnerAccount::profile($user);

            return response()->json([
                'message'     => 'You already have a partner application ('.($existing?->status ?? 'pending').').',
                'application' => $this->present($existing),
            ], 409);
        }

        SendRegistrationAlertJob::dispatch($user); // same alert to the office as a new partner registration

        return response()->json([
            'message'     => 'Partner application submitted. Another administrator must approve it before you can sign in at Partner Login.',
            'application' => $this->present($profile),
        ], 201);
    }

    private function present(?PartnerProfile $p): ?array
    {
        return $p ? [
            'status'       => $p->status,
            'name'         => $p->name,
            'company'      => $p->company,
            'submitted_at' => $p->created_at?->toIso8601ZuluString(),
            'decided_at'   => $p->decided_at?->toIso8601ZuluString(),
        ] : null;
    }
}
