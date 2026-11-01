<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActionHub\Action;
use App\Models\ActionHub\Holiday;
use App\Services\WhatsAppService;
use App\Support\ActionHub;
use App\Support\ActionHubRejected;
use App\Support\ActionHubWhatsapp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Workspace → Action Hub (admin only, routes/api.php). Every read first applies
 * due automatic status changes (ActionHub::refresh), so the page never depends
 * on a browser being open or on the scheduler having run. Saves are atomic:
 * the action and its audit events are written in one transaction.
 */
class ActionHubController extends Controller
{
    private const NO_HTML = 'not_regex:/[<>]/';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(self::filterRules());
        ActionHub::refresh();
        $actions = ActionHub::query($filters)->get();
        $names = ActionHub::userNames($actions);
        $overdue = Action::where('status', ActionHub::DELAYED)->orderBy('scheduled_at')->get();

        return response()->json([
            'data' => $actions->map(fn (Action $a) => ActionHub::present($a, $names))->values(),
            'overdue' => $overdue->map(fn (Action $a) => ActionHub::present($a))->values(),
            'total' => Action::count(),
            'config' => self::config(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->submit($request, null);
    }

    public function update(Request $request, Action $action): JsonResponse
    {
        return $this->submit($request, $action);
    }

    /**
     * Send WhatsApp dialog (every action): the messages that apply to it with their full text,
     * whether each fits the action's current state, the saved number and the message history.
     * Sends nothing.
     */
    public function whatsappPreview(Action $action, WhatsAppService $whatsApp): JsonResponse
    {
        ActionHub::refresh(); // due automatic status changes first, so the offered messages are current
        $action->refresh();

        return response()->json(['data' => ActionHubWhatsapp::preview($action, $whatsApp)]);
    }

    /**
     * Create the service ticket of an eligible action saved before tickets existed (from the
     * Send WhatsApp dialog; the same as its first Submit). Sends nothing; never replaces a ticket.
     */
    public function createTicket(Request $request, Action $action, WhatsAppService $whatsApp): JsonResponse
    {
        try {
            $ticket = ActionHubWhatsapp::issueTicket($action, $request->user());
        } catch (ActionHubRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $saved = $action->fresh();

        return response()->json([
            'message' => "Ticket {$ticket} created. Nothing was sent.",
            'data' => ActionHub::present($saved, ActionHub::userNames(collect([$saved]))),
            'preview' => ActionHubWhatsapp::preview($saved, $whatsApp),
        ]);
    }

    /**
     * Send WhatsApp (also used to retry): sends the message the admin chose (`template`; default
     * Request Received for ticket actions, General Message otherwise) for a SAVED action to that
     * action's own phone number. This is the only place a message is sent; Submit only saves.
     * The body carries the ticket number the user confirmed (none for Task / Contact Lead /
     * Other), the action `version` / `scheduled_at` they reviewed (the send is refused if the
     * action changed since) and, for a General Message, its `message`. A destination number is
     * never accepted from the browser. Messages that state a fact need `confirmed`. An accepted
     * message is never sent again, and parallel clicks are turned away (ActionHubWhatsapp::deliver).
     */
    public function sendWhatsapp(Request $request, Action $action, WhatsAppService $whatsApp): JsonResponse
    {
        $ticketAction = ActionHubWhatsapp::spec($action->action_type) !== null;
        $data = $request->validate([
            'ticket_no' => [$ticketAction ? 'required' : 'nullable', 'string', 'max:30'],
            'template' => ['nullable', 'string', Rule::in(array_keys(ActionHubWhatsapp::templates()))],
            'version' => ['nullable', 'integer', 'min:1'],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'confirmed' => ['nullable', 'boolean'],
            'message' => ['nullable', 'required_if:template,general', 'string', 'max:500', self::NO_HTML, 'not_regex:/[\r\n\t]/'],
        ], [
            'ticket_no.required' => 'The ticket number to send is missing. Reload the page and try again.',
            'template.in' => 'Choose one of the listed WhatsApp messages.',
            'message.required_if' => 'Type the message to send.',
            'message.not_regex' => 'The message must be one line without < or >.',
            'message.max' => 'The message can be at most 500 characters.',
        ]);
        $template = $data['template'] ?? ($ticketAction ? ActionHubWhatsapp::REQUEST_RECEIVED : ActionHubWhatsapp::GENERAL);
        if (($confirm = ActionHubWhatsapp::confirmation($template)) && ! ($data['confirmed'] ?? false)) {
            $problem = 'Please confirm before sending: '.$confirm.' Nothing was sent.';

            return response()->json(['message' => $problem, 'errors' => ['confirmed' => [$problem]],
                'whatsapp' => ['sent' => false, 'status' => null, 'message' => $problem, 'busy' => false]], 422);
        }
        if ($problem = ActionHubWhatsapp::cannotSend($action, $data['ticket_no'] ?? null)) {
            return response()->json([
                'message' => $problem,
                'errors' => ['ticket_no' => [$problem]],
                'whatsapp' => ['sent' => false, 'status' => null, 'message' => $problem, 'busy' => false],
            ], 422);
        }
        // WhatsApp not set up / switched off: refuse before touching the message record, so its
        // status and attempt count stay as they were. Only the refusal goes into the audit history.
        if ($u = ActionHubWhatsapp::unavailable($whatsApp)) {
            ActionHub::log($action, 'whatsapp_not_configured', $request->user(), [
                'reason' => $u['message'],
                'details' => ['ticket' => $action->ticket_no, 'message' => $template, 'message_label' => ActionHubWhatsapp::label($template),
                    'missing_settings' => $u['missing_settings'], 'sending_enabled' => $u['sending_enabled']],
            ]);
            $message = $u['message'].' Nothing was sent.';
            $state = ActionHubWhatsapp::present($action->message()->first()); // masked number only

            // No action record here (it holds the full phone number); the page reloads its rows.
            return response()->json([
                'message' => $message,
                'error' => $u['code'],
                'missing_settings' => $u['missing_settings'],
                'sending_enabled' => $u['sending_enabled'],
                'whatsapp_state' => $state,
                'whatsapp' => ['sent' => false, 'status' => $state['status'] ?? null, 'message' => $message, 'busy' => false],
            ], 503);
        }
        // No approved Meta template configured for this message (and plain text not allowed): refused the same way.
        if ($t = ActionHubWhatsapp::templateProblem($template)) {
            ActionHub::log($action, 'whatsapp_not_configured', $request->user(), [
                'reason' => $t,
                'details' => array_filter(['ticket' => $action->ticket_no, 'message' => $template, 'message_label' => ActionHubWhatsapp::label($template),
                    'missing_settings' => [ActionHubWhatsapp::TEMPLATE_ENV[$template] ?? $template]], fn ($v) => $v !== null),
            ]);

            return response()->json([
                'message' => $t.' Nothing was sent.', 'error' => 'template_not_configured',
                'missing_settings' => [ActionHubWhatsapp::TEMPLATE_ENV[$template] ?? $template],
                'whatsapp' => ['sent' => false, 'status' => null, 'message' => $t.' Nothing was sent.', 'busy' => false],
            ], 503);
        }
        ActionHub::refresh(); // due automatic status changes first (e.g. Get Ready), as the page would show
        $result = ActionHubWhatsapp::deliver($action, $request->user(), $whatsApp, $template, $data['version'] ?? null, $data['scheduled_at'] ?? null, $data['message'] ?? null);
        if ($result['refused']) { // changed since it was reviewed, or not for the action's current state: nothing attempted
            return response()->json(['message' => $result['message'], 'error' => $result['refused'] === 'changed' ? 'whatsapp_stale' : 'whatsapp_not_available',
                'whatsapp' => $result, 'whatsapp_state' => ActionHubWhatsapp::present($action->message()->first())], $result['refused'] === 'changed' ? 409 : 422);
        }
        if ($result['busy']) { // another request is sending it: error response without the action record
            return response()->json(['message' => $result['message'], 'error' => 'whatsapp_busy', 'whatsapp' => $result,
                'whatsapp_state' => ActionHubWhatsapp::present($action->message()->first())], 409);
        }
        $saved = $action->fresh();

        return response()->json([
            'data' => ActionHub::present($saved, ActionHub::userNames(collect([$saved]))),
            'whatsapp' => $result,
            'message' => $result['message'],
        ]);
    }

    /** Audit history of one action, oldest first. */
    public function history(Action $action): JsonResponse
    {
        return response()->json(['data' => $action->events()->get()->map(fn ($e) => ActionHub::presentEvent($e))->values()]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2100-01-01|unique:action_hub_holidays,date',
            'name' => ['required', 'string', 'max:120', self::NO_HTML],
        ], ['date.unique' => 'That date is already in the holiday list.']);
        Holiday::create($data + ['created_by' => $request->user()->id]);
        ActionHub::refresh();

        return response()->json(['holidays' => self::holidays()], 201);
    }

    public function destroyHoliday(Request $request, Holiday $holiday): JsonResponse
    {
        Log::info('Action Hub: holiday removed', ['date' => $holiday->date->format('Y-m-d'), 'name' => $holiday->name, 'by' => $request->user()->id]);
        $holiday->delete();

        return response()->json(['holidays' => self::holidays()]);
    }

    // ────────────────────────────────────────────────────────────────────

    private function submit(Request $request, ?Action $action): JsonResponse
    {
        $user = $request->user();
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => self::normalisePhone($request->input('phone'))]);
        }
        if ($request->has('action_type')) { // "Scheduled Installation" → stored value "Installation"
            $request->merge(['action_type' => ActionHub::actionValue($request->input('action_type'))]);
        }
        try {
            $in = $request->validate(self::rules($action !== null), [
                'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
                'scheduled_at.date_format' => 'Choose a valid scheduled date and time.',
                'action_other.required_if' => 'Describe the action when "Other" is selected.',
                'request_id.uuid' => 'The request could not be identified; reload the page and submit again.',
            ]);
        } catch (ValidationException $e) {
            // Field names only — never the values (phone numbers etc.).
            ActionHub::log($action, 'validation_failed', $user, [
                'old_status' => $action?->status,
                'details' => ['fields' => array_keys($e->errors()), 'operation' => $action ? 'update' : 'create'],
            ]);
            throw $e;
        }

