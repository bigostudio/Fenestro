<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderManagement\Order;
use App\Models\OrderManagement\OrderReceipt;
use App\Services\WhatsAppService;
use App\Models\OrderManagement\StatementSend;
use Illuminate\Support\Facades\Cache;
use App\Support\OrderManagement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receivables page (/admin/receivables). Admin-only (routes/api.php).
 * All amounts come from the Order model's payment maths — the same figures
 * Customer & Orders shows — never from the browser. Read-only: selecting
 * projects or generating statements never changes financial records.
 */
class ReceivablesController extends Controller
{
    /**
     * Every project with an outstanding balance > 0 (written-off orders owe
     * nothing, so they're excluded). Aborted projects are archived and are
     * listed (with any balance) in the Archive instead.
     */
    public function index(): JsonResponse
    {
        $rows = Order::with('receipts')->orderByDesc('id')->get()
            ->filter(fn (Order $o) => $o->isReceivable())
            ->map(fn (Order $o) => $o->receivableSummary())
            ->values();

        return response()->json([
            'data'             => $rows,
            'total'            => round($rows->sum('outstanding'), 2),
            'statement_drafts' => (bool) config('cns.whatsapp.statement_drafts_enabled'),
        ]);
    }

    /**
     * Statement data for the selected projects (the PDF is drawn in the
     * browser from these server figures) plus a WhatsApp draft for each.
     * Nothing is sent and nothing is stored.
     */
    public function statements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1|max:100',
            'ids.*' => 'required|string|max:20|distinct',
        ]);

        $orders = Order::with('receipts')->whereIn('code', $data['ids'])->get()->keyBy('code');
        $draftsOn = (bool) config('cns.whatsapp.statement_drafts_enabled');
        $statements = [];
        $skipped = [];

        foreach ($data['ids'] as $code) {
            $order = $orders->get($code);
            if (! $order) {
                $skipped[] = ['order_id' => $code, 'reason' => 'Project not found.'];
                continue;
            }

            $advance = $order->advanceReceipt();
            $n = 0;
            $receipts = $order->receiptsByDate()
                ->filter(fn (OrderReceipt $r) => (float) $r->amount > 0)
                ->map(function (OrderReceipt $r) use ($advance, &$n) {
                    $isAdvance = $advance && $r->is($advance);

                    return [
                        'date'   => $r->date?->format('Y-m-d') ?? '',
                        'label'  => $r->isRefund() ? 'Payment Refunded' : ($isAdvance ? 'Advance received' : 'Payment received ('.(++$n).')'),
                        'notes'  => $r->notes ?? '',
                        'amount' => (float) $r->amount,
                        'kind'   => $r->isRefund() ? 'refund' : 'payment',
                    ];
                })->values();

            $statements[] = $order->receivableSummary() + [
                'sft'            => (float) $order->sft,
                'rate'           => (float) $order->rate,
                'receipts'       => $receipts,
                // Running balance rows for the PDF, calculated here from the stored records.
                'ledger'         => $order->statementLedger(),
                'project_status' => $order->projectStatusLabel(),
                'generated_at' => OrderManagement::iso(now()),
                'draft'        => $draftsOn
                    ? OrderManagement::statementDraft($order)
                    : null,
                'draft_off_reason' => $draftsOn ? null : 'Statement WhatsApp drafts are switched off on this server (CNS_STATEMENT_DRAFTS).',
            ];
        }

        return response()->json(['statements' => $statements, 'skipped' => $skipped, 'whatsapp_api' => self::apiStatus()]);
    }

    /**
     * Receivables → Reminder: a payment-reminder message for each selected project,
     * with the customer's name, the server's outstanding balance and the WhatsApp
     * number to open. Read-only: nothing is sent or stored; the admin reviews each
     * message in WhatsApp and presses Send there.
     */
    public function reminders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'required|array|min:1|max:100',
            'ids.*' => 'required|string|max:20|distinct',
        ]);

        $orders = Order::with('receipts')->whereIn('code', $data['ids'])->get()->keyBy('code');
        $out = [];
        foreach ($data['ids'] as $code) {
            $order = $orders->get($code);
            if (! $order) {
                $out[] = ['id' => $code, 'customer' => '', 'project' => '', 'outstanding' => 0, 'phone' => null, 'text' => null, 'test_recipient' => false, 'demo' => false, 'problem' => 'Project not found.'];
                continue;
            }
            $outstanding = $order->outstanding();
            $row = ['id' => $order->code, 'customer' => OrderManagement::upper($order->customer), 'project' => OrderManagement::upper($order->project), 'outstanding' => $outstanding];
            if ($outstanding <= 0 || $order->isArchived()) {
                $out[] = $row + ['phone' => null, 'text' => null, 'test_recipient' => false, 'demo' => OrderManagement::isDemoCode($order->code),
                    'problem' => 'No reminder needed: this project has no outstanding balance.'];
                continue;
            }
            $draft = OrderManagement::reminderDraft($order);
            $problem = $draft['text'] === null ? ($draft['reason'] ?? 'The reminder could not be prepared.')
                : (! $draft['phone'] ? ($draft['demo'] ? $draft['reason'] : 'No valid WhatsApp number on this project. Add the customer’s 10-digit mobile number in Customer & Orders.') : null);
            $out[] = $row + [
                'phone'          => $draft['phone'],
                'text'           => $draft['text'],
                'test_recipient' => $draft['test_recipient'],
                'demo'           => $draft['demo'],
                'problem'        => $problem,
            ];
        }

        return response()->json(['reminders' => $out]);
    }

    /**
     * WhatsApp sending status for the Receivables / Archive pages. Without
     * ?live=1 it only reports which settings are present; with ?live=1 it
     * also asks the Cloud API whether they work (read-only, sends nothing).
     */
    public function whatsappStatus(Request $request, WhatsAppService $whatsApp): JsonResponse
    {
        $status = self::apiStatus();
        $out = $status + [
            'missing'        => $whatsApp->missingSettings(),
            'drafts_enabled' => (bool) config('cns.whatsapp.statement_drafts_enabled'),
            'template'       => config('cns.whatsapp.statement_api.template') ?: null,
            'test_recipient' => (bool) config('cns.whatsapp.test_recipient'),
            // Delivered / read status needs the WhatsApp webhook (WhatsappWebhookController).
            'delivery_tracking' => self::deliveryTracking(),
        ];
        if ($request->boolean('live')) {
            $check = $whatsApp->checkConnection($out['template'], (string) config('cns.whatsapp.statement_api.template_language', 'en'));
            $out['live'] = [
                'ok'       => $check['ok'],
                'error'    => $check['error'],
                'number'   => $check['phone']['display_phone_number'] ?? null,
                'name'     => $check['phone']['verified_name'] ?? null,
                'quality'  => $check['phone']['quality_rating'] ?? null,
                'template' => $check['template'],
            ];
        }

        return response()->json($out);
    }

    /** Delivery statuses arrive only through the signed webhook, which needs both settings. */
    private static function deliveryTracking(): bool
    {
        return (string) config('services.whatsapp.app_secret') !== '' && (string) config('services.whatsapp.verify_token') !== '';
    }

    /**
     * Latest delivery status for statements already accepted by WhatsApp
     * (sent / delivered / read / failed, reported by the webhook).
     */
    public function deliveries(Request $request): JsonResponse
    {
        $data = $request->validate(['message_ids' => 'required|array|max:100', 'message_ids.*' => 'required|string|max:120']);
        $rows = StatementSend::whereIn('message_id', $data['message_ids'])->get(['message_id', 'delivery_status', 'delivery_error', 'delivery_updated_at']);

        return response()->json([
            'tracking' => self::deliveryTracking(),
            'data'     => $rows->map(fn (StatementSend $s) => [
                'message_id' => $s->message_id,
                'status'     => $s->delivery_status,
                'error'      => $s->delivery_error,
                'updated_at' => OrderManagement::iso($s->delivery_updated_at),
            ])->values(),
        ]);
    }

    /** Whether statements can be sent automatically through the WhatsApp Cloud API. */
    private static function apiStatus(): array
    {
        $service = app(WhatsAppService::class);
        $configured = $service->isConfigured();
        $template = config('cns.whatsapp.statement_api.template');

        return [
            'configured' => $service->isEnabled() && (bool) config('cns.whatsapp.statement_drafts_enabled'),
            'mode'       => $template ? 'template' : 'document',
            'delivery_tracking' => self::deliveryTracking(),
            'reason'     => ! $configured
                ? 'Automatic sending is not configured: set WHATSAPP_TOKEN and WHATSAPP_PHONE_NUMBER_ID (WhatsApp Business Cloud API) on the server. Until then, statements cannot be sent.'
                : (! $service->isEnabled() ? 'Automatic WhatsApp sending is switched off on this server (WHATSAPP_SENDING_ENABLED=false).'
                    : (config('cns.whatsapp.statement_drafts_enabled') ? null : 'Statement WhatsApp messages are switched off on this server (CNS_STATEMENT_DRAFTS).')),
        ];
    }

    /**
     * Send one project's payment statement (PDF document + message as its
     * caption) through the WhatsApp Cloud API. Used by Receivables and Archive.
     *
     * The PDF is drawn in the browser from this project's server figures and
     * uploaded here. Before anything is sent the server checks that:
     *  - the PDF really is this project's statement (contains its project ID
     *    and customer name),
     *  - the recipient is this project's own valid number (decided here,
     *    never by the browser; demo records only go to a test recipient),
     *  - the same statement was not already accepted by WhatsApp in the last
     *    24 hours (so a retry or double click never sends it twice).
     * Every attempt is logged in om_statement_sends. Success is reported only
     * when the Cloud API accepts the message.
     */
    public function sendStatement(Request $request, string $code, WhatsAppService $whatsApp): JsonResponse
    {
        $order = Order::with('receipts')->where('code', $code)->firstOrFail();
        $request->validate(['pdf' => 'required|file|max:8192']);
        $bytes = (string) file_get_contents($request->file('pdf')->getRealPath());
        if (! str_starts_with($bytes, '%PDF-')) {
            return response()->json(['accepted' => false, 'message' => 'The uploaded statement is not a PDF.'], 422);
        }
        if (! self::pdfBelongsTo($bytes, $order)) {
            return response()->json(['accepted' => false, 'message' => "The PDF does not match project {$order->code} ({$order->customer}). Nothing was sent."], 422);
        }
        if (! self::pdfHasCurrentFigures($bytes, $order)) {
            return response()->json(['accepted' => false, 'message' => 'The PDF figures do not match the current payment records (a payment may have changed). Refresh and send again. Nothing was sent.'], 409);
        }

        $status = self::apiStatus();
        if (! $status['configured']) {
            return response()->json(['accepted' => false, 'configured' => false, 'message' => $status['reason']], 503);
        }

        $draft = OrderManagement::statementDraft($order);
        if ($draft['text'] === null || ! $draft['phone']) {
            // Missing/invalid number, or a demo record without a test recipient.
            return response()->json(['accepted' => false, 'configured' => true, 'message' => $draft['reason'] ?? 'No valid WhatsApp number for this project.'], 422);
        }

        $safe = fn (string $v) => trim(preg_replace('/[^A-Za-z0-9-]+/', '_', $v), '_');
        $filename = 'Statement_'.$safe($order->code).'_'.(substr($safe($order->customer), 0, 40) ?: 'Customer').'.pdf';
        $fingerprint = hash('sha256', json_encode([
            $order->code, $draft['phone'], $draft['text'], $order->invoiceAmount(), $order->receivedAmount(), $order->outstanding(),
            $order->receiptsByDate()->map(fn ($r) => [$r->date?->format('Y-m-d'), (float) $r->amount, $r->kind, $r->notes])->values()->all(),
        ]));

        // One send per project at a time (double clicks, two open tabs).
        $lock = Cache::lock('statement-send:'.$order->id, 120);
        if (! $lock->get()) {
            return response()->json(['accepted' => false, 'message' => 'A statement for this project is already being sent. Please wait.'], 409);
        }
        try {
            // Same statement already accepted recently → report it, don't send again. Checked while
            // holding the lock, so a send that finished a moment ago is always seen.
            $previous = StatementSend::where('order_id', $order->id)->where('fingerprint', $fingerprint)
                ->where('status', 'accepted')->where('created_at', '>=', now()->subDay())->latest('id')->first();
            if ($previous) {
                return response()->json(self::result($order, $draft, $filename, true, $previous->message_id, 'Already sent: WhatsApp accepted this same statement on '.$previous->created_at->timezone('Asia/Kolkata')->format('d M Y, h:i a').'. Not sent again.', true));
            }

            $template = config('cns.whatsapp.statement_api.template');
            $result = $whatsApp->sendDocument(
                $draft['phone'], $bytes, $filename, $draft['text'],
                $template ?: null, OrderManagement::statementValues($order), (string) config('cns.whatsapp.statement_api.template_language', 'en'),
            );
            StatementSend::create([
                'order_id'    => $order->id,
                'recipient'   => $draft['phone'],
                'fingerprint' => $fingerprint,
                'filename'    => $filename,
                'status'      => $result['accepted'] ? 'accepted' : 'failed',
                'message_id'  => $result['message_id'],
                'error'       => $result['error'],
                'created_by'  => $request->user()?->id,
            ]);
        } finally {
            $lock->release();
        }

        return response()->json(
            self::result($order, $draft, $filename, $result['accepted'], $result['message_id'], $result['accepted'] ? 'Sent: WhatsApp accepted the PDF and message for delivery.' : $result['error']),
            $result['accepted'] ? 200 : 502,
        );
    }

    private static function result(Order $order, array $draft, string $filename, bool $accepted, ?string $messageId, ?string $message, bool $duplicate = false): array
    {
        return [
            'order_id'       => $order->code,
            'accepted'       => $accepted,
            'duplicate'      => $duplicate,
            'configured'     => true,
            'message_id'     => $messageId,
            'to'             => $draft['phone'],
            'test_recipient' => $draft['test_recipient'],
            'filename'       => $filename,
            'message'        => $message,
        ];
    }

    /**
     * The PDF must print the server's current invoice, received and outstanding figures.
     * Amounts print as ₹1,18,000.00: the ₹ sign is drawn with its own embedded font, the
     * digits as plain text, so the digits ("1,18,000.00") are what is matched.
     */
    private static function pdfHasCurrentFigures(string $pdf, Order $order): bool
    {
        foreach ([$order->invoiceAmount(), $order->receivedAmount(), $order->outstanding()] as $amount) {
            if (! str_contains($pdf, preg_replace('/^-?Rs\. /', '', OrderManagement::pdfAmount($amount)))) {
                return false;
            }
        }

        return true;
    }

    /** The uploaded PDF must be this project's statement: its ID and (plain-text) customer name appear in it. */
    private static function pdfBelongsTo(string $pdf, Order $order): bool
    {
        if (! str_contains($pdf, '('.$order->code.')') && ! str_contains($pdf, $order->code)) {
            return false;
        }
        $name = (string) $order->customer;
        if ($name === '' || ! preg_match('/^[\x20-\x7E]+$/', $name)) {
            return true; // non-ASCII names are encoded differently inside the PDF; the project ID check still applies
        }

        // Ignore case: the PDF prints the name in proper case, the portal stores it in upper case.
        return str_contains(strtolower($pdf), strtolower(str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $name)));
    }
}
