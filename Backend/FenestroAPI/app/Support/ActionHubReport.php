<?php

namespace App\Support;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\ActionHub\Message;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Action Hub report (GET /api/admin/reports/action-hub), same shape as every
 * AdminReports report. Covers every action matching the page's filters
 * (ActionHub::query) after applying due automatic status changes, plus their
 * reschedule and status history from the audit table; with history=1 the
 * full audit history of those actions is added. Customer / project columns
 * are marked 'proper' (proper case in the PDF, upper case in Excel).
 */
class ActionHubReport
{
    private const EVENT_LABELS = [
        'created' => 'Created', 'submitted' => 'Submit saved', 'edited' => 'Edited', 'status_changed' => 'Status changed',
        'auto_get_ready' => 'Automatic: Get Ready', 'auto_delayed' => 'Automatic: Delayed', 'completed' => 'Completed',
        'aborted' => 'Aborted', 'reschedule_requested' => 'Reschedule requested', 'rescheduled' => 'Rescheduled',
        'assignee_changed' => 'Assignee changed', 'validation_failed' => 'Validation failed',
        'transition_rejected' => 'Status change rejected', 'stale_rejected' => 'Stale edit rejected',
        'ticket_assigned' => 'Ticket created', 'whatsapp_accepted' => 'WhatsApp sent', 'whatsapp_failed' => 'WhatsApp failed', 'whatsapp_not_configured' => 'WhatsApp not configured',
    ];

    private const WHATSAPP_LABELS = [
        ActionHubWhatsapp::PENDING => 'Not sent', ActionHubWhatsapp::SENDING => 'Sending', ActionHubWhatsapp::ACCEPTED => 'Sent',
        ActionHubWhatsapp::FAILED => 'Failed', ActionHubWhatsapp::NOT_CONFIGURED => 'Not sent (WhatsApp not configured)',
    ];

