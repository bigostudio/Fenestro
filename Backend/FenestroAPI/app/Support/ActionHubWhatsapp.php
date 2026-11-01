<?php

namespace App\Support;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\ActionHub\Message;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Service tickets and their WhatsApp messages (config action_hub.whatsapp, requested 2026-11-01).
 *
 *  - Only Snag, Service, Scheduled Measurements and Scheduled Installation get a ticket,
 *    assigned once inside the transaction that saves the action (on create, or on the first
 *    Submit of an older eligible action without one). Later edits never change it.
 *  - Ticket = PREFIX-YYMM-NNN. ONE serial per month (Asia/Kolkata) is shared by all four
 *    prefixes (counter row prefix "*"): PV-SG-2611-001, PV-SV-2611-002, PV-MSRMT-2611-003 …
 *    It only goes up, so a number is never reused. The counter row is written before it is
 *    read in the same transaction, so two simultaneous saves wait for each other; a unique
 *    index on ticket_no is the last safeguard.
 *  - Nine messages (config templates). Saving never sends. A message is sent only by the
 *    explicit Send WhatsApp request for a saved action, after the admin chose the message
 *    and reviewed it, to the action's own phone number, always with the action's ticket.
 *    Each message is offered only in the action state it describes (templateState).
 *  - One message row per action and occasion (dedupe_key): e.g. one "scheduled / rescheduled"
 *    message per appointment, one cancellation message per cancellation. The row is claimed
 *    with one conditional UPDATE, so double clicks and parallel requests cannot send twice,
 *    and an accepted message is never sent again. A failed one may be retried.
 *  - The send re-reads the action: if it changed after the admin opened the message, nothing
 *    is sent and the admin is asked to review it again.
 *  - Without WhatsApp credentials nothing is sent and the status says so.
 */
