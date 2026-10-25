<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\Order;
use App\Support\OrderManagement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API for the Prestige Order Management dashboard (/admin/orders).
 * All routes sit behind `auth:api` + `admin` middleware (see routes/api.php).
 *
 * Request/response bodies use the same field names as the dashboard's
 * in-browser records, so the approved prototype code runs unchanged.
 */
class OrderManagementController extends Controller
{
    /** Free-text fields are rendered as HTML by the prototype, so angle brackets are refused. */
    private const NO_HTML = 'not_regex:/[<>]/';

    /** Customer/project also appear inside an HTML attribute in the prototype, so quotes are refused too. */
    private const NO_HTML_OR_QUOTE = 'not_regex:/[<>"]/';

    // ── Load everything the dashboard needs ────────────────────────────

    public function index(): JsonResponse
    {
        return response()->json([
            // Newest first, matching the prototype's orders.unshift().
            'orders'    => Order::with('receipts')->orderByDesc('id')->get()->map->toDashboard()->values(),
            // Oldest first, matching inventory.push().
            'inventory' => InventoryProfile::with('usages')->orderBy('id')->get()->map->toDashboard()->values(),
            // CNS columns (labels, options, defaults, "done" values) and rules from config/cns.php.
            'config'    => [
                'statuses'           => OrderManagement::statusesForDashboard(),
                'archive_after_days' => (int) config('cns.archive_after_days', 0),
                'completion_values'  => OrderManagement::completionValues(),
                'archive_values'     => OrderManagement::archiveValues(),
                // PROJ STATUS COMM colour rule + server clock (the browser corrects for its own clock).
                'comm_fresh_hours'   => OrderManagement::commFreshHours(),
                'comm_unset_values'  => OrderManagement::commUnsetValues(),
                'server_time'        => OrderManagement::iso(now()),
                'statement_drafts'   => (bool) config('cns.whatsapp.statement_drafts_enabled'),
            ],
        ]);
    }

    // ── Orders ─────────────────────────────────────────────────────────

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $this->validateOrder($request);
        $this->checkReceipts($data['receipts'] ?? []);

        $order = $this->createWithUniqueCode(function () use ($data, $request) {
            $code = $this->pickCode($data['id'] ?? null, OrderManagement::ORDER_CODE_PATTERN, Order::class, fn () => OrderManagement::nextOrderCode());

            return DB::transaction(function () use ($data, $code, $request) {
                $order = Order::create($this->orderAttributes($data) + [
                    'code'       => $code,
                    'created_by' => $request->user()->id,
                    'updated_by' => $request->user()->id,
                ]);
                $this->syncReceipts($order, $data['receipts'] ?? []);

                return $order;
            });
        });

