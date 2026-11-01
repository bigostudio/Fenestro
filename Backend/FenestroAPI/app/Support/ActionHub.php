<?php

namespace App\Support;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\ActionHub\Holiday;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Action Hub rules (config/action_hub.php). All of them run on the server, so
 * every browser and user sees the same status:
 *
 *  - Working day: not a weekly off day (Sunday) and not in the holiday list.
 *  - Get Ready: from the start (local time) of the first of the last N working
 *    days before the appointment's local date (N = get_ready_working_days).
 *  - Delayed: the scheduled date AND time have passed while still pending.
 *  - Automatic changes only move forward (On Track → Get Ready → Delayed) and
 *    never touch Completed or Abort. A new date (Reschedule) recalculates the
 *    status from scratch; the old date stays in the audit history.
 *
 * Statuses are refreshed when Action Hub data is read or saved and by the
 * scheduled command action-hub:refresh-statuses. Refreshing is idempotent:
 * an automatic change is applied (and logged) once, guarded by the record's
 * version and a unique key on the audit row.
 */
class ActionHub
{
    public const ON_TRACK = 'On Track';

    public const GET_READY = 'Get Ready';

    public const DELAYED = 'Delayed';

    public const COMPLETED = 'Completed';

    public const ABORT = 'Abort';

    public const RESCHEDULE = 'Reschedule';

    public const TERMINAL = [self::COMPLETED, self::ABORT];

    /** Statuses the automatic rules manage, in the only order they may move. */
    private const RANK = [self::ON_TRACK => 0, self::GET_READY => 1, self::DELAYED => 2];

    public const OVERDUE_MESSAGE = 'Appointment overdue — reschedule this project.';

    /** @var array<string, string>|null date → holiday name, loaded once per request */
    private static ?array $holidays = null;

    public static function timezone(): string
    {
        return (string) config('action_hub.timezone', 'Asia/Kolkata');
    }

    public static function forgetHolidays(): void
    {
        self::$holidays = null;
    }

    /** @return array<string, string> */
    public static function holidays(): array
    {
        return self::$holidays ??= Holiday::orderBy('date')->get()
            ->mapWithKeys(fn (Holiday $h) => [$h->date->format('Y-m-d') => $h->name])->all();
    }

    public static function isWorkingDay(CarbonInterface $localDate): bool
    {
        return ! in_array($localDate->dayOfWeek, (array) config('action_hub.weekly_off', [0]), true)
            && ! array_key_exists($localDate->format('Y-m-d'), self::holidays());
    }

    /** Start (local midnight) of the Get Ready period for an appointment, as a UTC moment. */
    public static function getReadyFrom(CarbonInterface $scheduledAt): CarbonImmutable
    {
        $day = CarbonImmutable::instance($scheduledAt)->setTimezone(self::timezone())->startOfDay();
        $needed = max(0, (int) config('action_hub.get_ready_working_days', 2));
        for ($found = 0, $guard = 0; $found < $needed && $guard < 400; $guard++) {
            $day = $day->subDay();
            if (self::isWorkingDay($day)) {
                $found++;
            }
        }

        return $day->utc();
    }

    /** What the rules say the status is now, ignoring any earlier automatic state. */
    public static function calculated(CarbonInterface $scheduledAt, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        if ($now->gte($scheduledAt)) {
            return self::DELAYED;
        }

        return $now->gte(self::getReadyFrom($scheduledAt)) ? self::GET_READY : self::ON_TRACK;
    }

    public static function isOverdue(Action $a, ?CarbonInterface $now = null): bool
    {
        return ! in_array($a->status, self::TERMINAL, true) && ($now ?? now())->gte($a->scheduled_at);
    }

    // ── Display helpers ────────────────────────────────────────────────

    /** Web links and e-mail addresses inside free text: kept exactly as typed. */
    private const KEEP_CASE = '~(https?://\S+|www\.\S+|[^\s@<>()"\']+@[^\s@<>()"\']+\.[A-Za-z]{2,})~iu';

