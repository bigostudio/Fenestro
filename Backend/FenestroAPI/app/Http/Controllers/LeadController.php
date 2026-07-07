<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitLeadRequest;
use App\Jobs\SendLeadAlertJob;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function store(SubmitLeadRequest $request): JsonResponse
    {
        $lead = Lead::create([
            'referral_partner_id' => auth()->id(),
            'client_name'         => $request->client_name,
            'client_phone'        => $request->client_phone,
            'type'                => $request->type,
            'notes'               => $request->notes,
        ]);

        SendLeadAlertJob::dispatch();

        return response()->json([
            'message' => 'Lead submitted successfully.',
            'data'    => $lead,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();

        $query = Lead::with(['partner:id,name,phone,company', 'measurement:id,lead_id']);

        if ($user->isAdmin() || $user->isInternalTeam()) {
            // no filter — sees all leads
        } elseif ($user->isApprovedPartner()) {
            $query->where('referral_partner_id', $user->id);
        } else {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $leads = $query->latest()->paginate(15);

        $leads->getCollection()->transform(fn ($lead) => [
            'id'                  => $lead->id,
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