        return response()->json(['data' => $order->load('receipts')->toDashboard()], 201);
    }

    public function updateOrder(Request $request, string $code): JsonResponse
    {
        Order::where('code', $code)->firstOrFail(); // 404 before validating
        $data = $this->validateOrder($request);

        // The receipt checks run on the locked row, so a refund recorded at the same moment
        // (Project Abort locks the same row) can never be missed by this save.
        [$order, $before] = DB::transaction(function () use ($code, $data, $request) {
            $order = Order::where('code', $code)->lockForUpdate()->firstOrFail();
            $this->checkReceipts($data['receipts'] ?? [], $order);
            $before = OrderManagement::normaliseStatuses($order->statuses ?? []);
            $order->update($this->orderAttributes($data, $order) + ['updated_by' => $request->user()->id]);
            $this->syncReceipts($order, $data['receipts'] ?? []);

            return [$order, $before];
        });

        $order = $order->fresh('receipts');

        // WhatsApp drafts only for statuses whose saved value actually changed
        // (compared with the database, not the browser), and only after the
        // save succeeded. Nothing is sent; the admin opens and sends the draft.
        $drafts = [];
        foreach (OrderManagement::normaliseStatuses($order->statuses ?? []) as $key => $value) {
            if (($before[$key] ?? null) !== $value && ($draft = OrderManagement::whatsappDraft($order, $key, $value))) {
                $drafts[] = $draft;
            }
        }

        return response()->json(['data' => $order->toDashboard(), 'whatsapp_drafts' => $drafts]);
    }

    // ── Expected installation date (PROD STRTD message) ─────────────────

    /**
     * Set an order's expected installation date. Used when PROD STRTD is chosen
     * on CNS but the date is missing: the admin enters it, it is saved, and the
     * PROD STRTD draft is returned if that is the order's current status.
     */
    // ── Project Abort with refund ─────────────────────────────────────

    /** Figures the abort dialog shows before the admin confirms (all from the stored records). */
    public function abortSummary(string $code): JsonResponse
    {
        $order = Order::with('receipts')->where('code', $code)->firstOrFail();

        return response()->json(['data' => $this->abortFigures($order)]);
    }

    /**
     * Abort a project and record the refund the admin chose (full, partial or
     * none) in one step. The refund is a separate receipt (kind = refund); the
     * original payments are never changed. The project status (PROJECT ABORT)
     * and the refund status are kept separately.
     *
     * Safe to repeat: the same requestId (one per dialog) never records a second
     * refund, and the order row is locked while the refund is checked and saved.
     */
    public function abortProject(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'refundAmount' => 'required|integer|min:0|max:99999999999',
            'refundDate'   => 'required_unless:refundAmount,0|nullable|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:'.now('Asia/Kolkata')->toDateString(),
            'notes'        => ['nullable', 'string', 'max:500', self::NO_HTML],
            'requestId'    => 'required|string|min:8|max:64|regex:/^[A-Za-z0-9-]+$/',
            'confirm'      => 'accepted',
        ], [
            'refundAmount.integer' => 'The refund must be a whole number of rupees.',
            'confirm.accepted'     => 'Please confirm the abort and refund.',
            'refundDate.required_unless' => 'Choose the refund date.',
            'refundDate.before_or_equal' => 'The refund date cannot be in the future.',
        ]);
        $abortValue = OrderManagement::archiveValues()[0] ?? 'PROJECT ABORT';
        $commKey = config('cns.comm_reset.status', 'comm');

        $result = DB::transaction(function () use ($code, $data, $abortValue, $commKey, $request) {
            $order = Order::where('code', $code)->lockForUpdate()->firstOrFail();
            $order->load('receipts');

            // Same confirmation again (double click, retry): nothing new is recorded.
            if ($order->receipts->firstWhere('ref', $data['requestId'])) {
                return ['order' => $order, 'replayed' => true];
            }
            $refund = (int) $data['refundAmount'];
            $refundable = $order->refundableAmount();
            if ($refund > $refundable) {
                throw ValidationException::withMessages(['refundAmount' => 'The refund (₹'.number_format($refund).') cannot be more than the customer\'s net amount paid (₹'.number_format($refundable).').']);
            }
            // A refund returns money already paid: payments recorded up to the refund date must cover it.
            if ($refund > 0 && $refund > ($paidByDate = $order->refundableOn($data['refundDate']))) {
                throw ValidationException::withMessages(['refundDate' => 'By '.\Illuminate\Support\Carbon::parse($data['refundDate'])->format('d M Y').' the customer had paid ₹'.number_format($paidByDate).' net. A refund cannot be dated before the payment it returns.']);
            }
            if ($refund === 0 && $order->isAborted()) {
                return ['order' => $order, 'replayed' => true]; // already aborted, nothing to add
            }

            $statuses = OrderManagement::normaliseStatuses($order->statuses ?? []);
            $wasAborted = ($statuses[$commKey] ?? null) === $abortValue;
            $statuses[$commKey] = $abortValue;
            $order->fill([
                'statuses'   => $statuses,
                'updated_by' => $request->user()->id,
            ] + ($wasAborted ? [] : ['completed_at' => now(), 'last_comm_change' => now()]))->save();

            if ($refund > 0) {
                $order->receipts()->create([
                    'date'     => $data['refundDate'],
                    'amount'   => $refund,
                    'kind'     => 'refund',
                    'notes'    => trim((string) ($data['notes'] ?? '')) ?: 'Project aborted – refund to customer',
                    'ref'      => $data['requestId'],
                    'position' => (int) $order->receipts->max('position') + 1,
                ]);
            }

            return ['order' => $order->fresh('receipts'), 'replayed' => false];
        });

        $order = $result['order'];

        return response()->json([
            'data'     => $order->toDashboard(),
            'summary'  => $this->abortFigures($order),
            'replayed' => $result['replayed'],
        ]);
    }

    // ── Delete Project (only projects with no history) ─────────────────

    /**
     * Why a project may not be deleted. Anything with financial or customer
     * history is kept: payments/refunds, statements sent, inventory used, or a
     * closed (completed/aborted) project in the Archive. Those are closed with
     * PROJECT ABORT instead, which keeps the full record.
     */
    private function deleteBlockers(Order $order): array
    {
        $order->loadMissing('receipts');
        $payments = $order->receipts->filter(fn ($r) => ! $r->isRefund() && (float) $r->amount > 0)->count();
        $refunds = $order->receipts->filter(fn ($r) => $r->isRefund() && (float) $r->amount > 0)->count();
        $statements = \App\Models\OrderManagement\StatementSend::where('order_id', $order->id)->count();
        $usage = \App\Models\OrderManagement\InventoryUsage::where('usage_key', $order->code)->where('qty', '>', 0)->count();

        $reasons = [];
        if ($payments) {
            $reasons[] = $payments.' payment'.($payments === 1 ? '' : 's').' recorded';
        }
        if ($refunds) {
            $reasons[] = $refunds.' refund'.($refunds === 1 ? '' : 's').' recorded';
        }
        if ($statements) {
            $reasons[] = $statements.' payment statement'.($statements === 1 ? '' : 's').' sent';
        }
        if ($usage) {
            $reasons[] = 'inventory used on '.$usage.' profile'.($usage === 1 ? '' : 's');
        }
        if ($order->isAborted() || $order->isCompleted()) {
            $reasons[] = 'the project is closed ('.$order->projectStatusLabel().') and kept in the Archive';
        }

        return ['reasons' => $reasons, 'counts' => compact('payments', 'refunds', 'statements', 'usage')];
    }

    public function deleteCheck(string $code): JsonResponse
    {
        $order = Order::with('receipts')->where('code', $code)->firstOrFail();
        $b = $this->deleteBlockers($order);

        return response()->json(['data' => [
            'id' => $order->code, 'customer' => $order->customer, 'project' => $order->project,
            'status' => $order->projectStatusLabel(), 'deletable' => $b['reasons'] === [], 'reasons' => $b['reasons'], 'counts' => $b['counts'],
        ]]);
    }

    /** Deletes a project with no history. The admin must type the project ID to confirm. */
    public function destroyOrder(Request $request, string $code): JsonResponse
    {
        $request->validate(['confirm' => 'required|string'], ['confirm.required' => 'Type the project ID to confirm.']);
        if (strtoupper(trim((string) $request->input('confirm'))) !== strtoupper($code)) {
            throw ValidationException::withMessages(['confirm' => 'Type the project ID exactly ('.$code.') to confirm.']);
        }

        return DB::transaction(function () use ($code, $request) {
            $order = Order::where('code', $code)->lockForUpdate()->firstOrFail();
            $b = $this->deleteBlockers($order);
            if ($b['reasons'] !== []) {
                return response()->json([
                    'message' => 'This project cannot be deleted: '.implode('; ', $b['reasons']).'. Close it with PROJECT ABORT instead; that keeps its full record.',
                    'reasons' => $b['reasons'],
                ], 409);
            }
            // Only empty ₹0 receipt rows and zero-quantity usage rows can remain; remove them with the project.
            \App\Models\OrderManagement\InventoryUsage::where('usage_key', $order->code)->delete();
            $order->receipts()->delete();
            \Illuminate\Support\Facades\Log::info('Order Management: project deleted', ['code' => $order->code, 'customer' => $order->customer, 'by' => $request->user()->id]);
            $order->delete();

            return response()->json(['deleted' => $code]);
        });
    }

    private function abortFigures(Order $order): array
    {
        return [
            'id'            => $order->code,
            'customer'      => $order->customer,
            'project'       => $order->project,
            'invoice'       => $order->invoiceAmount(),
            'payments'      => $order->paymentsAmount(),   // total received, before refunds
            'refunded'      => $order->refundedAmount(),   // previous refunds
            'refundable'    => $order->refundableAmount(), // remaining refundable = net paid
            'outstanding'   => $order->outstanding(),
            'aborted'       => $order->isAborted(),
            'refund_status' => $order->refundStatus(),
            'payment_status'=> $order->paymentStatus(),
        ];
    }

    public function setInstallationDate(Request $request, string $code): JsonResponse
    {
        $order = Order::with('receipts')->where('code', $code)->firstOrFail();
        $data = $request->validate(['installationDate' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before:2100-01-01']);

        $order->update(['installation_date' => $data['installationDate'], 'updated_by' => $request->user()->id]);
        $order = $order->fresh('receipts');

        $comm = OrderManagement::normaliseStatuses($order->statuses ?? [])['comm'] ?? null;
        $draft = $comm ? OrderManagement::whatsappDraft($order, 'comm', $comm) : null;

        return response()->json(['data' => $order->toDashboard(), 'whatsapp_drafts' => $draft ? [$draft] : []]);
    }

    private function validateOrder(Request $request): array
    {
        $rules = [
            'id'                => 'nullable|string|max:20',
            'customer'          => ['required', 'string', 'max:255', self::NO_HTML_OR_QUOTE],
            'project'           => ['required', 'string', 'max:255', self::NO_HTML_OR_QUOTE],
            'phone'             => ['nullable', 'string', 'max:20', self::NO_HTML],
            'email'             => 'nullable|email:rfc|max:150',
            // Customer GSTIN (optional): 15 characters, standard format, e.g. 29ABCDE1234F1Z5.
            'gstin'             => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]$/'],
            'confirmed'         => 'required|date_format:Y-m-d',
            'installationDate'  => 'nullable|date_format:Y-m-d',
            'sft'               => 'required|numeric|min:0|max:999999999',
            'rate'              => 'required|numeric|min:0|max:999999999',
            'tax'               => 'nullable|numeric|min:0|max:100',
            'writtenOff'        => 'required|in:No,Yes',
            'remarks'           => ['nullable', 'string', 'max:2000', self::NO_HTML],
            'receipts'          => 'nullable|array|max:500',
            'receipts.*.date'   => 'nullable|date_format:Y-m-d',
            'receipts.*.amount' => 'nullable|numeric|min:0|max:99999999999',
            'receipts.*.kind'   => 'nullable|in:payment,refund',
            'receipts.*.ref'    => 'nullable|string|max:64',
            'receipts.*.notes'  => ['nullable', 'string', 'max:500', self::NO_HTML],
            'statuses'          => 'nullable|array', // missing statuses get the workbook defaults
            'completedAt'       => 'nullable|date',
            'lastCommChange'    => 'nullable|date',
            'lastCommReset'     => 'nullable|date',
        ];
        // Allowed values come from config/cns.php — the browser's options are never trusted.
        foreach (OrderManagement::allStatuses() as $key => $s) {
            // 'sometimes': a status the browser doesn't send keeps its saved value
            // (e.g. an older cached dashboard, or a switched-off column).
            $rules["statuses.$key"] = ['sometimes', 'required', Rule::in(OrderManagement::allowedValues($s))];
        }

        return $request->validate($rules, [
            'customer.not_regex'         => 'Customer name cannot contain < > or double quotes.',
            'project.not_regex'          => 'Project name cannot contain < > or double quotes.',
            'phone.not_regex'            => 'Phone number cannot contain < or >.',
            'remarks.not_regex'          => 'Remarks cannot contain < or >.',
            'receipts.*.notes.not_regex' => 'Receipt notes cannot contain < or >.',
            'gstin.regex'                => 'Enter a valid 15-character GSTIN (e.g. 29ABCDE1234F1Z5), or leave it empty.',
        ]);
    }

    /** @param Order|null $existing the stored order when updating (null when creating) */
    private function orderAttributes(array $data, ?Order $existing = null): array
    {
        // Statuses not sent keep their saved value (older dashboards, switched-off columns).
        $statuses = OrderManagement::normaliseStatuses(array_merge($existing?->statuses ?? [], $data['statuses'] ?? []));
        $commKey = config('cns.comm_reset.status', 'comm');

        // Closing time is decided here, not by the browser: set when the project
        // becomes PROJECT COMPLETED or PROJECT ABORT, kept while that status stays,
        // cleared when it is restored (which puts it back on the active dashboard).
        $completedAt = null;
        $newComm = $statuses[$commKey] ?? null;
        if (OrderManagement::isCompletionValue($newComm)) {
            $sameAsBefore = $existing && (OrderManagement::normaliseStatuses($existing->statuses ?? [])[$commKey] ?? null) === $newComm;
            $completedAt = $sameAsBefore && $existing->completed_at ? $existing->completed_at : now();
        }

        // PROJ STATUS COMM selection time — set by the server (never the browser)
        // whenever the status changes, so the 4-day colour period always starts
        // at the real selection. Unchanged status → original time kept (a page
        // refresh or editing other fields never restarts the period).
        $previousComm = $existing ? (OrderManagement::normaliseStatuses($existing->statuses ?? [])[$commKey] ?? null) : null;
        $selectedAt = $existing?->last_comm_change;
        if (! $existing) {
            $default = OrderManagement::statuses()[$commKey]['default'] ?? null;
            $selectedAt = ($statuses[$commKey] ?? null) !== $default ? now() : null;
        } elseif (($statuses[$commKey] ?? null) !== $previousComm) {
            $selectedAt = now();
        }

        // The last automatic reset never moves backwards (an older browser tab
        // must not undo the scheduler's once-per-day guard).
        $lastReset = OrderManagement::parseTime($data['lastCommReset'] ?? null);
        if ($existing?->last_comm_reset && (! $lastReset || $lastReset->lt($existing->last_comm_reset))) {
            $lastReset = $existing->last_comm_reset;
        }

        return [
            'customer'         => $data['customer'],
            'project'          => $data['project'],
            'phone'            => $data['phone'] ?? null,
            // Not sent (older dashboard) → keep the saved email.
            'email'            => array_key_exists('email', $data) ? (trim((string) $data['email']) ?: null) : $existing?->email,
            'gstin'            => array_key_exists('gstin', $data) ? (strtoupper(trim((string) $data['gstin'])) ?: null) : $existing?->gstin,
            'confirmed'        => $data['confirmed'],
            // Not sent (older dashboard) → keep the saved date.
            'installation_date' => array_key_exists('installationDate', $data) ? ($data['installationDate'] ?: null) : $existing?->installation_date,
            'sft'              => $data['sft'],
            'rate'             => $data['rate'],
            'tax'              => $data['tax'] ?? 0,
            'written_off'      => $data['writtenOff'],
            'remarks'          => $data['remarks'] ?? null,
            'statuses'         => $statuses,
            'completed_at'     => $completedAt,
            'last_comm_change' => $selectedAt,
            'last_comm_reset'  => $lastReset,
        ];
    }

    /**
     * Receipt rules beyond the field formats:
     *  - amounts are whole rupees (a value already stored on this order, e.g. an
     *    older decimal entry, may be kept as it is);
     *  - refunds are recorded only through Project Abort (abortProject): the
     *    order form may send back the refunds already stored, unchanged, but can
     *    never add, alter or remove one (syncReceipts keeps the stored refunds);
     *  - the stored refunds can never exceed the payments received.
     */
    private function checkReceipts(array $receipts, ?Order $existing = null): void
    {
        $stored = $existing ? $existing->receipts->map(fn ($r) => (string) round((float) $r->amount, 2))->all() : [];
        $storedRefunds = $existing ? $existing->receipts->filter(fn ($r) => $r->isRefund())->values()->all() : [];
        $unmatched = $storedRefunds;
        $payments = 0.0;
        foreach (array_values($receipts) as $i => $r) {
            $amount = (float) ($r['amount'] ?? 0);
            if (floor($amount) != $amount && ! in_array((string) round($amount, 2), $stored, true)) {
                throw ValidationException::withMessages(["receipts.$i.amount" => 'Receipt amounts must be whole rupees (no paise).']);
            }
            if (($r['kind'] ?? 'payment') === 'refund') {
                // Must be one of the stored refunds, as stored (each matched once).
                $match = null;
                foreach ($unmatched as $k => $x) {
                    if (round((float) $x->amount, 2) === round($amount, 2) && ($x->date?->format('Y-m-d') ?? '') === (string) ($r['date'] ?? '')
                        && (string) ($x->ref ?? '') === (string) ($r['ref'] ?? '')) {
                        $match = $k;
                        break;
                    }
                }
                if ($match === null) {
                    throw ValidationException::withMessages(["receipts.$i.kind" => 'Refunds can only be recorded through Project Abort (choose PROJECT ABORT and confirm the refund in its dialog).']);
                }
                unset($unmatched[$match]);
            } else {
                $payments += $amount;
            }
        }
        $refunds = array_sum(array_map(fn ($x) => (float) $x->amount, $storedRefunds));
        if (round($refunds, 2) > round($payments, 2)) {
            throw ValidationException::withMessages(['receipts' => 'Refunds (₹'.number_format($refunds).') cannot be more than the payments received (₹'.number_format($payments).').']);
        }
    }

    /** Replaces the payment rows. Stored refunds are kept exactly as they are (only Project Abort adds them). */
    private function syncReceipts(Order $order, array $receipts): void
    {
        $order->receipts()->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '<>', 'refund'))->delete();
        foreach (array_values($receipts) as $i => $r) {
            if (($r['kind'] ?? 'payment') === 'refund') {
                continue; // already stored, checked unchanged by checkReceipts
            }
            $order->receipts()->create([
                'date'     => $r['date'] ?? null,
                'amount'   => $r['amount'] ?? 0,
                'kind'     => 'payment',
                'notes'    => $r['notes'] ?? null,
                'ref'      => $r['ref'] ?? null,
                'position' => $i,
            ]);
        }
    }

    // ── Inventory ──────────────────────────────────────────────────────

    public function storeInventory(Request $request): JsonResponse
    {
        $data = $this->validateInventory($request, true);
        $this->checkWholeQuantities($data, null);

        $profile = DB::transaction(function () use ($data, $request) {
            $profile = InventoryProfile::create($this->inventoryAttributes($data) + [
                'code'       => $data['code'],
                'updated_by' => $request->user()->id,
            ]);
            $this->syncUsage($profile, $data['usage']);

            return $profile;
        });

        return response()->json(['data' => $profile->load('usages')->toDashboard()], 201);
    }

    public function updateInventory(Request $request, string $code): JsonResponse
    {
        $profile = InventoryProfile::where('code', $code)->firstOrFail();
        $data = $this->validateInventory($request, false);
        $this->checkWholeQuantities($data, $profile);

        DB::transaction(function () use ($profile, $data, $request) {
            // The profile code is the record's identity and is never renamed.
            $profile->update($this->inventoryAttributes($data) + ['updated_by' => $request->user()->id]);
            $this->syncUsage($profile, $data['usage']);
        });

        return response()->json(['data' => $profile->fresh('usages')->toDashboard()]);
    }

    /** Stock and usage quantities are whole numbers; a value already stored on the profile may be kept. */
    private function checkWholeQuantities(array $data, ?InventoryProfile $existing): void
    {
        $same = fn ($a, $b) => $b !== null && abs((float) $a - (float) $b) < 0.0005;
        foreach (['stock' => $existing?->stock, 'baseStock' => $existing?->base_stock] as $field => $stored) {
            if (isset($data[$field]) && $data[$field] !== null && floor((float) $data[$field]) != (float) $data[$field] && ! $same($data[$field], $stored)) {
                throw ValidationException::withMessages([$field => 'Stock quantities must be whole numbers.']);
            }
        }
        $storedUsage = $existing ? $existing->usages->pluck('qty', 'usage_key')->all() : [];
        foreach ($data['usage'] ?? [] as $key => $qty) {
            if (floor((float) $qty) != (float) $qty && ! $same($qty, $storedUsage[$key] ?? null)) {
                throw ValidationException::withMessages(['usage' => 'Usage quantities must be whole numbers.']);
            }
        }
    }

    private function validateInventory(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'code'      => array_filter([
                $creating ? 'required' : 'nullable',
                'string',
                'max:100',
                // The code is used in the record's URL and inside the prototype's onclick
                // handlers, so slashes, quotes, backslashes and angle brackets are refused.
                'not_regex:/[\/\\\\<>"\']/',
                $creating ? 'unique:om_inventory_profiles,code' : null,
            ]),
            'item'      => ['required', 'string', 'max:255', self::NO_HTML],
            'stock'     => 'required|numeric|min:-999999999|max:999999999',
            'baseStock' => 'nullable|numeric|min:-999999999|max:999999999',
            // Whole-number quantities are checked in checkWholeQuantities().
            'unitCost'  => 'nullable|numeric|min:0|max:999999999',
            'usage'     => 'nullable|array|max:1000',
            'usage.*'   => 'numeric|min:0|max:999999999',
        ], [
            'code.unique'    => 'That profile code already exists.',
            'code.not_regex' => 'Profile codes cannot contain / \\ < > or quotes.',
            'item.not_regex' => 'Item description cannot contain < or >.',
        ]);

        // Usage is an object keyed by project; validate the keys too.
        $usage = [];
        foreach (($data['usage'] ?? []) as $key => $qty) {
            $key = (string) $key;
            if ($key === '' || mb_strlen($key) > 255 || preg_match('/[<>"\']/', $key)) {
                throw ValidationException::withMessages(['usage' => 'Invalid project reference in usage.']);
            }
            $usage[$key] = (float) $qty;
        }
        $data['usage'] = $usage;

        return $data;
    }

    private function inventoryAttributes(array $data): array
    {
        return [
            'item'       => $data['item'],
            'stock'      => $data['stock'],
            'base_stock' => $data['baseStock'] ?? $data['stock'],
            'unit_cost'  => $data['unitCost'] ?? 0,
        ];
    }

    private function syncUsage(InventoryProfile $profile, array $usage): void
    {
        $profile->usages()->delete();
        foreach ($usage as $key => $qty) {
            $profile->usages()->create(['usage_key' => $key, 'qty' => $qty]);
        }
    }

    private function pickCode(?string $proposed, string $pattern, string $model, callable $generate): string
    {
        if ($proposed && preg_match($pattern, $proposed) && ! $model::where('code', $proposed)->exists()) {
            return $proposed;
        }

        return $generate();
    }

    /** Retry when two admins create a record with the same generated code at the same moment. */
    private function createWithUniqueCode(callable $create)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $create();
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
                usleep(random_int(5_000, 30_000));
            }
        }
    }
}