class ActionHubWhatsapp
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    public const REQUEST_RECEIVED = 'request_received';

    /** One-line message the admin types, for Task / Contact Lead / Other (no ticket). */
    public const GENERAL = 'general';

    /** Server setting that holds each message's approved Meta template name (config action_hub.whatsapp.templates.*.meta). */
    public const TEMPLATE_ENV = [
        'request_received' => 'WHATSAPP_AH_TEMPLATE_REQUEST_RECEIVED', 'scheduled' => 'WHATSAPP_AH_TEMPLATE_SCHEDULED',
        'approaching' => 'WHATSAPP_AH_TEMPLATE_APPROACHING', 'completed' => 'WHATSAPP_AH_TEMPLATE_COMPLETED',
        'delayed_internal' => 'WHATSAPP_AH_TEMPLATE_DELAYED', 'rescheduled_customer' => 'WHATSAPP_AH_TEMPLATE_RESCHEDULED',
        'postponed_customer' => 'WHATSAPP_AH_TEMPLATE_POSTPONED', 'cancelled_customer' => 'WHATSAPP_AH_TEMPLATE_CANCELLED_CUSTOMER',
        'cancelled_internal' => 'WHATSAPP_AH_TEMPLATE_CANCELLED_INTERNAL', 'general' => 'WHATSAPP_AH_TEMPLATE_GENERAL',
    ];

    /** Counter row of the serial shared by all ticket prefixes. */
    private const SHARED = '*';

    /** A send that has not finished after this many seconds may be retried (request died mid-send). */
    private const STUCK_AFTER = 120;

    /**
     * What the admin must confirm (checkbox in Send WhatsApp; also required by the server)
     * before sending a message that states something about the service.
     */
    private const CONFIRM = [
        'scheduled' => 'The appointment shown is confirmed with the customer.',
        'completed' => 'The service is completed and handed over.',
        'delayed_internal' => 'The delay is on our side and no new date is confirmed yet.',
        'rescheduled_customer' => 'The customer asked for this new date and time.',
        'postponed_customer' => 'The customer asked to postpone, and no new date is confirmed yet.',
        'cancelled_customer' => 'The customer asked to cancel this service permanently.',
        'cancelled_internal' => 'The service is permanently cancelled for an internal reason that was communicated to the customer.',
    ];

    /**
     * Ticket prefix and service name of a Snag / Service / Scheduled Measurements / Scheduled
     * Installation action; null for every other action. Recognises the stored value, its label
     * and common variants in any case/spacing ("Scheduled Installation", "Measurement", " snag ").
     *
     * @return array{prefix: string, service: string}|null
     */
    public static function spec(?string $actionType): ?array
    {
        $tickets = config('action_hub.whatsapp.tickets', []);

        return $tickets[$actionType] ?? $tickets[self::serviceAction($actionType)] ?? null;
    }

    /** Stored value of one of the four service actions for $type (value, label or variant), or null. */
    public static function serviceAction(?string $type): ?string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $type)));
        foreach (array_keys(config('action_hub.whatsapp.tickets', [])) as $value) {
            $names = [$value, ActionHub::actionLabel($value)];
            if (in_array($key, array_map('mb_strtolower', $names), true)) {
                return $value;
            }
        }

        return [
            'measurement' => 'Measurements Scheduled', 'measurements' => 'Measurements Scheduled',
            'scheduled measurement' => 'Measurements Scheduled', 'measurement scheduled' => 'Measurements Scheduled',
            'scheduled installations' => 'Installation', 'installations' => 'Installation', 'installation scheduled' => 'Installation',
            'snags' => 'Snag', 'services' => 'Service',
        ][$key] ?? null;
    }

    /** @return array<string, array{label: string, text: string, params: string[], meta: ?string}> */
    public static function templates(): array
    {
        return config('action_hub.whatsapp.templates', []);
    }

    public static function label(?string $key): string
    {
        return self::templates()[$key]['label'] ?? (string) $key;
    }

    public static function confirmation(string $key): ?string
    {
        return self::CONFIRM[$key] ?? null;
    }

    /** The messages offered for this action: the nine ticket messages, or the General Message (no ticket). */
    public static function keysFor(Action $a): array
    {
        $keys = array_keys(self::templates());

        return self::spec($a->action_type) ? array_values(array_diff($keys, [self::GENERAL])) : [self::GENERAL];
    }

    /**
     * Why message $key cannot be sent on this server even though WhatsApp itself is set up: no
     * approved Meta template configured for it (and plain text not allowed). Null = it can.
     */
    public static function templateProblem(string $key): ?string
    {
        if (trim((string) (self::templates()[$key]['meta'] ?? '')) !== '' || config('action_hub.whatsapp.allow_session_text')) {
            return null;
        }

        return 'No approved WhatsApp template is configured for “'.self::label($key).'”. WhatsApp delivers business-started messages only as approved templates: set '
            .(self::TEMPLATE_ENV[$key] ?? 'its template setting').' to the approved template’s name on the server.';
    }

    /**
     * Create the ticket of an eligible action saved before tickets existed (same as its first
     * Submit would). Never replaces an existing ticket. Returns the ticket number.
     *
     * @throws ActionHubRejected when the action is not eligible
     */
    public static function issueTicket(Action $a, User $user): string
    {
        return DB::transaction(function () use ($a, $user) {
            $fresh = Action::whereKey($a->id)->lockForUpdate()->firstOrFail();
            if ($fresh->ticket_no) {
                return $fresh->ticket_no; // already has one (e.g. a parallel click): unchanged
            }
            if (! self::spec($fresh->action_type)) {
                throw new ActionHubRejected('Only Snag, Service, Scheduled Measurements and Scheduled Installation actions get a service ticket.');
            }
            self::assignTicket($fresh, now());
            ActionHub::log($fresh, 'ticket_assigned', $user, [
                'old_status' => $fresh->status, 'new_status' => $fresh->status,
                'details' => ['ticket' => $fresh->ticket_no, 'action_type' => $fresh->action_type, 'from' => 'Send WhatsApp'],
            ]);

            return $fresh->ticket_no;
        });
    }

    /**
     * Next ticket number for $prefix in the month of $now (business time zone), from the
     * serial shared by all prefixes. Must run inside the transaction that stores it.
     */
    public static function nextTicket(string $prefix, CarbonInterface $now): string
    {
        $period = CarbonImmutable::instance($now)->setTimezone(ActionHub::timezone())->format('ym');
        $key = ['prefix' => self::SHARED, 'period' => $period];
        $table = DB::table('action_hub_ticket_counters');
        // A month that started with one counter per prefix (before the shared serial) continues
        // after its highest number. Those counters no longer change.
        $start = (int) (clone $table)->where('period', $period)->where('prefix', '!=', self::SHARED)->max('last_serial');
        // Write first: on SQLite this takes the database write lock, on MySQL/PostgreSQL
        // the row lock, so the value read below cannot be read by anyone else first.
        (clone $table)->insertOrIgnore($key + ['last_serial' => $start, 'created_at' => now(), 'updated_at' => now()]);
        do {
            (clone $table)->where($key)->increment('last_serial', 1, ['updated_at' => now()]);
            $ticket = sprintf('%s-%s-%03d', $prefix, $period, (int) (clone $table)->where($key)->value('last_serial'));
        } while (Action::where('ticket_no', $ticket)->exists()); // never hand out an existing number

        return $ticket;
    }

    /** Called by ActionHub::create / update (same transaction): ticket on the action + its "request received" message (not sent). */
    public static function assignTicket(Action $a, CarbonInterface $now): ?Message
    {
        $spec = self::spec($a->action_type);
        if (! $spec) {
            return null;
        }
        $a->ticket_no = self::nextTicket($spec['prefix'], $now);
        $a->save();

        return Message::create([
            'action_id' => $a->id, 'ticket_no' => $a->ticket_no, 'service_type' => $spec['service'],
            'template' => self::REQUEST_RECEIVED, 'dedupe_key' => self::REQUEST_RECEIVED, 'status' => self::PENDING,
        ]);
    }

    /** Why this saved action cannot send WhatsApp messages at all (null = it can). $ticketNo: the ticket the user confirmed. */
    public static function cannotSend(Action $a, ?string $ticketNo): ?string
    {
        $spec = self::spec($a->action_type);
        if (! $spec) {
            // Task / Contact Lead / Other: General Message only, never with a ticket.
            return $ticketNo !== null && $ticketNo !== ''
                ? 'Only Snag, Service, Scheduled Measurements and Scheduled Installation actions have a service ticket; this action can send a General Message without one. Nothing was sent.'
                : null;
        }
        if (! $a->ticket_no) {
            return 'This action has no ticket number yet. Click Edit, then Submit to save it; that creates the ticket. Then send.';
        }
        if ($ticketNo !== $a->ticket_no) {
            return "The ticket number does not match this action (its ticket is {$a->ticket_no}). Reload the page and try again. Nothing was sent.";
        }
        if (! str_starts_with($a->ticket_no, $spec['prefix'].'-')) {
            return "Ticket {$a->ticket_no} was created for a different action type, not ".ActionHub::actionLabel($a->action_type).'. Nothing was sent.';
        }
        if (! Message::where('action_id', $a->id)->exists()) {
            return 'This ticket has no WhatsApp message record. Nothing was sent.';
        }

        return null;
    }

    /**
     * Whether message $key fits the action's current state, and the occasion it belongs to.
     *
     * @return array{available: bool, reason: ?string, group: ?string, scheduled_for: ?CarbonInterface}
     */
    public static function templateState(Action $a, string $key, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $sched = $a->scheduled_at;
        $future = $sched && $sched->gt($now);
        $pending = in_array($a->status, [ActionHub::ON_TRACK, ActionHub::GET_READY], true);
        $changed = (string) ($a->status_changed_at ?? $a->updated_at)?->getTimestamp();
        $no = fn (string $reason) => ['available' => false, 'reason' => $reason, 'group' => null, 'scheduled_for' => null];
        $yes = fn (string $group, bool $withDate = false) => ['available' => true, 'reason' => null, 'group' => $group, 'scheduled_for' => $withDate ? $sched : null];

        $spec = self::spec($a->action_type);
        if ($key === self::GENERAL) {
            // Any status. Its occasion is the exact text (set by deliver), so the same message is never sent twice.
            return $spec ? $no('For Task, Contact Lead and Other actions. Service actions use the ticket messages.') : $yes(self::GENERAL);
        }
        if (isset(self::templates()[$key]) && ! $spec) {
            return $no('Only for Snag, Service, Scheduled Measurements and Scheduled Installation actions (they have a service ticket).');
        }
        if (isset(self::templates()[$key]) && ! $a->ticket_no) {
            return $no('This action has no ticket yet. Create its ticket first (Create ticket, or Edit → Submit).');
        }

        switch ($key) {
            case self::REQUEST_RECEIVED:
                return $a->status === ActionHub::ABORT ? $no('Not available: this action was aborted (cancelled). Use a cancellation message instead.') : $yes(self::REQUEST_RECEIVED);
            case 'scheduled':
                return $pending && $future ? $yes('schedule:'.$sched->getTimestamp(), true)
                    : $no("Available when a future appointment is saved and the action is On Track or Get Ready (it is {$a->status}).");
            case 'approaching':
                return $a->status === ActionHub::GET_READY && $future ? $yes('approaching:'.$sched->getTimestamp(), true)
                    : $no('Available when the appointment is approaching: status Get Ready (the last '.config('action_hub.get_ready_working_days', 2).' working days before it).');
            case 'completed':
                if ($a->status !== ActionHub::COMPLETED) {
                    return $no('Available after the action is marked Completed.');
                }

                return trim((string) config('action_hub.whatsapp.feedback_link')) === ''
                    ? $no('The feedback link is not configured on this server (ACTION_HUB_FEEDBACK_URL), so the completion message cannot be sent.')
                    : $yes('completed:'.$changed);
            case 'delayed_internal':
            case 'postponed_customer':
                // One delay message per delay: the internal and the customer-requested wording share it.
                return $a->status === ActionHub::DELAYED ? $yes('delay:'.$changed)
                    : $no('Available while the action is Delayed and no new date is confirmed (after a new date is saved, use Rescheduled or Service Scheduled).');
            case 'rescheduled_customer':
                $rescheduled = $sched && ActionEvent::where('action_id', $a->id)->where('event', 'rescheduled')->get()
                    ->contains(fn (ActionEvent $e) => $e->new_scheduled_at?->equalTo($sched));

                // Shares the occasion with "Service Scheduled": one confirmation per appointment.
                return $pending && $future && $rescheduled ? $yes('schedule:'.$sched->getTimestamp(), true)
                    : $no('Available after a new future date and time have been saved with Reschedule.');
            case 'cancelled_customer':
            case 'cancelled_internal':
                return $a->status === ActionHub::ABORT ? $yes('cancel:'.$changed)
                    : $no('Available only after an admin has aborted (cancelled) the action.');
        }

        return $no('Unknown message.');
    }

    /**
     * Values for the placeholders of every message ({date}/{time}: $scheduledFor, else the current
     * appointment; {message}: the General Message text the admin typed).
     */
    public static function values(Action $a, ?CarbonInterface $scheduledFor = null, string $message = ''): array
    {
        $when = $scheduledFor ?? $a->scheduled_at;

        return [
            'customer_name' => OrderManagement::upper($a->customer),
            'service_type' => self::serviceType($a),
            'ticket_no' => (string) $a->ticket_no,
            'date' => (string) ActionHub::local($when, 'd M Y'),
            'time' => (string) ActionHub::local($when, 'h:i A'),
            'feedback_link' => trim((string) config('action_hub.whatsapp.feedback_link')),
            'message' => self::cleanMessage($message),
        ];
    }

    /** "Snag" / "Measurement" … for ticket actions; the action's own label otherwise (e.g. "Contact Lead", "Other: Site visit"). */
    public static function serviceType(Action $a): string
    {
        return self::spec($a->action_type)['service']
            ?? ($a->action_type === 'Other' && $a->action_other ? 'Other: '.$a->action_other : ActionHub::actionLabel($a->action_type));
    }

    /** General Message text as sent: one line, single spaces (WhatsApp template values cannot contain line breaks). */
    public static function cleanMessage(?string $message): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $message));
    }

    public static function text(string $key, array $values): string
    {
        $vars = [];
        foreach ($values as $k => $v) {
            $vars['{'.$k.'}'] = $v;
        }

        return strtr((string) (self::templates()[$key]['text'] ?? ''), $vars);
    }

    /** "9876543210" (or "+91 98765 43210") → "919876543210"; null when it is not a valid Indian mobile. Never another number. */
    public static function recipient(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        $cc = (string) config('action_hub.whatsapp.country_code', '91');
        if (preg_match('/^(?:'.preg_quote($cc, '/').'|0)?([6-9]\d{9})$/', $d, $m)) {
            return $cc.$m[1];
        }

        return null;
    }

    /**
     * Why WhatsApp cannot send on this server, or null when it can. Only setting NAMES are
     * reported, never their values: ['code', 'message', 'missing_settings', 'sending_enabled'].
     */
    public static function unavailable(WhatsAppService $wa): ?array
    {
        $missing = $wa->missingSettings();
        $enabled = (bool) config('services.whatsapp.enabled');
        if (! $missing && $enabled) {
            return null;
        }
        $why = array_filter([
            $missing ? 'Missing settings: '.implode(', ', $missing).'.' : null,
            $enabled ? null : 'Sending is switched off (WHATSAPP_SENDING_ENABLED=false).',
        ]);

        return [
            'code' => 'whatsapp_unavailable',
            'message' => 'WhatsApp sending is not configured on this server. Configure the WhatsApp Business API before sending messages. '.implode(' ', $why),
            'missing_settings' => $missing,
            'sending_enabled' => $enabled,
        ];
    }

    /** unavailable()'s message, or null when WhatsApp can send. */
    public static function configProblem(WhatsAppService $wa): ?string
    {
        return self::unavailable($wa)['message'] ?? null;
    }

    /**
     * Everything Send WhatsApp shows before sending: each message with its full text (from the
     * saved action), whether it can be sent now and why not, the masked destination and the
     * ticket's message history. Sends nothing.
     */
    public static function preview(Action $a, WhatsAppService $wa): array
    {
        $values = self::values($a, null, '{message}'); // the General Message preview fills {message} as the admin types
        $values['message'] = '{message}';
        $messages = $a->messages()->get();
        $templates = [];
        foreach (self::keysFor($a) as $key) {
            $t = self::templates()[$key];
            $state = self::templateState($a, $key);
            $row = $state['group'] && $key !== self::GENERAL ? $messages->firstWhere('dedupe_key', $state['group']) : null;
            $templates[] = [
                'key' => $key,
                'label' => $t['label'],
                'available' => $state['available'],
                'reason' => $state['reason'],
                'text' => self::text($key, $values),
                'uses_schedule' => in_array('date', $t['params'], true),
                'confirm' => self::confirmation($key),
                'needs_message' => $key === self::GENERAL,
                'meta_template' => (bool) ($t['meta'] ?? null),
                // Fits the action, but this server has no approved template for it: preview only.
                'template_problem' => self::templateProblem($key),
                // This occasion's message so far: already accepted (not sent again) or a failed try.
                'current' => $row ? self::present($row) : null,
            ];
        }
        $to = self::recipient($a->phone);
        $digits = preg_replace('/\D+/', '', (string) $a->phone);
        $eligible = self::spec($a->action_type) !== null;

        return [
            'action_id' => $a->id,
            'ticket_no' => $a->ticket_no,
            // An eligible action saved before tickets existed: its ticket can be created now.
            'needs_ticket' => $eligible && ! $a->ticket_no,
            'ticket_action' => $eligible,
            'customer' => $values['customer_name'],
            'project' => OrderManagement::upper($a->project),
            'service_type' => $values['service_type'],
            'action_label' => ActionHub::actionLabel($a->action_type),
            'status' => $a->status,
            'version' => $a->version,
            'scheduled_local' => ActionHub::local($a->scheduled_at),
            'scheduled_display' => $values['date'].' at '.$values['time'],
            // The number saved on this action, as it is sent (international format). Only admins see this page.
            'phone' => $to ? '+'.substr($to, 0, 2).' '.substr($to, 2, 5).' '.substr($to, 7) : ($digits !== '' ? $a->phone : null),
            'recipient' => $to ? WhatsAppService::mask($to) : null,
            // The same number in the form WhatsApp chat links use (e.g. 919876543210): Send WhatsApp opens
            // this customer's chat with the message filled in (same click-to-chat as Send Statement).
            'wa_number' => $to,
            'recipient_problem' => $to ? null : ($digits === ''
                ? 'No WhatsApp number is saved on this action. Add the customer’s 10-digit mobile number (Edit → Submit) before sending.'
                : 'The saved number is not a valid 10-digit Indian mobile number. Correct it (Edit → Submit) before sending.'),
            'cannot_send' => self::cannotSend($a, $a->ticket_no),
            'unavailable' => self::unavailable($wa),
            'templates' => $templates,
            'messages' => $messages->sortByDesc('id')->map(fn (Message $m) => self::present($m))->values()->all(),
        ];
    }

    /**
     * Send (or retry) message $key of $a's ticket. $version / $scheduledLocal: what the admin
     * reviewed; if the action has changed since, nothing is sent. Returns
     * ['sent' => bool, 'status' => ?string, 'message' => string, 'busy' => bool, 'refused' => ?string]
     * (refused: 'changed' or 'not_available' — nothing was attempted).
     */
    public static function deliver(Action $a, ?User $user, WhatsAppService $wa, string $key = self::REQUEST_RECEIVED, ?int $version = null, ?string $scheduledLocal = null, ?string $message = null): array
    {
        $out = fn (bool $sent, ?string $status, string $message, bool $busy = false, ?string $refused = null) => compact('sent', 'status', 'message', 'busy', 'refused');
        if (! isset(self::templates()[$key])) {
            return $out(false, null, 'Unknown WhatsApp message. Nothing was sent.', refused: 'not_available');
        }
        $message = self::cleanMessage($message);
        if ($key === self::GENERAL && $message === '') {
            return $out(false, null, 'Type the message to send. Nothing was sent.', refused: 'not_available');
        }

        // Claim the send in one transaction that re-reads the action, so a change made after the
        // admin opened the message, or a parallel click, is caught before anything is sent.
        try {
            $claim = DB::transaction(function () use ($a, $user, $key, $version, $scheduledLocal, $message) {
                $fresh = Action::whereKey($a->id)->lockForUpdate()->firstOrFail();
                if (($version !== null && $fresh->version !== $version) || ($scheduledLocal !== null && ActionHub::local($fresh->scheduled_at) !== $scheduledLocal)) {
                    return ['changed' => true];
                }
                $state = self::templateState($fresh, $key);
                if (! $state['available']) {
                    return ['not_available' => $state['reason']];
                }
                // A General Message's occasion is its exact text (only a fingerprint is stored, never the text).
                $group = $key === self::GENERAL ? 'general:'.substr(hash('sha256', mb_strtolower($message)), 0, 40) : $state['group'];
                $msg = Message::where('action_id', $fresh->id)->where('dedupe_key', $group)->first()
                    ?? Message::create([
                        'action_id' => $fresh->id, 'ticket_no' => $fresh->ticket_no, 'service_type' => mb_substr(self::serviceType($fresh), 0, 40),
                        'template' => $key, 'dedupe_key' => $group, 'scheduled_for' => $state['scheduled_for'], 'status' => self::PENDING,
                    ]);
                if ($msg->status === self::ACCEPTED) {
                    return ['already' => $msg];
                }
                // Only one request can win, so it cannot be sent twice.
                $claimed = Message::whereKey($msg->id)
                    ->where(fn ($q) => $q->whereIn('status', [self::PENDING, self::FAILED, self::NOT_CONFIGURED])
                        ->orWhere(fn ($q) => $q->where('status', self::SENDING)->where('updated_at', '<', now()->subSeconds(self::STUCK_AFTER))))
                    ->update([
                        'status' => self::SENDING, 'template' => $key, 'scheduled_for' => $state['scheduled_for'],
                        'sent_by' => $user?->id, 'sent_by_name' => $user?->name,
                        'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now(), 'updated_at' => now(),
                    ]);

                return $claimed === 1 ? ['msg' => $msg->refresh(), 'action' => $fresh] : ['busy' => $msg->refresh()];
            });
        } catch (UniqueConstraintViolationException) {
            $claim = ['busy' => Message::where('action_id', $a->id)->latest('id')->first()]; // a parallel click created this occasion's row
        }

        if (isset($claim['changed'])) {
            return $out(false, null, 'This action changed after you opened the message (status, date or details). Nothing was sent — review the message again before sending.', refused: 'changed');
        }
        if (isset($claim['not_available'])) {
            return $out(false, null, self::label($key).' cannot be sent now: '.$claim['not_available'].' Nothing was sent.', refused: 'not_available');
        }
        $for = fn (?Message $m) => ($t = $m?->ticket_no ?? $a->ticket_no) ? "ticket {$t}" : 'this action';
        if (isset($claim['already'])) {
            $m = $claim['already'];
            $what = $m->template === $key ? 'this message' : '“'.self::label($m->template).'”, which covers the same occasion';

            return $out(true, self::ACCEPTED, "Already sent: WhatsApp accepted {$what} for {$for($m)}. Not sent again.");
        }
        if (isset($claim['busy'])) {
            $m = $claim['busy'];

            return $m?->status === self::ACCEPTED
                ? $out(true, self::ACCEPTED, "Already sent: {$for($m)}. Not sent again.")
                : $out(false, $m?->status, "The message for {$for($m)} is already being sent. Please wait.", true);
        }

        /** @var Message $msg */
        $msg = $claim['msg'];
        $fresh = $claim['action'];
        $to = self::recipient($fresh->phone);
        // Safety net below the endpoint (settings changed while a request was running).
        $problem = self::configProblem($wa) ?? self::templateProblem($key);
        $tpl = self::templates()[$key];
        if ($problem) {
            [$status, $error, $messageId] = [self::NOT_CONFIGURED, $problem.' The message was NOT sent.', null];
        } elseif (! $to) {
            [$status, $error, $messageId] = [self::FAILED, "The customer's phone number is not a valid 10-digit Indian mobile number. The message was NOT sent; correct the number and retry.", null];
        } else {
            $values = self::values($fresh, $msg->scheduled_for, $message);
            $meta = (string) ($tpl['meta'] ?? '');
            $r = $wa->sendMessage(
                $to, self::text($key, $values),
                $meta !== '' ? $meta : null, array_map(fn ($p) => $values[$p] ?? '', $tpl['params']),
                (string) config('action_hub.whatsapp.template_language', 'en'),
            );
            [$status, $error, $messageId] = [$r['accepted'] ? self::ACCEPTED : self::FAILED, $r['error'], $r['message_id']];
        }

        $msg->update([
            'status' => $status, 'error' => $error, 'message_id' => $messageId ?? $msg->message_id,
            'recipient_masked' => $to ? WhatsAppService::mask($to) : null,
            'accepted_at' => $status === self::ACCEPTED ? now() : null,
            'delivery_status' => null, 'delivery_error' => null, 'delivery_updated_at' => null,
        ]);
        ActionHub::log($fresh, 'whatsapp_'.$status, $user, [
            'reason' => $error,
            'details' => array_filter([
                'ticket' => $msg->ticket_no, 'message' => $key, 'message_label' => $tpl['label'], 'attempt' => $msg->attempts,
                'message_id' => $messageId, 'template' => ($tpl['meta'] ?? null) ?: 'text', 'to' => $to ? WhatsAppService::mask($to) : null,
                'scheduled_for' => ActionHub::local($msg->scheduled_for, 'd M Y, H:i'),
            ], fn ($v) => $v !== null),
        ]);

        return $out(
            $status === self::ACCEPTED,
            $status,
            $status === self::ACCEPTED
                ? "WhatsApp message “{$tpl['label']}” sent to the customer ({$msg->recipient_masked})".($msg->ticket_no ? " with ticket {$msg->ticket_no}" : '').'. WhatsApp accepted it for delivery (not yet confirmed as delivered).'
                : "WhatsApp message “{$tpl['label']}” for {$for($msg)} NOT sent: {$error}",
        );
    }

    /** API shape (no message text, masked number only). */
    public static function present(?Message $m): ?array
    {
        if (! $m) {
            return null;
        }

        return [
            'id' => $m->id,
            'template' => $m->template,
            'template_label' => self::label($m->template),
            'status' => $m->status,
            'error' => $m->error,
            'recipient' => $m->recipient_masked,
            'attempts' => $m->attempts,
            'last_attempt_at' => ActionHub::iso($m->last_attempt_at),
            'accepted_at' => ActionHub::iso($m->accepted_at),
            'scheduled_for' => ActionHub::local($m->scheduled_for),
            'sent_by' => $m->sent_by_name,
            'delivery_status' => $m->delivery_status,
            'delivery_error' => $m->delivery_error,
            'delivery_updated_at' => ActionHub::iso($m->delivery_updated_at),
            // This message may be (re)sent: nothing accepted yet and no send running.
            'can_send' => $canSend = in_array($m->status, [self::PENDING, self::FAILED, self::NOT_CONFIGURED], true)
                || ($m->status === self::SENDING && $m->updated_at?->lt(now()->subSeconds(self::STUCK_AFTER))),
            'can_retry' => $canSend && $m->attempts > 0,
        ];
    }
}
