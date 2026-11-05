<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Leads workflow (config/leads.php): Lead IDs, the 14 statuses, lifecycle (active / closed /
 * archived), Red/Amber/Green from the stored dates, history and WhatsApp drafts.
 *
 * Everything here is server-side: the RAG colour and the Lead ID are never taken from a client.
 */
final class LeadWorkflow
{
    public const RECEIVED = 'LEAD-RCVD';

    public const NO_RESPONSE_CLOSE = 'ATT-3-CALL-LATER';

    public const ATTEMPTS = ['ATT-1-NP', 'ATT-1-CALL-LATER', 'ATT-2-NP', 'ATT-2-CALL-LATER', 'ATT-3-NP', 'ATT-3-CALL-LATER'];

    /** Statuses whose RAG follows the (re)scheduled measurement date; a date is required. */
    public const SCHEDULED = ['MSRMT-SCH', 'MSRMT-INC', 'MSRMT-DLY-RESCLD-ID', 'MSRMT-DLY-RESCLD-CD'];

    public const FULL = 'MSRMT-FULL';

    public const ABORTS = ['MSRMT-ABRT-CL', 'MSRMT-ABRT-PV'];

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    public const ARCHIVED = 'archived';

    public const GET_READY = 'Get ready';

    public const QUOTATION = 'Discuss quotation with Customer';

    /** List tabs on the Leads page (the archive is its own tab). */
    public const TABS = ['active', 'new', 'in_progress', 'measured', 'closed', 'archived'];

    /** @return array<string, string> code => label, in the required order */
    public static function statuses(): array
    {
        return (array) config('leads.statuses');
    }

    public static function label(?string $code): string
    {
        return self::statuses()[$code] ?? (string) $code;
    }

    public static function timezone(): string
    {
        return (string) config('leads.timezone', 'Asia/Kolkata');
    }

    /** @return string[] the Action Hub "Assigned To" names (same list, same rules). */
    public static function assignees(): array
    {
        return (array) config('action_hub.assignees', []);
    }

    private static function weeklyOff(): array
    {
        return array_map('intval', (array) config('leads.weekly_off', [0, 6]));
    }

    // ── Red / Amber / Green ─────────────────────────────────────────────

    /**
     * @return array{rag: 'red'|'amber'|'green'|'neutral', message: ?string}
     *
     * Dates are compared as calendar days in the business time zone, so the result does not depend
     * on the viewer's browser or time zone.
     */
    public static function rag(Lead $lead, ?CarbonInterface $now = null): array
    {
        $code = (string) $lead->status_code;
        $today = CarbonImmutable::instance($now ?? now())->setTimezone(self::timezone())->startOfDay();

        if ($lead->lifecycle === self::ARCHIVED || in_array($code, self::ABORTS, true)) {
            return ['rag' => 'neutral', 'message' => 'Archived'];
        }
        if ($code === self::NO_RESPONSE_CLOSE || $lead->lifecycle === self::CLOSED) {
            return ['rag' => 'red', 'message' => (string) config('leads.closed_no_response')];
        }
        if ($code === self::RECEIVED || in_array($code, self::ATTEMPTS, true)) {
            return ['rag' => 'red', 'message' => null];
        }
        if (in_array($code, self::SCHEDULED, true)) {
            if (! $lead->scheduled_at) {
                return ['rag' => 'red', 'message' => 'Measurement date missing'];
            }
            $day = CarbonImmutable::instance($lead->scheduled_at)->setTimezone(self::timezone())->startOfDay();
            if ($today->gt($day)) {
                return ['rag' => 'red', 'message' => 'Measurement overdue'];   // from the day after the scheduled date
            }
            $within = WorkingDays::countBetween($today, $day, self::weeklyOff());

            return $within <= (int) config('leads.get_ready_working_days', 2)
                ? ['rag' => 'amber', 'message' => self::GET_READY]                // includes the scheduled date itself
                : ['rag' => 'green', 'message' => null];
        }
        if ($code === self::FULL) {
            if (! $lead->measured_at) {
                return ['rag' => 'red', 'message' => self::QUOTATION];
            }
            $measured = CarbonImmutable::instance($lead->measured_at)->setTimezone(self::timezone())->startOfDay();
            $greenUntil = WorkingDays::add($measured, (int) config('leads.quotation_working_days', 2), self::weeklyOff());

            return $today->gt($greenUntil)
                ? ['rag' => 'red', 'message' => self::QUOTATION]
                : ['rag' => 'green', 'message' => null];
        }

        return ['rag' => 'red', 'message' => null];
    }

