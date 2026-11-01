<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Business constants for the Order Management dashboard. Lead options mirror
 * the approved prototype; CNS status columns and WhatsApp draft texts come
 * from config/cns.php. The server rejects values the UI can't produce.
 */
final class OrderManagement
{
    /**
     * CNS status columns from config/cns.php:
     * key => ['label' => ..., 'options' => [...], 'default' => ..., 'done' => [...]].
     */
    public static function statuses(): array
    {
        return array_filter(self::allStatuses(), fn (array $s) => $s['enabled'] ?? true);
    }

    /** Every configured status column, including switched-off ones ('enabled' => false). */
    public static function allStatuses(): array
    {
        return config('cns.statuses', []);
    }

    /** Status columns in the shape the dashboard's STATUSES array uses. */
    public static function statusesForDashboard(): array
    {
        $out = [];
        foreach (self::statuses() as $key => $s) {
            $out[] = [
                'key'     => $key,
                'label'   => $s['label'],
                'options' => array_values($s['options']),
                'def'     => $s['default'],
                'done'    => array_values($s['done'] ?? []),
                'legacy'  => array_values($s['legacy'] ?? []),
                // How legacy values are shown (e.g. NO → "YET TO SHARE"); the stored value is kept.
                'legacy_labels' => (object) ($s['legacy_labels'] ?? []),
            ];
        }

        return $out;
    }

    /** Values the server accepts for a status: current options plus legacy values still on older orders. */
    /**
     * Values for the statement template body, in order: customer name,
     * project name, invoice, received, outstanding (same formatting as the
     * draft text).
     */
    public static function statementValues(\App\Models\OrderManagement\Order $order): array
    {
        return array_map(fn ($f) => self::placeholder($order, $f)[0] ?? '', ['customer_name', 'project_name', 'invoice_amount', 'received_amount', 'outstanding_amount']);
    }

    /** PROJ STATUS COMM values that close a project (PROJECT COMPLETED, PROJECT ABORT). */
    public static function completionValues(): array
    {
        $c = config('cns.comm_reset', []);

        return array_values($c['stop_values'] ?? ['PROJECT COMPLETED']);
    }

    /** Hours a selected PROJ STATUS COMM value stays green (4 days). */
    public static function commFreshHours(): int
    {
        return max(1, (int) config('cns.comm_colour.fresh_hours', 96));
    }

    /** Values that mean "nothing done yet" — always red (YET TO START, YET TO SHARE, legacy NO). */
    public static function commUnsetValues(): array
    {
        return array_values(config('cns.comm_colour.unset_values', ['YET TO START', 'YET TO SHARE', 'NO']));
    }

    /** Closing values that archive a project straight away, whatever is due (PROJECT ABORT). */
    public static function archiveValues(): array
    {
        return array_values(config('cns.comm_reset.archive_values', ['PROJECT ABORT']));
    }

    public static function isCompletionValue(?string $value): bool
    {
        return $value !== null && in_array($value, self::completionValues(), true);
    }

    public static function allowedValues(array $status): array
    {
        return array_values(array_merge($status['options'], $status['legacy'] ?? []));
    }

    public static function isDemoCode(string $code): bool
    {
        return str_starts_with($code, (string) config('cns.demo_prefix', 'PV-DEMO-'));
    }

    /**
     * WhatsApp click-to-chat draft for a status that was just saved, or null
     * when no template exists for that status/value. Nothing is sent: the
     * admin opens the link and sends the message themselves.
     */
    public static function whatsappDraft(\App\Models\OrderManagement\Order $order, string $statusKey, string $value): ?array
    {
        $template = config("cns.whatsapp.templates.$statusKey.$value");
        if (! is_string($template) || $template === '') {
            return null;
        }

        return self::buildDraft($order, $template) + [
            'status_key'   => $statusKey,
            'status_label' => config("cns.statuses.$statusKey.label", $statusKey),
            'value'        => $value,
        ];
    }