        try {
            $result = ActionHub::save($action, $in, $user, $in['request_id']);
        } catch (ActionHubRejected $e) {
            $current = $action?->fresh();
            ActionHub::log($current, $e->event, $user, [
                'old_status' => $current?->status,
                'new_status' => $in['status'] ?? null,
                'reason' => $e->getMessage(),
                'details' => ['requested_status' => $in['status'] ?? null, 'version_sent' => $in['version'] ?? null, 'version_current' => $current?->version],
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => array_fill_keys($e->fields, [$e->getMessage()]),
                'current' => $current ? ActionHub::present($current) : null,
            ], $e->httpStatus);
        }

        // Submit only saves (and gives an eligible action its ticket). WhatsApp is sent separately: sendWhatsapp().
        $saved = $result['action']->fresh();

        return response()->json([
            'data' => ActionHub::present($saved, ActionHub::userNames(collect([$saved]))),
            'replayed' => $result['replayed'],
            'message' => $result['replayed'] ? 'Already saved.' : ($action ? 'Changes saved.' : 'Action added.'),
        ], $action || $result['replayed'] ? 200 : 201);
    }

    private static function rules(bool $updating): array
    {
        return [
            'customer' => ['required', 'string', 'max:255', self::NO_HTML],
            'project' => ['required', 'string', 'max:255', self::NO_HTML],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'description' => ['nullable', 'string', 'max:2000', self::NO_HTML],
            'action_type' => ['required', 'string', Rule::in(config('action_hub.actions'))],
            'action_other' => ['nullable', 'required_if:action_type,Other', 'string', 'max:120', self::NO_HTML],
            'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'status' => ['nullable', 'string', Rule::in(config('action_hub.statuses'))],
            'assigned_to' => ['required', 'string', Rule::in(config('action_hub.assignees'))], // only the seven names
            'reason' => ['nullable', 'string', 'max:500', self::NO_HTML],
            'version' => $updating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'request_id' => ['required', 'uuid'],
        ];
    }

    /** Filters for the list and its report (ReportController uses the same rules). */
    public static function filterRules(): array
    {
        return [
            'q' => 'nullable|string|max:100',
            'status' => ['nullable', 'string', Rule::in(array_merge(['all'], array_diff(config('action_hub.statuses'), [ActionHub::RESCHEDULE])))],
            'action' => ['nullable', 'string', Rule::in(array_merge(['all'], config('action_hub.actions')))],
            'assignee' => ['nullable', 'string', Rule::in(array_merge(['all'], config('action_hub.assignees')))],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'history' => 'nullable|in:0,1,true,false',
        ];
    }

    /** "+91 98765-43210" / "098765 43210" → "9876543210" (anything else is left for validation to refuse). */
    private static function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/[\s\-().]+/', '', trim($raw));
        if (preg_match('/^(?:\+?91|0)(\d{10})$/', $digits, $m)) {
            return $m[1];
        }

        return $digits;
    }

    private static function holidays(): array
    {
        return Holiday::orderBy('date')->get()->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->format('Y-m-d'), 'name' => $h->name])->values()->all();
    }

    private static function config(): array
    {
        return [
            'actions' => config('action_hub.actions'),
            'action_labels' => collect(config('action_hub.actions'))->mapWithKeys(fn ($v) => [$v => ActionHub::actionLabel($v)])->all(),
            'assignees' => config('action_hub.assignees'),
            'statuses' => config('action_hub.statuses'),
            'location' => config('action_hub.location'),
            'timezone' => ActionHub::timezone(),
            'weekly_off' => config('action_hub.weekly_off'),
            'get_ready_working_days' => (int) config('action_hub.get_ready_working_days'),
            'holidays' => self::holidays(),
            'now_local' => ActionHub::local(now()),
            'overdue_message' => ActionHub::OVERDUE_MESSAGE,
            'ticket_actions' => array_keys(config('action_hub.whatsapp.tickets', [])),
            // Whether Send WhatsApp can work on this server (setting names only, never values).
            'whatsapp_ready' => ($wa = ActionHubWhatsapp::unavailable(app(WhatsAppService::class))) === null,
            'whatsapp_problem' => $wa['message'] ?? null,
            'whatsapp_missing' => $wa['missing_settings'] ?? [],
            'whatsapp_sending_enabled' => $wa['sending_enabled'] ?? true,
            // Approved Meta template settings still empty (those messages are preview-only).
            'whatsapp_templates_missing' => array_values(array_map(fn ($k) => ActionHubWhatsapp::TEMPLATE_ENV[$k],
                array_filter(array_keys(ActionHubWhatsapp::templates()), fn ($k) => ActionHubWhatsapp::templateProblem($k) !== null))),
        ];
    }
}
