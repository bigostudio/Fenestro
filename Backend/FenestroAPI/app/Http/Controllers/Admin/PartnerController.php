<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPartnerApprovedJob;
use App\Jobs\SendPartnerRejectedJob;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Support\PartnerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PartnerController extends Controller
{
    public function approved(): JsonResponse
    {
        $users = User::where('role', 'referral_partner')
            ->where('is_active', 1)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'phone', 'company', 'is_active', 'created_at'])
            ->each(fn (User $u) => $u->setAttribute('account_type', 'partner'));

        $rows = $users->concat($this->staffPartners(PartnerProfile::APPROVED))->sortByDesc('created_at')->values();

        return response()->json([
            'data'  => $rows,
            'total' => $rows->count(),
        ]);
    }

    public function pending(): JsonResponse
    {
        $users = User::where('role', 'referral_partner')
            ->whereNull('is_active')
            ->orderBy('created_at', 'desc')
            ->get(['id', 'name', 'phone', 'company', 'is_active', 'created_at'])
            ->each(fn (User $u) => $u->setAttribute('account_type', 'partner'));

        $rows = $users->concat($this->staffPartners(PartnerProfile::PENDING))->sortByDesc('created_at')->values();

        return response()->json([
            'data'  => $rows,
            'total' => $rows->count(),
        ]);
    }

    /**
     * Staff accounts' partner applications in the same row shape as ordinary partners. The id is
     * the (unchanged) user id; name/company/date come from the partner profile; account_type tells
     * the page it is an administrator's application. Never exposes admin fields (email, role).
     */
    private function staffPartners(string $status): Collection
    {
        return PartnerProfile::with('user:id,phone,role')->where('status', $status)->get()
            ->filter(fn (PartnerProfile $p) => $p->user && in_array($p->user->role, PartnerAccount::DUAL_ROLE_ROLES, true))
            ->map(function (PartnerProfile $p) use ($status) {
                $row = new User;
                $row->forceFill([
                    'id'           => $p->user_id,
                    'name'         => $p->name,
                    'phone'        => $p->user->phone,
                    'company'      => $p->company,
                    'role'         => $p->user->role, // hidden below
                    'is_active'    => $status === PartnerProfile::APPROVED ? 1 : null,
                    'created_at'   => $p->created_at,
                ]);
                $row->exists = true;
                $row->setAttribute('account_type', 'admin');

                return $row->makeHidden('role');
            })->values();
    }

    public function updateStatus(User $user, Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => 'required|boolean',
        ]);
        $approve = $request->boolean('is_active');
        $actor = auth('api')->user();

        // An administrator's own partner application: decided by ANOTHER administrator, on the
        // partner profile only — the admin role, credentials and permissions are never touched.
        if ($user->role !== 'referral_partner') {
            $profile = PartnerAccount::isDualRole($user) ? PartnerAccount::profile($user) : null;
            if (! $profile) {
                return response()->json(['message' => 'Partner registration not found.'], 404); // never approve/reject a staff account itself
            }
            if ($actor && $actor->id === $user->id) {
                return response()->json(['message' => 'You cannot approve or reject your own partner application. Another administrator must decide it.'], 403);
            }

            // Atomic: only a still-pending profile changes (two admins deciding at once → one wins).
            $changed = DB::transaction(fn () => PartnerProfile::where('id', $profile->id)->where('status', PartnerProfile::PENDING)->update([
                'status'     => $approve ? PartnerProfile::APPROVED : PartnerProfile::REJECTED,
                'decided_by' => $actor?->id,
                'decided_at' => now(),
            ]));
            if ($changed !== 1) {
                return response()->json(['message' => 'Only pending registrations can be updated.'], 422);
            }
            $this->notifyDecision($user, $approve);

            return response()->json(['message' => 'Partner '.($approve ? 'approved' : 'rejected').' successfully.']);
        }

        if ($user->is_active !== null) {
            return response()->json([
                'message' => 'Only pending registrations can be updated.',
            ], 422);
        }

        $user->update(['is_active' => (int) $approve]);
        $this->notifyDecision($user, $approve);

        return response()->json(['message' => 'Partner '.($approve ? 'approved' : 'rejected').' successfully.']);
    }

    private function notifyDecision(User $user, bool $approved): void
    {
        if ($approved) {
            SendPartnerApprovedJob::dispatch($user);
        } else {
            // (A rejected account can never use the common partner access code: approval is checked at login and on every request.)
            SendPartnerRejectedJob::dispatch($user);
        }
    }
}
