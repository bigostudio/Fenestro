<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitLeadRequest;
use App\Jobs\SendLeadAlertJob;
use App\Models\Lead;
use App\Support\LeadWorkflow;
use App\Support\PartnerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(SubmitLeadRequest $request): JsonResponse
    {
        // Same workflow as the office: Lead ID PV-LD-YYMM-NNN, status Lead Received.
        $lead = LeadWorkflow::create([
            'referral_partner_id' => auth()->id(),
            'client_name'         => $request->client_name,
            'client_phone'        => $request->client_phone,
            'type'                => $request->type,
            'notes'               => $request->notes,
        ], auth()->user());

        SendLeadAlertJob::dispatch();

        return response()->json([
            'message' => 'Lead submitted successfully.',
            'data'    => $lead,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = Lead::with(['partner:id,name,phone,company', 'measurement:id,lead_id,status']);

        if (PartnerAccount::isPartnerSession()) {
            // Partner-login session (also a dual-role admin's): own referred leads only.
            if (! PartnerAccount::isApproved($user)) {
                return response()->json(['message' => 'Unauthorized.'], 403);
            }
            $query->where('referral_partner_id', $user->id);
        } elseif ($user->isAdmin() || $user->isInternalTeam()) {
            // no filter — sees all leads
        } elseif ($user->isApprovedPartner()) {
            $query->where('referral_partner_id', $user->id);
        } else {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min(500, max(1, (int) $request->input('per_page', 15)));
        $leads = $query->latest()->paginate($perPage);

        $leads->getCollection()->transform(fn ($lead) => [
            'id'                  => $lead->id,
            'lead_no'             => $lead->lead_no,
            'status_code'         => $lead->status_code,
            'status_label'        => LeadWorkflow::label($lead->status_code),
            'partner_status'      => LeadWorkflow::partnerStatus($lead),
            'lifecycle'           => $lead->lifecycle,
            'measurement_status'  => $lead->measurement?->status,
            'referral_partner_id' => $lead->referral_partner_id,
            'client_name'         => $lead->client_name,
            'client_phone'        => $lead->client_phone,
            'type'                => $lead->type,
            'notes'               => $lead->notes,
            'status'              => $lead->status,
            'measurement_id'      => $lead->measurement?->id,
            'partner'             => $lead->partner,
            'created_at'          => $lead->created_at,
            'updated_at'          => $lead->updated_at,
        ]);

        return response()->json($leads);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->delete();

        return response()->json([
            'message' => 'Lead and all related measurements deleted successfully.',
        ]);
    }
}
