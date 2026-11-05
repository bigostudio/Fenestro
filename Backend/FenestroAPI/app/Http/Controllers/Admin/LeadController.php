<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Support\LeadConflict;
use App\Support\LeadWorkflow;
use App\Support\PartnerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leads page (/admin/leads). Admin-only (routes/api.php). Statuses, RAG, Lead IDs, closing and
 * archiving are decided by App\Support\LeadWorkflow on the server; nothing is deleted here.
 */
class LeadController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'statuses' => collect(LeadWorkflow::statuses())->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
            'scheduled_statuses' => LeadWorkflow::SCHEDULED,
            'full_status' => LeadWorkflow::FULL,
            'closing_status' => LeadWorkflow::NO_RESPONSE_CLOSE,
            'archiving_statuses' => LeadWorkflow::ABORTS,
            'assignees' => LeadWorkflow::assignees(),
            // Approved referral partners, including administrators approved as partners (dual role,
            // shown with their partner-profile name/company).
            'partners' => User::where(fn ($q) => $q->where('role', 'referral_partner')->where('is_active', 1))
                ->orWhereIn('id', self::approvedStaffPartnerIds())
                ->get(['id', 'name', 'phone', 'company', 'role', 'is_active'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => PartnerAccount::present($u)['name'], 'company' => PartnerAccount::present($u)['company'], 'has_phone' => (bool) preg_match('/^\d{10}$/', (string) $u->phone)])
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'timezone' => LeadWorkflow::timezone(),
            'weekly_off' => config('leads.weekly_off'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tab' => ['nullable', Rule::in(LeadWorkflow::TABS)],
            'q'   => ['nullable', 'string', 'max:100'],
        ]);
        $now = now();
        $leads = LeadWorkflow::query($request->input('tab', 'active'), $request->input('q'))
            ->orderByDesc('id')->limit(1000)->get();

        return response()->json([
            'data'   => $leads->map(fn (Lead $l) => LeadWorkflow::present($l, $now))->values(),
            'counts' => LeadWorkflow::counts(),
        ]);
    }

    public function show(Lead $lead): JsonResponse
    {
        return response()->json(['data' => LeadWorkflow::present($lead->load(['partner', 'measurement']))]);
    }

    public function history(Lead $lead): JsonResponse
    {
        return response()->json(['data' => $lead->events()->orderByDesc('id')->limit(200)->get()->map(fn ($e) => LeadWorkflow::presentEvent($e))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        $lead = LeadWorkflow::create($data, $request->user());

        return response()->json([
            'message' => "Lead {$lead->lead_no} created — status Lead Received.",
            'data'    => LeadWorkflow::present($lead->load(['partner', 'measurement'])),
        ], 201);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        try {
            $before = $lead->only(['status_code', 'scheduled_at', 'lifecycle']);
            $saved = LeadWorkflow::update($lead, $data, $request->user());
        } catch (LeadConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => $this->outcome($before, $saved),
            'data'    => LeadWorkflow::present($saved->load(['partner', 'measurement'])),
        ]);
    }

    /** Prepare click-to-chat drafts for the chosen recipients (saved on the lead). Nothing is sent. */
    public function whatsapp(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate(['to_partner' => ['required', 'boolean'], 'to_customer' => ['required', 'boolean']]);
        $lead->load('partner');
        $drafts = LeadWorkflow::whatsappDrafts($lead, (bool) $data['to_partner'], (bool) $data['to_customer']);
        if ($drafts['problem']) {
            return response()->json(['message' => $drafts['problem']], 422);
        }
        $lead->forceFill(['to_partner' => (bool) $data['to_partner'], 'to_customer' => (bool) $data['to_customer']])->save();
        LeadWorkflow::logWhatsapp($lead, array_column($drafts['recipients'], 'kind'), $request->user());

        return response()->json([
            'message' => 'WhatsApp draft prepared. Open each chat and press Send in WhatsApp — nothing has been sent yet.',
            'mode' => 'draft',
            'is_demo' => (bool) $lead->is_demo,
            'recipients' => $drafts['recipients'],
        ]);
    }

    private function outcome(array $before, Lead $l): string
    {
        if ($before['lifecycle'] !== $l->lifecycle) {
            return match ($l->lifecycle) {
                LeadWorkflow::CLOSED => "Lead {$l->lead_no} closed — {$l->closure_reason}.",
                LeadWorkflow::ARCHIVED => "Lead {$l->lead_no} archived ({$l->closure_reason}). It is kept under the Archived tab.",
                default => "Lead {$l->lead_no} reopened — ".LeadWorkflow::label($l->status_code).'.',
            };
        }
        $scheduledChanged = ($before['scheduled_at']?->getTimestamp()) !== ($l->scheduled_at?->getTimestamp());
        if ($scheduledChanged && $l->scheduled_at) {
            return "Measurement ".($before['scheduled_at'] ? 'rescheduled' : 'scheduled').' for '.LeadWorkflow::local($l->scheduled_at, 'd M Y, h:i A').'.';
        }

        return $before['status_code'] !== $l->status_code
            ? 'Status changed to '.LeadWorkflow::label($l->status_code).'.'
            : "Lead {$l->lead_no} updated.";
    }

    private function rules(bool $update): array
    {
        $codes = array_keys(LeadWorkflow::statuses());
        $local = 'date_format:Y-m-d\TH:i';

        $rules = array_filter([
            'client_name'         => [$update ? 'sometimes' : null, 'required', 'string', 'max:255'],
            'client_phone'        => [$update ? 'sometimes' : null, 'required', 'digits:10'],
            'type'                => [$update ? 'sometimes' : null, 'required', Rule::in(['B2B', 'B2C'])],
            // A referral partner, or an administrator holding a partner profile (dual role; e.g. their own submitted leads).
            'referral_partner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'referral_partner')
                ->orWhereIn('id', PartnerProfile::query()->select('user_id')))],
            'assigned_to'         => ['nullable', 'string', Rule::in(LeadWorkflow::assignees())],
            'notes'               => ['nullable', 'string', 'max:5000'],
            'to_partner'          => ['sometimes', 'boolean'],
            'to_customer'         => ['sometimes', 'boolean'],
            // Only on edit: a new lead is always "Lead Received".
            'status_code'         => $update ? ['required', Rule::in($codes)] : null,
            'scheduled_at'        => $update ? ['nullable', $local, 'required_if:status_code,'.implode(',', LeadWorkflow::SCHEDULED)] : null,
            'measured_at'         => $update ? ['nullable', $local, 'required_if:status_code,'.LeadWorkflow::FULL, 'before_or_equal:'.LeadWorkflow::local(now()->addDay(), 'Y-m-d\TH:i')] : null,
            'version'             => $update ? ['required', 'integer'] : null,
        ], fn ($r) => $r !== null);

        return array_map(fn (array $r) => array_values(array_filter($r, fn ($x) => $x !== null)), $rules);
    }

    /** User ids of administrators whose partner profile is approved (dual role). */
    private static function approvedStaffPartnerIds(): array
    {
        return PartnerProfile::where('status', PartnerProfile::APPROVED)->pluck('user_id')->all();
    }
}