    /** Which list tab a lead belongs to (besides "active", which is every active lead). */
    public static function tab(Lead $lead): string
    {
        if ($lead->lifecycle !== self::ACTIVE) {
            return $lead->lifecycle;
        }

        return match (true) {
            $lead->status_code === self::RECEIVED => 'new',
            $lead->status_code === self::FULL => 'measured',
            default => 'in_progress',
        };
    }

    /** Coarse status kept in the legacy `status` column and shown to referral partners. */
    public static function legacyStatus(string $code): ?string
    {
        return match (true) {
            $code === self::RECEIVED => 'new',
            in_array($code, self::ATTEMPTS, true) => 'contacted',
            in_array($code, self::SCHEDULED, true) => 'scheduled',
            $code === self::FULL => 'completed',
            default => null, // aborted: the legacy column keeps its last value
        };
    }

    public static function partnerStatus(Lead $lead): string
    {
        if ($lead->lifecycle !== self::ACTIVE) {
            return 'Closed';
        }

        return match (self::legacyStatus((string) $lead->status_code)) {
            'contacted' => 'Contacted',
            'scheduled' => 'Scheduled',
            'completed' => 'Measured',
            default => 'New',
        };
    }

    // ── Lead IDs ────────────────────────────────────────────────────────

    /**
     * Next PV-LD-YYMM-NNN for the month of $now (business time zone). Must run inside the transaction
     * that stores it: the counter row is written first, which takes the row lock (MySQL) / database
     * write lock (SQLite), so two requests can never read the same serial. Serials only go up, so an
     * ID is never reused, even after a lead is deleted. The unique index on leads.lead_no is the backstop.
     */
    public static function nextLeadNo(CarbonInterface $now): string
    {
        $period = CarbonImmutable::instance($now)->setTimezone(self::timezone())->format('ym');
        $table = DB::table('lead_id_counters');
        $table->clone()->insertOrIgnore(['period' => $period, 'last_serial' => 0, 'created_at' => now(), 'updated_at' => now()]);
        do {
            $table->clone()->where('period', $period)->increment('last_serial', 1, ['updated_at' => now()]);
            $no = sprintf('%s-%s-%03d', config('leads.id_prefix', 'PV-LD'), $period, (int) $table->clone()->where('period', $period)->value('last_serial'));
        } while (Lead::where('lead_no', $no)->exists());

        return $no;
    }

    // ── Create / update ─────────────────────────────────────────────────

    /**
     * New lead: always LEAD-RCVD (Red), active, with a server-generated Lead ID.
     *
     * @param array{client_name: string, client_phone: string, type: string, notes?: ?string,
     *              referral_partner_id?: ?int, assigned_to?: ?string, to_partner?: bool, to_customer?: bool} $in
     */
    public static function create(array $in, ?User $by, bool $demo = false, ?CarbonInterface $now = null): Lead
    {
        $now ??= now();

        return DB::transaction(function () use ($in, $by, $demo, $now) {
            $lead = new Lead([
                'referral_partner_id' => $in['referral_partner_id'] ?? null,
                'client_name' => trim((string) $in['client_name']),
                'client_phone' => (string) $in['client_phone'],
                'type' => (string) $in['type'],
                'notes' => self::cleanNotes($in['notes'] ?? null),
                'assigned_to' => $in['assigned_to'] ?? null,
                'to_partner' => (bool) ($in['to_partner'] ?? false),
                'to_customer' => (bool) ($in['to_customer'] ?? false),
            ]);
            $lead->status_code = self::RECEIVED;
            $lead->status = 'new';
            $lead->lifecycle = self::ACTIVE;
            $lead->lead_no = self::nextLeadNo($now);
            $lead->is_demo = $demo;
            $lead->version = 1; // the first edit must send this version
            $lead->created_by = $by?->id;
            $lead->updated_by = $by?->id;
            $lead->created_at = $now;
            $lead->updated_at = $now;
            $lead->save();

            self::log($lead, 'created', $by, ['new_status' => self::RECEIVED, 'new_assignee' => $lead->assigned_to], $now);

            return $lead;
        });
    }