    public static function build(array $f): array
    {
        ActionHub::refresh();
        $actions = ActionHub::query($f)->get();
        $ids = $actions->pluck('id');
        $events = ActionEvent::whereIn('action_id', $ids)->orderBy('id')->get();
        $names = User::whereIn('id', $actions->flatMap(fn (Action $a) => [$a->created_by, $a->updated_by])->filter()->unique())->pluck('name', 'id');
        $now = now();
        $overdue = $actions->filter(fn (Action $a) => $a->status === ActionHub::DELAYED);
        $withHistory = filter_var($f['history'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $summary = [self::stat('Actions', $actions->count())];
        foreach (array_diff(config('action_hub.statuses'), [ActionHub::RESCHEDULE]) as $s) {
            $summary[] = self::stat($s, $actions->where('status', $s)->count());
        }
        $summary[] = self::stat('Rescheduled (times)', $events->where('event', 'rescheduled')->count());

        $sections = [
            self::section('Current actions', 'Actions', [
                self::col('customer', 'Customer', proper: true), self::col('project', 'Project', proper: true), self::col('phone', 'Phone'),
                self::col('description', 'Description', wide: true), self::col('action', 'Action'), self::col('ticket', 'Ticket #'),
                self::col('whatsapp', 'WhatsApp'), self::col('scheduled', 'Scheduled', 'datetime'),
                self::col('status', 'Status'), self::col('assigned', 'Assigned to'), self::col('created', 'Created', 'datetime'),
                self::col('updated', 'Last updated', 'datetime'), self::col('updated_by', 'Updated by'),
            ], $actions->map(fn (Action $a) => [
                'customer' => OrderManagement::upper($a->customer), 'project' => OrderManagement::upper($a->project), 'phone' => $a->phone,
                'description' => ActionHub::upperText($a->description), 'action' => self::actionLabel($a),
                'ticket' => (string) $a->ticket_no, 'whatsapp' => self::whatsapp($a), 'scheduled' => ActionHub::iso($a->scheduled_at),
                'status' => $a->status.($a->status === ActionHub::DELAYED ? ' (overdue)' : ''), 'assigned' => $a->assigned_to,
                'created' => ActionHub::iso($a->created_at), 'updated' => ActionHub::iso($a->updated_at), 'updated_by' => $names->get($a->updated_by) ?? '',
            ])->values()->all(), 'No actions match these filters.'),

            self::section('Delayed / overdue appointments', 'Overdue', [
                self::col('customer', 'Customer', proper: true), self::col('project', 'Project', proper: true), self::col('phone', 'Phone'),
                self::col('action', 'Action'), self::col('scheduled', 'Scheduled', 'datetime'), self::col('overdue_by', 'Overdue by'),
                self::col('assigned', 'Assigned to'), self::col('note', 'Note', wide: true),
            ], $overdue->map(fn (Action $a) => [
                'customer' => OrderManagement::upper($a->customer), 'project' => OrderManagement::upper($a->project), 'phone' => $a->phone,
                'action' => self::actionLabel($a), 'scheduled' => ActionHub::iso($a->scheduled_at),
                'overdue_by' => $now->gt($a->scheduled_at) ? CarbonImmutable::instance($a->scheduled_at)->diffForHumans($now, ['syntax' => CarbonImmutable::DIFF_ABSOLUTE, 'parts' => 2]) : '—',
                'assigned' => $a->assigned_to, 'note' => ActionHub::OVERDUE_MESSAGE,
            ])->values()->all(), 'No delayed or overdue appointments.'),

            self::section('Reschedule history', 'Reschedules', [
                self::col('customer', 'Customer', proper: true), self::col('project', 'Project', proper: true),
                self::col('previous', 'Previous date & time', 'datetime'), self::col('new', 'New date & time', 'datetime'),
                self::col('reason', 'Reason', wide: true), self::col('by', 'By'), self::col('at', 'Recorded', 'datetime'),
            ], $events->where('event', 'rescheduled')->map(fn (ActionEvent $e) => [
                'customer' => OrderManagement::upper($e->customer), 'project' => OrderManagement::upper($e->project),
                'previous' => ActionHub::iso($e->old_scheduled_at), 'new' => ActionHub::iso($e->new_scheduled_at),
                'reason' => (string) $e->reason, 'by' => $e->user_name ?? '', 'at' => self::at($e),
            ])->values()->all(), 'No reschedules recorded.'),

            self::section('WhatsApp messages', 'WhatsApp', [
                self::col('ticket', 'Ticket #'), self::col('customer', 'Customer', proper: true), self::col('message', 'Message'),
                self::col('for', 'Appointment in message'), self::col('status', 'Status'), self::col('attempts', 'Attempts'),
                self::col('to', 'To'), self::col('by', 'Sent by'), self::col('at', 'Last attempt', 'datetime'), self::col('error', 'Error', wide: true),
            ], Message::whereIn('action_id', $ids)->orderBy('id')->get()->map(fn (Message $m) => [
                'ticket' => $m->ticket_no, 'customer' => OrderManagement::upper($actions->firstWhere('id', $m->action_id)?->customer),
                'message' => ActionHubWhatsapp::label($m->template), 'for' => (string) ActionHub::local($m->scheduled_for, 'd M Y, h:i A'),
                'status' => self::messageStatus($m), 'attempts' => (string) $m->attempts, 'to' => (string) $m->recipient_masked,
                'by' => (string) $m->sent_by_name, 'at' => ActionHub::iso($m->last_attempt_at), 'error' => (string) ($m->delivery_error ?? $m->error),
            ])->values()->all(), 'No WhatsApp messages for these actions.'),

            self::section('Status history (manual and automatic)', 'Status history', [
                self::col('customer', 'Customer', proper: true), self::col('project', 'Project', proper: true),
                self::col('from', 'From'), self::col('to', 'To'), self::col('source', 'Manual / automatic'),
                self::col('by', 'By'), self::col('at', 'Recorded', 'datetime'),
            ], $events->whereIn('event', ['status_changed', 'auto_get_ready', 'auto_delayed', 'completed', 'aborted'])->map(fn (ActionEvent $e) => [
                'customer' => OrderManagement::upper($e->customer), 'project' => OrderManagement::upper($e->project),
                'from' => $e->old_status ?? '—', 'to' => $e->new_status ?? '—', 'source' => ucfirst($e->source),
                'by' => $e->source === 'automatic' ? 'System' : ($e->user_name ?? ''), 'at' => self::at($e),
            ])->values()->all(), 'No status changes recorded.'),
        ];

        if ($withHistory) {
            $sections[] = self::section('Audit history', 'Audit history', [
                self::col('at', 'Recorded', 'datetime'), self::col('customer', 'Customer', proper: true), self::col('project', 'Project', proper: true),
                self::col('event', 'Event'), self::col('source', 'Manual / automatic'), self::col('by', 'By'),
                self::col('status', 'Status'), self::col('schedule', 'Scheduled'), self::col('assignee', 'Assignee'), self::col('details', 'Details', wide: true),
            ], $events->map(fn (ActionEvent $e) => [
                'at' => self::at($e), 'customer' => OrderManagement::upper($e->customer), 'project' => OrderManagement::upper($e->project),
                'event' => self::EVENT_LABELS[$e->event] ?? $e->event, 'source' => ucfirst($e->source), 'by' => $e->source === 'automatic' ? 'System' : ($e->user_name ?? ''),
                'status' => self::change($e->old_status, $e->new_status),
                'schedule' => self::change(ActionHub::local($e->old_scheduled_at, 'd M Y H:i'), ActionHub::local($e->new_scheduled_at, 'd M Y H:i')),
                'assignee' => self::change($e->old_assignee, $e->new_assignee), 'details' => self::details($e),
            ])->values()->all(), 'No audit history for these actions.');
        }

        return [
            'title' => 'Action Hub Report',
            'description' => 'Appointments and follow-ups with status, schedule and change history.',
            'filters' => self::filterLabels($f, $withHistory),
            'summary' => $summary,
            'sections' => $sections,
            'notes' => [
                'Times are in '.ActionHub::timezone().'. Get Ready starts '.config('action_hub.get_ready_working_days').' working days before the appointment (Sundays and listed holidays are not working days); Delayed means the scheduled date and time have passed.',
            ],
        ];
    }

    private static function actionLabel(Action $a): string
    {
        return $a->action_type === 'Other' && $a->action_other ? 'Other: '.$a->action_other : ActionHub::actionLabel($a->action_type);
    }

    /** Latest message of the ticket: "Service Scheduled: Sent (delivered)". */
    private static function whatsapp(Action $a): string
    {
        $m = $a->message;

        return $m ? ActionHubWhatsapp::label($m->template).': '.self::messageStatus($m) : '';
    }

    private static function messageStatus(Message $m): string
    {
        $label = self::WHATSAPP_LABELS[$m->status] ?? $m->status;

        return $m->status === ActionHubWhatsapp::ACCEPTED && $m->delivery_status ? $label.' ('.$m->delivery_status.')' : $label;
    }

    private static function at(ActionEvent $e): ?string
    {
        return $e->created_at ? ActionHub::iso($e->created_at) : null;
    }

    private static function change(?string $old, ?string $new): string
    {
        if ($old === $new) {
            return (string) ($new ?? '');
        }

        return trim(($old ?? '—').' → '.($new ?? '—'));
    }

    private static function details(ActionEvent $e): string
    {
        $parts = [];
        if ($e->reason) {
            $parts[] = $e->reason;
        }
        $d = $e->details ?? [];
        if (! empty($d['fields'])) {
            $parts[] = 'Fields: '.implode(', ', $d['fields']);
        }
        if ($e->event === 'ticket_assigned' && ! empty($d['ticket'])) {
            $parts[] = 'Ticket '.$d['ticket'];
        }
        if (! empty($d['ticket']) && str_starts_with($e->event, 'whatsapp_')) {
            $parts[] = ($d['message_label'] ?? 'Request Received').' · Ticket '.$d['ticket'].(isset($d['attempt']) ? ' · attempt '.$d['attempt'] : '')
                .(! empty($d['scheduled_for']) ? ' · for '.$d['scheduled_for'] : '').(! empty($d['to']) ? ' · to '.$d['to'] : '');
        }
        if (! empty($d['changed'])) {
            $parts[] = 'Saved: '.implode(', ', $d['changed']);
        } elseif (($d['result'] ?? '') === 'no changes') {
            $parts[] = 'Submitted with no changes';
        }

        return implode(' · ', $parts);
    }

    private static function filterLabels(array $f, bool $history): array
    {
        $out = [];
        foreach (['q' => 'Search', 'status' => 'Status', 'action' => 'Action', 'assignee' => 'Assigned to', 'from' => 'Scheduled from', 'to' => 'Scheduled to'] as $k => $label) {
            if (($v = trim((string) ($f[$k] ?? ''))) !== '' && $v !== 'all') {
                $out[] = ['label' => $label, 'value' => $k === 'action' ? ActionHub::actionLabel($v) : $v];
            }
        }
        if ($history) {
            $out[] = ['label' => 'Audit history', 'value' => 'Included'];
        }

        return $out;
    }

    private static function stat(string $label, int|float $value): array
    {
        return ['label' => $label, 'value' => $value, 'format' => 'number'];
    }

    private static function col(string $key, string $label, string $format = 'text', bool $wide = false, bool $proper = false): array
    {
        return ['key' => $key, 'label' => $label, 'format' => $format] + ($wide ? ['wide' => true] : []) + ($proper ? ['proper' => true] : []);
    }

    private static function section(string $title, string $sheet, array $columns, array $rows, string $empty): array
    {
        return ['title' => $title, 'sheet' => $sheet, 'columns' => $columns, 'rows' => $rows, 'totals' => null, 'empty' => $empty];
    }
}