    /** Draft that accompanies a PDF payment statement (Receivables / Archive → Send Statement). */
    public static function statementDraft(\App\Models\OrderManagement\Order $order): array
    {
        // Aborted: neutral wording (no payment demand, no refund implied). Fully paid:
        // thank-you wording (no payment request). Otherwise the statement with the balance due.
        $template = $order->isAborted() ? 'statement_template_aborted'
            : ($order->outstanding() <= 0 && $order->written_off !== 'Yes' ? 'statement_template_paid' : 'statement_template');

        return self::buildDraft($order, (string) config('cns.whatsapp.'.$template, config('cns.whatsapp.statement_template'))) + [
            'status_key'   => 'statement',
            'status_label' => 'PAYMENT STATEMENT',
            'value'        => self::rupees($order->outstanding()),
        ];
    }

    /**
     * Payment reminder (Receivables → Reminder) for a project with money due. Same
     * recipient rules as every draft (test number → none for demo orders → the
     * customer's valid number). Nothing is sent; the admin presses Send in WhatsApp.
     */
    public static function reminderDraft(\App\Models\OrderManagement\Order $order): array
    {
        return self::buildDraft($order, (string) config('cns.whatsapp.reminder_template')) + [
            'status_key'   => 'reminder',
            'status_label' => 'PAYMENT REMINDER',
            'value'        => self::rupees($order->outstanding()),
        ];
    }

    /**
     * Fill a template from the order. If any placeholder has no value, no
     * text or link is produced — 'missing' says what to add instead, so a
     * customer never receives "undefined", "null" or a guessed value.
     */
    private static function buildDraft(\App\Models\OrderManagement\Order $order, string $template): array
    {
        $values = [];
        $missing = [];
        preg_match_all('/\{([a-z_]+)\}/', $template, $m);
        foreach (array_unique($m[1]) as $field) {
            [$value, $problem] = self::placeholder($order, $field);
            if ($problem !== null) {
                $missing[$field] = $problem;
            } else {
                $values['{'.$field.'}'] = $value;
            }
        }

        if ($missing) {
            return [
                'order_id'       => $order->code,
                'customer'       => self::upper($order->customer),
                'text'           => null,
                'phone'          => null,
                'url'            => null,
                'test_recipient' => false,
                'demo'           => self::isDemoCode($order->code),
                'missing'        => array_keys($missing),
                'reason'         => implode(' ', $missing),
            ];
        }

        return self::addressDraft($order, self::withSignature(strtr($template, $values))) + ['missing' => []];
    }

    /**
     * Close a WhatsApp message with the company signature (cns.whatsapp.signature),
     * exactly once. An older sign-off at the end ("Regards,\nPrestige Ventures – …")
     * is replaced so a customised template never ends with two sign-offs.
     */
    public static function withSignature(string $text): string
    {
        $signature = trim((string) config('cns.whatsapp.signature'));
        $text = rtrim($text);
        if ($signature === '') {
            return $text;
        }
        $flat = fn (string $v) => preg_replace('/\s+/u', ' ', $v);
        if (str_contains($flat($text), $flat($signature))) {
            return $text;
        }
        $text = preg_replace('/\n+(?:(?:Regards|Warm regards|Best regards|Thanks|Thank you)[!,.]?\n)?Prestige Ventures\b[^\n]*$/u', '', $text);

        return rtrim($text)."\n\n".$signature;
    }