    /**
     * Edit a lead (office). Version-checked: a stale edit is refused (409) instead of overwriting
     * someone else's change. Status rules: ATT-3-CALL-LATER closes the lead ("Closed Lead - No
     * response"); both aborts archive it; any other status on a closed/archived lead reopens it.
     * Nothing is ever deleted; every change is written to the history.
     *
     * @param array<string, mixed> $in validated fields (see Admin\LeadController::rules)
     * @throws LeadConflict
     */
    public static function update(Lead $lead, array $in, ?User $by, ?CarbonInterface $now = null): Lead
    {
        $now ??= now();

        return DB::transaction(function () use ($lead, $in, $by, $now) {
            /** @var Lead $l */
            $l = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('version', $in) && (int) $in['version'] !== (int) $l->version) {
                throw new LeadConflict('This lead was changed by someone else since you opened it. Reload the lead and make your change again.');
            }

            $old = ['status' => $l->status_code, 'scheduled' => $l->scheduled_at, 'assignee' => $l->assigned_to, 'notes' => $l->notes, 'lifecycle' => $l->lifecycle];
            $changed = [];
            foreach (['client_name', 'client_phone', 'type', 'referral_partner_id', 'to_partner', 'to_customer'] as $f) {
                if (array_key_exists($f, $in)) {
                    $value = $f === 'client_name' ? trim((string) $in[$f]) : $in[$f];
                    if ($l->{$f} != $value) {
                        $changed[] = $f;
                    }
                    $l->{$f} = $value;
                }
            }
            if (array_key_exists('assigned_to', $in)) {
                $l->assigned_to = $in['assigned_to'] ?: null;
            }
            if (array_key_exists('notes', $in)) {
                $l->notes = self::cleanNotes($in['notes']);
            }

            $code = (string) ($in['status_code'] ?? $l->status_code);
            $l->status_code = $code;
            if ($legacy = self::legacyStatus($code)) {
                $l->status = $legacy;
            }
            if (in_array($code, self::SCHEDULED, true) && array_key_exists('scheduled_at', $in)) {
                $l->scheduled_at = self::parseLocal($in['scheduled_at']);
            }
            if ($code === self::FULL) {
                $l->measured_at = array_key_exists('measured_at', $in) && $in['measured_at']
                    ? self::parseLocal($in['measured_at'])
                    : ($l->measured_at ?? $now);
            }

            // Lifecycle from the status.
            $event = null;
            if ($code === self::NO_RESPONSE_CLOSE) {
                if ($l->lifecycle !== self::CLOSED) {
                    [$l->lifecycle, $l->closure_reason, $l->closed_at, $event] = [self::CLOSED, (string) config('leads.closed_no_response'), $now, 'closed'];
                }
            } elseif (in_array($code, self::ABORTS, true)) {
                if ($l->lifecycle !== self::ARCHIVED) {
                    [$l->lifecycle, $l->closure_reason, $l->closed_at, $event] = [self::ARCHIVED, self::label($code), $now, 'archived'];
                }
            } elseif ($l->lifecycle !== self::ACTIVE) {
                [$l->lifecycle, $l->closure_reason, $l->closed_at, $event] = [self::ACTIVE, null, null, 'reopened'];
            }

            $l->version = (int) $l->version + 1;
            $l->updated_by = $by?->id;
            $l->updated_at = $now;
            $l->save();

            if ($old['status'] !== $code) {
                self::log($l, 'status_changed', $by, ['old_status' => $old['status'], 'new_status' => $code], $now);
            }
            if ($event) {
                self::log($l, $event, $by, ['old_status' => $old['status'], 'new_status' => $code, 'details' => ['reason' => $l->closure_reason, 'from' => $old['lifecycle']]], $now);
            }
            if (! self::sameMoment($old['scheduled'], $l->scheduled_at)) {
                self::log($l, $old['scheduled'] ? 'rescheduled' : 'scheduled', $by, [
                    'old_scheduled_at' => $old['scheduled'], 'new_scheduled_at' => $l->scheduled_at, 'new_status' => $code,
                ], $now);
            }
            if ($old['assignee'] !== $l->assigned_to) {
                self::log($l, 'assigned', $by, ['old_assignee' => $old['assignee'], 'new_assignee' => $l->assigned_to], $now);
            }
            if ((string) $old['notes'] !== (string) $l->notes) {
                self::log($l, 'note_edited', $by, ['old_notes' => $old['notes']], $now);  // the earlier text is kept here
            }
            if ($changed) {
                self::log($l, 'edited', $by, ['details' => ['fields' => $changed]], $now);
            }

            return $l;
        });
    }

    private static function cleanNotes(mixed $notes): ?string
    {
        $text = trim(str_replace("\r\n", "\n", (string) $notes));

        return $text === '' ? null : $text;
    }

    private static function sameMoment(?CarbonInterface $a, ?CarbonInterface $b): bool
    {
        return ($a === null && $b === null) || ($a !== null && $b !== null && $a->equalTo($b));
    }

    /** "2026-11-05T10:30" entered in the business time zone → UTC moment. */
    public static function parseLocal(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, self::timezone())->utc() : null;
    }

    public static function local(?CarbonInterface $t, string $format = 'Y-m-d\TH:i'): ?string
    {
        return $t ? CarbonImmutable::instance($t)->setTimezone(self::timezone())->format($format) : null;
    }

    public static function log(Lead $lead, string $event, ?User $by, array $data = [], ?CarbonInterface $now = null): LeadEvent
    {
        return LeadEvent::create($data + [
            'lead_id' => $lead->id,
            'event' => $event,
            'user_id' => $by?->id,
            'user_name' => $by?->name,
            'created_at' => $now ?? now(),
        ]);
    }

    // ── Lists and output ────────────────────────────────────────────────

    public static function query(string $tab, ?string $search = null): Builder
    {
        $q = Lead::query()->with(['partner:id,name,phone,company', 'measurement:id,lead_id,status']);
        match ($tab) {
            'closed' => $q->where('lifecycle', self::CLOSED),
            'archived' => $q->where('lifecycle', self::ARCHIVED),
            'new' => $q->where('lifecycle', self::ACTIVE)->where('status_code', self::RECEIVED),
            'measured' => $q->where('lifecycle', self::ACTIVE)->where('status_code', self::FULL),
            'in_progress' => $q->where('lifecycle', self::ACTIVE)->whereNotIn('status_code', [self::RECEIVED, self::FULL]),
            default => $q->where('lifecycle', self::ACTIVE),
        };
        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';
            $q->where(fn (Builder $w) => $w->where('client_name', 'like', $like)->orWhere('client_phone', 'like', $like)->orWhere('lead_no', 'like', $like));
        }

        return $q;
    }

    /** @return array<string, int> counts per tab, from the stored records */
    public static function counts(): array
    {
        $rows = Lead::query()->selectRaw('lifecycle, status_code, count(*) as n')->groupBy('lifecycle', 'status_code')->get();
        $out = array_fill_keys(self::TABS, 0);
        foreach ($rows as $r) {
            $n = (int) $r->n;
            if ($r->lifecycle === self::ACTIVE) {
                $out['active'] += $n;
                $out[$r->status_code === self::RECEIVED ? 'new' : ($r->status_code === self::FULL ? 'measured' : 'in_progress')] += $n;
            } elseif (isset($out[$r->lifecycle])) {
                $out[$r->lifecycle] += $n;
            }
        }

        return $out;
    }

    public static function present(Lead $l, ?CarbonInterface $now = null): array
    {
        return [
            'id' => $l->id,
            'lead_no' => $l->lead_no,
            'client_name' => $l->client_name,
            'client_phone' => $l->client_phone,
            'type' => $l->type,
            'notes' => $l->notes,
            'status' => $l->status,
            'status_code' => $l->status_code,
            'status_label' => self::label($l->status_code),
            'rag' => self::rag($l, $now),
            'tab' => self::tab($l),
            'assigned_to' => $l->assigned_to,
            'scheduled_at' => self::local($l->scheduled_at),
            'measured_at' => self::local($l->measured_at),
            'lifecycle' => $l->lifecycle,
            'closure_reason' => $l->closure_reason,
            'closed_at' => $l->closed_at?->toIso8601String(),
            'to_partner' => (bool) $l->to_partner,
            'to_customer' => (bool) $l->to_customer,
            'version' => (int) $l->version,
            'is_demo' => (bool) $l->is_demo,
            'referral_partner_id' => $l->referral_partner_id,
            'partner' => $l->partner ? ['id' => $l->partner->id, 'name' => $l->partner->name, 'phone' => $l->partner->phone, 'company' => $l->partner->company] : null,
            'measurement_id' => $l->measurement?->id,
            'measurement_status' => $l->measurement?->status,
            'created_at' => $l->created_at?->toIso8601String(),
            'updated_at' => $l->updated_at?->toIso8601String(),
        ];
    }

    public static function presentEvent(LeadEvent $e): array
    {
        return [
            'id' => $e->id,
            'event' => $e->event,
            'user' => $e->user_name,
            'at' => $e->created_at?->toIso8601String(),
            'old_status' => $e->old_status ? self::label($e->old_status) : null,
            'new_status' => $e->new_status ? self::label($e->new_status) : null,
            'old_scheduled_at' => self::local($e->old_scheduled_at),
            'new_scheduled_at' => self::local($e->new_scheduled_at),
            'old_assignee' => $e->old_assignee,
            'new_assignee' => $e->new_assignee,
            'old_notes' => $e->old_notes,
            'details' => $e->details,
        ];
    }

    // ── WhatsApp drafts ─────────────────────────────────────────────────

    /**
     * Messages for the chosen recipients (click-to-chat drafts, the same workflow as Action Hub and
     * Receivables: the admin opens the chat and presses Send in WhatsApp — nothing is sent from the
     * server, so nothing is ever reported as sent).
     *
     * @return array{recipients: list<array{kind: string, name: string, phone: string, wa_number: ?string, text: string}>, problem: ?string}
     */
    public static function whatsappDrafts(Lead $l, bool $toPartner, bool $toCustomer, ?CarbonInterface $now = null): array
    {
        if (! $toPartner && ! $toCustomer) {
            return ['recipients' => [], 'problem' => 'Select at least one recipient'];
        }
        if ($toPartner && (! $l->partner || ! preg_match('/^\d{10}$/', (string) $l->partner->phone))) {
            return ['recipients' => [], 'problem' => 'This lead has no referring partner with a WhatsApp number. Untick "To Partner" or set Referred By.'];
        }
        if ($toCustomer && ! preg_match('/^\d{10}$/', (string) $l->client_phone)) {
            return ['recipients' => [], 'problem' => 'The customer phone number is missing or not a 10-digit mobile number.'];
        }

        $when = $l->scheduled_at ? self::local($l->scheduled_at, 'd M Y').' at '.self::local($l->scheduled_at, 'h:i A') : null;
        $out = [];
        if ($toCustomer) {
            $out[] = self::recipient($l, 'customer', $l->client_name, $l->client_phone, self::customerText($l, $when));
        }
        if ($toPartner) {
            $out[] = self::recipient($l, 'partner', $l->partner->name, $l->partner->phone, self::partnerText($l, $when));
        }

        return ['recipients' => $out, 'problem' => null];
    }

    private static function recipient(Lead $l, string $kind, string $name, string $phone, string $text): array
    {
        return [
            'kind' => $kind,
            'name' => $name,
            'phone' => $phone,
            // Demo records: the chat opens WITHOUT a recipient, so a fictional number is never messaged.
            'wa_number' => $l->is_demo ? null : config('action_hub.whatsapp.country_code', '91').$phone,
            'text' => $text,
        ];
    }

    private static function customerText(Lead $l, ?string $when): string
    {
        $code = (string) $l->status_code;
        $line = match (true) {
            $code === self::RECEIVED => 'We have received your enquiry. Our team will contact you shortly.',
            str_ends_with($code, '-NP') => 'We tried to reach you but could not get through. Please let us know a convenient time to call you.',
            str_ends_with($code, '-CALL-LATER') => 'As requested, we will call you back at a convenient time.',
            $code === 'MSRMT-SCH' => "Your site measurement is scheduled for {$when}.",
            $code === 'MSRMT-INC' => "Your site measurement was partly completed. The next visit is scheduled for {$when}.",
            $code === 'MSRMT-DLY-RESCLD-ID' => "Due to internal constraints, your site measurement has been rescheduled to {$when}. We regret the inconvenience.",
            $code === 'MSRMT-DLY-RESCLD-CD' => "As requested, your site measurement has been rescheduled to {$when}.",
            $code === self::FULL => 'Your site measurement has been completed. Our team will share the quotation shortly.',
            $code === 'MSRMT-ABRT-CL' => 'As requested, we have closed your enquiry. Please contact us whenever you would like to proceed.',
            $code === 'MSRMT-ABRT-PV' => 'We are unable to proceed with your enquiry at this time. Thank you for considering Prestige Ventures.',
            default => 'Thank you for your enquiry.',
        };

        return "Dear {$l->client_name},\n\n{$line}\n\nLead ID: {$l->lead_no}\n\nFor any changes, please contact us on WhatsApp at 6360474741.\n\nRegards,\nPrestige Ventures - Admin";
    }

    private static function partnerText(Lead $l, ?string $when): string
    {
        $schedule = in_array($l->status_code, self::SCHEDULED, true) && $when ? "\nMeasurement: {$when}" : '';

        return "Dear {$l->partner->name},\n\nUpdate on your referral {$l->client_name} (Lead ID: {$l->lead_no}):\nStatus: ".self::label($l->status_code)."{$schedule}\n\nThank you for the referral.\n\nRegards,\nPrestige Ventures - Admin";
    }

    /** Recorded when drafts are prepared (who, which recipient kinds) — never numbers or message text. */
    public static function logWhatsapp(Lead $l, array $kinds, ?User $by): void
    {
        self::log($l, 'whatsapp_draft', $by, ['new_status' => $l->status_code, 'details' => ['recipients' => $kinds, 'mode' => 'draft']]);
    }
}