    /**
     * Description in capital letters. Punctuation, numbers and symbols are
     * unchanged, and so are links and e-mail addresses, which can stop working
     * when upper-cased. Also applied when old records are read, so no data
     * migration is needed.
     */
    public static function upperText(?string $value): string
    {
        $parts = preg_split(self::KEEP_CASE, trim((string) $value), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return mb_strtoupper(trim((string) $value));
        }
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) { // even = ordinary text; odd = a captured link / e-mail
                $parts[$i] = mb_strtoupper($part);
            }
        }

        return implode('', $parts);
    }

    /** Text shown for a stored action value ("Installation" → "Scheduled Installation"). */
    public static function actionLabel(?string $value): string
    {
        return (string) (config('action_hub.action_labels', [])[$value] ?? $value);
    }

    /** Stored value for an action given either as its value or as its label (old API clients and new labels both work). */
    public static function actionValue(mixed $given): mixed
    {
        if (! is_string($given) || in_array($given, (array) config('action_hub.actions'), true)) {
            return $given;
        }
        $value = array_search($given, (array) config('action_hub.action_labels', []), true);

        return $value === false ? $given : $value;
    }

    private static function later(string $a, string $b): string
    {
        return (self::RANK[$a] ?? 0) >= (self::RANK[$b] ?? 0) ? $a : $b;
    }

    // ── Automatic refresh ──────────────────────────────────────────────

    /**
     * Apply due automatic changes (On Track → Get Ready, → Delayed) to pending
     * actions. Returns the number of actions changed. Safe to run repeatedly
     * and concurrently: each change is applied only if the record is still at
     * the version that was read, and its audit row has a unique key.
     */
    public static function refresh(?CarbonInterface $now = null): int
    {
        $now ??= now();
        self::forgetHolidays();
        $changed = 0;
        Action::whereIn('status', [self::ON_TRACK, self::GET_READY])
            ->orderBy('id')
            ->chunkById(200, function ($actions) use ($now, &$changed) {
                foreach ($actions as $a) {
                    $target = self::later($a->status, self::calculated($a->scheduled_at, $now));
                    if ($target !== $a->status && self::applyAutomatic($a, $target, $now)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    private static function applyAutomatic(Action $a, string $target, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($a, $target, $now) {
            $updated = Action::whereKey($a->id)->where('version', $a->version)->where('status', $a->status)
                ->update(['status' => $target, 'status_changed_at' => $now, 'version' => $a->version + 1, 'updated_at' => $now]);
            if ($updated !== 1) {
                return false; // changed meanwhile (another request or scheduler run)
            }
            self::log($a, $target === self::DELAYED ? 'auto_delayed' : 'auto_get_ready', null, [
                'source' => 'automatic',
                'old_status' => $a->status,
                'new_status' => $target,
                'old_scheduled_at' => $a->scheduled_at,
                'new_scheduled_at' => $a->scheduled_at,
                'reason' => $target === self::DELAYED ? self::OVERDUE_MESSAGE : 'Within '.config('action_hub.get_ready_working_days', 2).' working days of the appointment.',
                'dedupe_key' => 'auto:'.$a->id.':'.$a->version.':'.str_replace(' ', '_', $target),
            ]);

            return true;
        });
    }

    // ── Saving (create / Submit) ───────────────────────────────────────

    /**
     * Create (no $action) or update an action from validated input and write
     * its audit events in the same transaction. $requestId makes a retried
     * Submit return the first result instead of saving twice.
     *
     * @return array{action: Action, replayed: bool}
     *
     * @throws ActionHubRejected for a stale version or a status change the rules do not allow
     */
    public static function save(?Action $action, array $in, User $user, string $requestId): array
    {
        $submitKey = 'submit:'.$requestId;
        if ($done = ActionEvent::where('dedupe_key', $submitKey)->first()) {
            return ['action' => Action::findOrFail($done->action_id), 'replayed' => true];
        }
        self::forgetHolidays();
        self::holidays(); // read before the transaction, so its first statement is a write (SQLite locking)

        try {
            return DB::transaction(function () use ($action, $in, $user, $submitKey) {
                $now = now();
                $scheduled = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $in['scheduled_at'], self::timezone())->startOfMinute()->utc();
                $fields = [
                    'customer' => OrderManagement::upper($in['customer']),
                    'project' => OrderManagement::upper($in['project']),
                    'phone' => $in['phone'],
                    'description' => self::upperText($in['description'] ?? '') ?: null,
                    'action_type' => $in['action_type'],
                    'action_other' => $in['action_type'] === 'Other' ? trim((string) $in['action_other']) : null,
                    'assigned_to' => $in['assigned_to'],
                ];
                $requested = $in['status'] ?? self::ON_TRACK;
                $reason = trim((string) ($in['reason'] ?? '')) ?: null;

                return $action
                    ? self::update(Action::whereKey($action->id)->lockForUpdate()->firstOrFail(), $fields, $scheduled, $requested, $reason, (int) $in['version'], $user, $submitKey, $now)
                    : self::create($fields, $scheduled, $requested, $user, $submitKey, $now);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same Submit finished in a parallel request: return that result.
            $done = ActionEvent::where('dedupe_key', $submitKey)->first();
            if (! $done) {
                throw $e;
            }

            return ['action' => Action::findOrFail($done->action_id), 'replayed' => true];
        }
    }

    private static function create(array $fields, CarbonImmutable $scheduled, string $requested, User $user, string $submitKey, CarbonInterface $now): array
    {
        if (! in_array($requested, [self::ON_TRACK, self::GET_READY, self::COMPLETED, self::ABORT], true)) {
            throw new ActionHubRejected("A new action can start as On Track (default), Get Ready, Completed or Abort — not {$requested}.");
        }
        // A new action cannot be scheduled in the past (the current minute counts as passed), whatever its status.
        if ($scheduled->lte($now)) {
            throw new ActionHubRejected(
                'The scheduled date and time ('.self::local($scheduled, 'd M Y, H:i').') have already passed. Choose a date and time in the future.',
                422, 'transition_rejected', ['scheduled_at'],
            );
        }
        $status = in_array($requested, self::TERMINAL, true) ? $requested : self::later($requested, self::calculated($scheduled, $now));

        $a = Action::create($fields + [
            'scheduled_at' => $scheduled, 'status' => $status, 'status_changed_at' => $now, 'version' => 1,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        // Snag / Service / Scheduled Measurements / Scheduled Installation: ticket number now, in this
        // transaction. Saving never sends WhatsApp (ActionHubController::sendWhatsapp does, on request).
        ActionHubWhatsapp::assignTicket($a, $now);
        $base = ['old_status' => null, 'new_status' => $status, 'new_scheduled_at' => $scheduled, 'new_assignee' => $a->assigned_to];
        self::log($a, 'created', $user, [...$base, 'details' => array_filter(['requested_status' => $requested, 'action_type' => $a->action_type, 'ticket' => $a->ticket_no])]);
        if ($status !== self::ON_TRACK) {
            // Started as Get Ready / Delayed by the rules (date already close or past), or chosen by the admin.
            $automatic = $requested !== $status;
            self::log($a, $automatic ? ($status === self::DELAYED ? 'auto_delayed' : 'auto_get_ready') : self::statusEvent($status), $automatic ? null : $user, [
                ...$base,
                'source' => $automatic ? 'automatic' : 'manual',
                'old_status' => self::ON_TRACK,
                'reason' => $automatic && $status === self::DELAYED ? self::OVERDUE_MESSAGE : null,
            ]);
        }
        self::log($a, 'submitted', $user, [...$base, 'dedupe_key' => $submitKey, 'details' => ['result' => 'created', 'version' => 1]]);

        return ['action' => $a, 'replayed' => false];
    }

    private static function update(Action $a, array $fields, CarbonImmutable $scheduled, string $requested, ?string $reason, int $version, User $user, string $submitKey, CarbonInterface $now): array
    {
        if ($version !== $a->version) {
            throw new ActionHubRejected('This action was changed by someone else (or by the automatic status rules) after you opened it. Your changes were not saved — reload it and submit again.', 409, 'stale_rejected');
        }

        $old = $a->replicate();
        $dateChanged = ! $scheduled->equalTo($a->scheduled_at);
        $status = self::nextStatus($a, $requested, $scheduled, $dateChanged, $now);
        // An eligible action saved without a ticket (older record, or changed to an eligible type) gets one now.
        $needsTicket = ! $a->ticket_no && ActionHubWhatsapp::spec($fields['action_type']) !== null;

        $changed = array_keys(array_filter($fields, fn ($v, $k) => (string) $v !== (string) $a->{$k}, ARRAY_FILTER_USE_BOTH));
        if ($dateChanged) {
            $changed[] = 'scheduled_at';
        }
        if ($status !== $a->status) {
            $changed[] = 'status';
        }
        if ($needsTicket) {
            $changed[] = 'ticket_no';
        }

        $a->fill($fields + ['scheduled_at' => $scheduled, 'status' => $status, 'updated_by' => $user->id]);
        if ($changed) {
            if ($status !== $old->status) {
                $a->status_changed_at = $now;
            }
            $a->version = $a->version + 1;
            $a->save();
        }
        if ($needsTicket) {
            ActionHubWhatsapp::assignTicket($a, $now);
        }

        $base = [
            'old_status' => $old->status, 'new_status' => $a->status,
            'old_scheduled_at' => $old->scheduled_at, 'new_scheduled_at' => $a->scheduled_at,
            'old_assignee' => $old->assigned_to, 'new_assignee' => $a->assigned_to,
        ];
        if ($dateChanged) {
            if ($requested === self::RESCHEDULE) {
                self::log($a, 'reschedule_requested', $user, [...$base, 'new_scheduled_at' => null, 'new_status' => self::RESCHEDULE, 'reason' => $reason]);
            }
            self::log($a, 'rescheduled', $user, [...$base, 'reason' => $reason, 'details' => ['was_overdue' => $now->gte($old->scheduled_at) && ! in_array($old->status, self::TERMINAL, true)]]);
        }
        if ($status !== $old->status) {
            self::log($a, self::statusEvent($status), $user, [...$base,
                'reason' => $dateChanged ? 'Status recalculated for the new appointment date.' : $reason,
                'details' => ['requested_status' => $requested],
            ]);
        }
        if ($old->assigned_to !== $a->assigned_to) {
            self::log($a, 'assignee_changed', $user, $base);
        }
        if ($needsTicket) {
            self::log($a, 'ticket_assigned', $user, [...$base, 'details' => ['ticket' => $a->ticket_no, 'action_type' => $a->action_type]]);
        }
        $edited = array_values(array_diff($changed, ['status', 'scheduled_at', 'assigned_to', 'ticket_no']));
        if ($edited) {
            self::log($a, 'edited', $user, [...$base, 'details' => ['fields' => $edited]]);
        }
        self::log($a, 'submitted', $user, [...$base,
            'dedupe_key' => $submitKey,
            'details' => ['result' => $changed ? 'saved' : 'no changes', 'changed' => array_values(array_unique($changed)), 'version' => $a->version],
        ]);

        return ['action' => $a, 'replayed' => false];
    }

    /** The status after a Submit, or a rejection explaining why the requested change is not allowed. */
    private static function nextStatus(Action $a, string $requested, CarbonImmutable $scheduled, bool $dateChanged, CarbonInterface $now): string
    {
        $wasTerminal = in_array($a->status, self::TERMINAL, true);

        // Any new date (earlier or later) must still be ahead — whatever the status
        // chosen, and even when the original appointment has already passed.
        if ($dateChanged && $scheduled->lte($now)) {
            throw new ActionHubRejected(
                'The new appointment date and time ('.self::local($scheduled, 'd M Y, H:i').') have already passed. Choose a date and time in the future.',
                422, 'transition_rejected', ['scheduled_at'],
            );
        }
        if ($requested === self::RESCHEDULE) {
            if (! $dateChanged) {
                throw new ActionHubRejected('To reschedule, choose a new date and time that is different from the current appointment.', 422, 'transition_rejected', ['scheduled_at']);
            }

            return self::calculated($scheduled, $now); // On Track or Get Ready, from the new date
        }
        if (in_array($requested, self::TERMINAL, true)) {
            return $requested; // Completed / Abort: always allowed, never changed automatically
        }
        if ($wasTerminal) {
            throw new ActionHubRejected("This action is {$a->status}. To reopen it, choose Reschedule with a new future date and time.");
        }

        // Pending statuses. A new date always recalculates (a Reschedule in effect).
        $target = self::calculated($scheduled, $now);
        if ($dateChanged && ($requested === $a->status || $requested === self::ON_TRACK)) {
            return $target;
        }
        if ($requested === self::DELAYED) {
            return self::DELAYED; // an admin may flag a known delay early
        }
        if ($target === self::DELAYED) {
            throw new ActionHubRejected('This appointment is overdue — choose Reschedule with a new date and time (or Completed / Abort).');
        }

        return $requested === self::GET_READY ? self::GET_READY : $target;
    }

    private static function statusEvent(string $status): string
    {
        return match ($status) {
            self::COMPLETED => 'completed',
            self::ABORT => 'aborted',
            default => 'status_changed',
        };
    }

    // ── Audit history ──────────────────────────────────────────────────

    /** Write one audit row (snapshots of customer/project; never phone numbers, tokens or passwords). */
    public static function log(?Action $a, string $event, ?User $user, array $data = []): ?ActionEvent
    {
        $row = $data + [
            'action_id' => $a?->id,
            'event' => $event,
            'source' => $user ? 'manual' : 'automatic',
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'customer' => $a?->customer,
            'project' => $a?->project,
            'created_at' => now(),
        ];

        if (isset($row['dedupe_key'])) {
            // A repeated scheduler run / retried request: the first row wins.
            $exists = ActionEvent::where('dedupe_key', $row['dedupe_key'])->exists();
            if ($exists && $event !== 'submitted') {
                return null;
            }
        }

        return ActionEvent::create($row);
    }

    // ── Reading ────────────────────────────────────────────────────────

    /** Filters shared by the page and its report. */
    public static function query(array $f): Builder
    {
        $tz = self::timezone();
        $q = Action::query();
        if (($s = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($s)).'%';
            $digits = preg_replace('/\D+/', '', $s);
            $q->where(function (Builder $w) use ($like, $digits) {
                $w->whereRaw('LOWER(customer) LIKE ?', [$like])->orWhereRaw('LOWER(project) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(ticket_no) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }
        foreach (['status' => 'status', 'action' => 'action_type', 'assignee' => 'assigned_to'] as $param => $column) {
            if (($v = $f[$param] ?? '') !== '' && $v !== 'all') {
                $q->where($column, $v);
            }
        }
        if (! empty($f['from'])) {
            $q->where('scheduled_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $f['from'], $tz)->startOfDay()->utc());
        }
        if (! empty($f['to'])) {
            $q->where('scheduled_at', '<', CarbonImmutable::createFromFormat('Y-m-d', $f['to'], $tz)->addDay()->startOfDay()->utc());
        }

        return $q->with('message')->orderBy('scheduled_at')->orderBy('id');
    }

    public static function local(?CarbonInterface $t, string $format = 'Y-m-d\TH:i'): ?string
    {
        return $t ? CarbonImmutable::instance($t)->setTimezone(self::timezone())->format($format) : null;
    }

    public static function iso(?CarbonInterface $t): ?string
    {
        return $t ? CarbonImmutable::instance($t)->setTimezone(self::timezone())->toIso8601String() : null;
    }

    /** API shape of one action (times in the business time zone). */
    public static function present(Action $a, ?Collection $names = null): array
    {
        return [
            'id' => $a->id,
            'customer' => OrderManagement::upper($a->customer),
            'project' => OrderManagement::upper($a->project),
            'phone' => $a->phone,
            'description' => self::upperText($a->description),
            'action_type' => $a->action_type,
            'action_label' => self::actionLabel($a->action_type),
            'ticket_no' => $a->ticket_no,
            // Snag / Service / Scheduled Measurements / Scheduled Installation (any status, with or without a
            // ticket): the row offers Send WhatsApp. Decided here so the page never compares action names itself.
            'service_action' => ActionHubWhatsapp::spec($a->action_type) !== null,
            'whatsapp' => ActionHubWhatsapp::present($a->message),
            'action_other' => $a->action_other ?? '',
            'scheduled_at' => self::iso($a->scheduled_at),
            'scheduled_local' => self::local($a->scheduled_at),
            'get_ready_from' => self::local(self::getReadyFrom($a->scheduled_at), 'Y-m-d'),
            'status' => $a->status,
            'overdue' => self::isOverdue($a),
            'assigned_to' => $a->assigned_to,
            'version' => $a->version,
            'created_at' => self::iso($a->created_at),
            'updated_at' => self::iso($a->updated_at),
            'created_by' => $names?->get($a->created_by),
            'updated_by' => $names?->get($a->updated_by),
        ];
    }

    public static function presentEvent(ActionEvent $e): array
    {
        return [
            'id' => $e->id,
            'action_id' => $e->action_id,
            'event' => $e->event,
            'source' => $e->source,
            'user' => $e->user_name,
            'customer' => $e->customer,
            'project' => $e->project,
            'old_status' => $e->old_status,
            'new_status' => $e->new_status,
            'old_scheduled_at' => self::iso($e->old_scheduled_at),
            'new_scheduled_at' => self::iso($e->new_scheduled_at),
            'old_assignee' => $e->old_assignee,
            'new_assignee' => $e->new_assignee,
            'reason' => $e->reason,
            'details' => $e->details,
            'at' => $e->created_at ? CarbonImmutable::instance($e->created_at)->setTimezone(self::timezone())->format('Y-m-d\TH:i:s.uP') : null,
        ];
    }

    public static function userNames(Collection $actions): Collection
    {
        $ids = $actions->flatMap(fn (Action $a) => [$a->created_by, $a->updated_by])->filter()->unique();

        return User::whereIn('id', $ids)->pluck('name', 'id');
    }
}