    /**
     * Customer and project text as the portal shows and saves it: upper case.
     * Older records keep their stored text; this only formats what is shown.
     */
    public static function upper(?string $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /** @return array{0: ?string, 1: ?string} [value, problem-if-missing] */
    private static function placeholder(\App\Models\OrderManagement\Order $order, string $field): array
    {
        switch ($field) {
            case 'customer_name':
            case 'customer':
                $v = trim((string) $order->customer); // exactly as entered

                return $v !== '' ? [$v, null] : [null, 'The customer name is missing on this order.'];
            case 'project_name':
            case 'project':
                $v = trim((string) $order->project); // exactly as entered

                return $v !== '' ? [$v, null] : [null, 'The project name is missing on this order.'];
            case 'order_id':
                return [$order->code, null];
            case 'advance_amount':
                $r = $order->advanceReceipt();

                return $r ? [self::rupees((float) $r->amount), null] : [null, 'No advance receipt is recorded on this order yet — add it in Customer & Orders first.'];
            case 'payment_amount':
                $r = $order->latestReceipt();

                return $r ? [self::rupees((float) $r->amount), null] : [null, 'No payment receipt is recorded on this order yet — add it in Customer & Orders first.'];
            case 'installation_date':
                return $order->installation_date
                    ? [$order->installation_date->format('d M Y'), null]
                    : [null, 'The expected installation date is not set for this order.'];
            case 'google_review_link':
                $url = trim((string) config('cns.whatsapp.google_review_url'));

                return $url !== '' ? [$url, null] : [null, 'The Google review link is not configured yet (set CNS_GOOGLE_REVIEW_URL on the server).'];
            case 'amount':
            case 'outstanding':
            case 'outstanding_amount':
                return [self::rupees($order->outstanding()), null];
            case 'invoice_amount':
                return [self::rupees($order->invoiceAmount()), null];
            case 'received_amount':
                return [self::rupees($order->receivedAmount()), null];
            case 'payments_amount': // all payments, before refunds
                return [self::rupees($order->paymentsAmount()), null];
            case 'refunded_amount':
                return [self::rupees($order->refundedAmount()), null];
        }

        return [null, "Unknown placeholder {{$field}} in the message template."];
    }

    /**
     * Decide who a draft goes to and build the wa.me link:
     * test number (if configured) → no recipient for demo orders → the
     * customer's valid mobile number → otherwise no link, with a reason.
     */
    private static function addressDraft(\App\Models\OrderManagement\Order $order, string $text): array
    {
        $draft = [
            'order_id'       => $order->code,
            'customer'       => self::upper($order->customer),
            'text'           => $text,
            'phone'          => null,
            'url'            => null,
            'test_recipient' => false,
            'demo'           => self::isDemoCode($order->code),
            'reason'         => null,
        ];

        $testRecipient = self::normalisePhone((string) config('cns.whatsapp.test_recipient'));
        if ($testRecipient) {
            // Testing: every draft goes to the configured test number.
            $draft['phone'] = $testRecipient;
            $draft['test_recipient'] = true;
        } elseif ($draft['demo']) {
            // Demo data must never reach a real phone: open WhatsApp without a
            // recipient so the admin picks a chat (e.g. their own) to test with.
            $draft['url'] = 'https://wa.me/?text='.rawurlencode($text);
            $draft['reason'] = 'Demo record: no customer number is used. Choose a chat in WhatsApp to test this draft, or set WHATSAPP_DRAFT_TEST_RECIPIENT.';

            return $draft;
        } else {
            $draft['phone'] = self::normalisePhone((string) $order->phone);
        }

        if (! $draft['phone']) {
            $draft['reason'] = 'This order has no valid 10-digit mobile number, so a WhatsApp draft could not be opened.';

            return $draft;
        }

        $draft['url'] = 'https://wa.me/'.$draft['phone'].'?text='.rawurlencode($text);

        return $draft;
    }

    /** Indian mobile number → international digits (e.g. 919876543210), or null if invalid. */
    public static function normalisePhone(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        $cc = (string) config('cns.whatsapp.country_code', '91');
        // International dialling prefix (0091 98765 43210) → 91 98765 43210.
        if (str_starts_with($digits, '00'.$cc)) {
            $digits = substr($digits, 2);
        }
        // Trunk zero written after the country code (+91 0 98765 43210).
        if (str_starts_with($digits, $cc.'0') && preg_match('/^[6-9]\d{9}$/', substr($digits, strlen($cc) + 1))) {
            $digits = $cc.substr($digits, strlen($cc) + 1);
        }

        if (preg_match('/^[6-9]\d{9}$/', $digits)) {
            return $cc.$digits;
        }
        if (str_starts_with($digits, $cc) && preg_match('/^[6-9]\d{9}$/', substr($digits, strlen($cc)))) {
            return $digits;
        }
        if (preg_match('/^0[6-9]\d{9}$/', $digits)) {
            return $cc.substr($digits, 1);
        }

        return null;
    }

    /** "AKRAM KHAN" → "Akram Khan" (the dashboard stores names in upper case). */

    public const CONTACT_STATUSES = [
        'YET TO CONNECT',
        'NOT PICKING CALL',
        'NOT REACHABLE',
        'CONNECTED - APPOINTMENT BOOKED',
        'CONNECTED - PLASTERING INCOMPLETE',
        'CONNECTED - GENERAL INQUIRY',
        'CONNECTED - NOT INTERESTED',
        'NOT PICKING CALL - LEFT MESSAGE',
    ];

    public const OUTCOMES = ['APPOINTMENT BOOKED', 'INQUIRY DROPPED'];

    public const APPOINTMENT_RESULTS = ['INQUIRY ATTENDED', 'INQUIRY MISSED'];

    public const BUILDING_TYPES = ['Commercial', 'Apartment', 'Residential', 'Renovation', 'Others'];

    public const ORDER_CODE_PATTERN = '/^PV-\d{4}-\d{3,6}$/';


    /**
     * Fill in defaults for enabled status columns and drop unknown keys.
     * Values for switched-off columns are kept (not defaulted) so turning a
     * column off and on again loses nothing.
     */
    public static function normaliseStatuses(array $statuses): array
    {
        $out = [];
        foreach (self::allStatuses() as $key => $s) {
            $value = $statuses[$key] ?? null;
            $valid = in_array($value, self::allowedValues($s), true);
            if ($s['enabled'] ?? true) {
                $out[$key] = $valid ? $value : $s['default'];
            } elseif ($valid) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** ₹ with Indian digit grouping, e.g. 1838750.5 → ₹18,38,750.50 (matches the dashboard's money()). */
    /**
     * Amount as printed on the PDF statement: "Rs. 1,18,000.00" (Indian
     * grouping, 2 decimals; the PDF core fonts have no ₹ glyph). The browser
     * prints the same string, so the server can check the uploaded PDF
     * carries its own figures.
     */
    public static function pdfAmount(float $amount): string
    {
        $neg = $amount < 0;
        $s = self::rupees(abs(round($amount, 2)));
        $s = substr($s, strlen('₹'));
        if (! str_contains($s, '.')) {
            $s .= '.00';
        } elseif (strlen(substr($s, strpos($s, '.') + 1)) === 1) {
            $s .= '0';
        }

        return ($neg ? '- ' : '').'Rs. '.$s;
    }

    public static function rupees(float $amount): string
    {
        $neg = $amount < 0;
        $amount = round(abs($amount), 2);
        $int = (string) (int) floor($amount);
        $dec = (int) round(($amount - floor($amount)) * 100);
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $grouped = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).','.$last3 : $last3;

        return ($neg ? '-' : '').'₹'.$grouped.($dec ? '.'.str_pad((string) $dec, 2, '0', STR_PAD_LEFT) : '');
    }

    /** ISO-8601 UTC string with milliseconds, matching JS Date#toISOString(). */
    public static function iso(?CarbonInterface $time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Parse an ISO timestamp from the browser into app-timezone Carbon. */
    public static function parseTime(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    /** Next PV-YYMM-NNN code for the current month (same scheme as the prototype). */
    public static function nextOrderCode(): string
    {
        $prefix = 'PV-'.now()->format('ym').'-';
        $max = \App\Models\OrderManagement\Order::where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($c) => (int) substr($c, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
