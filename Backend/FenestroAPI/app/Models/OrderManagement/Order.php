<?php

namespace App\Models\OrderManagement;

use App\Support\OrderManagement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'om_orders';

    protected $fillable = [
        'code', 'customer', 'project', 'phone', 'email', 'gstin', 'confirmed', 'installation_date', 'sft', 'rate', 'tax',
        'written_off', 'remarks', 'statuses', 'completed_at', 'last_comm_change',
        'last_comm_reset', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'confirmed'        => 'date:Y-m-d',
        'installation_date' => 'date:Y-m-d',
        'sft'              => 'float',
        'rate'             => 'float',
        'tax'              => 'float',
        'statuses'         => 'array',
        'completed_at'     => 'datetime',
        'last_comm_change' => 'datetime',
        'last_comm_reset'  => 'datetime',
    ];

    public function receipts(): HasMany
    {
        return $this->hasMany(OrderReceipt::class)->orderBy('position');
    }

    // ── Payment maths: identical to the dashboard's total()/invoice()/received()/outstanding() ──

    public function invoiceAmount(): float
    {
        $taxable = round((float) $this->sft * (float) $this->rate, 2);

        return round($taxable * (1 + (float) $this->tax / 100), 2);
    }

    /** Money received net of refunds (payments − refunds). Each refund row is counted once. */
    public function receivedAmount(): float
    {
        return round($this->paymentsAmount() - $this->refundedAmount(), 2);
    }

    /** All money received (payments only, before refunds). */
    public function paymentsAmount(): float
    {
        return round((float) $this->receipts->reject(fn (OrderReceipt $r) => $r->isRefund())->sum('amount'), 2);
    }

    /** Money paid back to the customer (receipts marked REFUNDED). */
    public function refundedAmount(): float
    {
        return round((float) $this->receipts->filter(fn (OrderReceipt $r) => $r->isRefund())->sum('amount'), 2);
    }

    /** What can still be refunded: the customer's net amount paid (never below zero). */
    public function refundableAmount(): float
    {
        return max(0.0, $this->receivedAmount());
    }

    /**
     * What could be refunded on a given date: payments recorded up to that date
     * (undated ones included) minus every refund already recorded. A refund can
     * only return money that had actually been paid by then.
     */
    public function refundableOn(string $date): float
    {
        $paid = $this->receipts->reject(fn (OrderReceipt $r) => $r->isRefund())
            ->filter(fn (OrderReceipt $r) => ! $r->date || $r->date->format('Y-m-d') <= $date)->sum('amount');

        return max(0.0, round((float) $paid - $this->refundedAmount(), 2));
    }

    /** On a finalised PROJECT ABORT: the unpaid invoice balance the company writes off (not a payment or refund). */
    public function abortWriteOff(): float
    {
        return $this->isAborted() && $this->written_off !== 'Yes' ? $this->balanceBeforeClosing() : 0.0;
    }

    /**
     * Refund status — separate from PROJECT ABORT and from the payment status:
     * NO REFUND, PARTIAL REFUND (some of the money paid back) or FULL REFUND
     * (everything received paid back).
     */
    public function refundStatus(): string
    {
        $refunded = $this->refundedAmount();
        if ($refunded <= 0) {
            return 'NO REFUND';
        }

        return $refunded >= $this->paymentsAmount() ? 'FULL REFUND' : 'PARTIAL REFUND';
    }

    /**
     * What the customer still owes. Written-off and aborted (PROJECT ABORT)
     * projects owe nothing: an abort closes the account at ₹0 whatever was
     * refunded. Otherwise invoice minus net receipts, never below zero.
     */
    public function outstanding(): float
    {
        if ($this->written_off === 'Yes' || $this->isAborted()) {
            return 0.0;
        }

        return $this->balanceBeforeClosing();
    }

    /** Invoice minus net receipts (never below zero), before any write-off or abort closes the account. */
    private function balanceBeforeClosing(): float
    {
        return max(0.0, round($this->invoiceAmount() - $this->receivedAmount(), 2));
    }

    /** Receipts in date order (undated last, then entry order). */
    public function receiptsByDate()
    {
        return $this->receipts->sortBy(fn (OrderReceipt $r) => [$r->date ? 0 : 1, $r->date?->format('Y-m-d') ?? '', $r->position])->values();
    }

    /** The advance = the first payment (by date), or null if nothing has been received. Refunds never count. */
    public function advanceReceipt(): ?OrderReceipt
    {
        return $this->receiptsByDate()->first(fn (OrderReceipt $r) => ! $r->isRefund() && (float) $r->amount > 0);
    }

    /** The most recent payment (by date). Refunds never count. */
    public function latestReceipt(): ?OrderReceipt
    {
        return $this->receiptsByDate()->filter(fn (OrderReceipt $r) => ! $r->isRefund() && (float) $r->amount > 0)->last();
    }

    /** NOT PAID / ADVANCE RECEIVED / PARTIALLY PAID / FULLY PAID / REFUNDED / WRITTEN OFF (net of refunds). */
    public function paymentStatus(): string
    {
        if ($this->written_off === 'Yes') {
            return 'WRITTEN OFF';
        }
        $paid = $this->receipts->filter(fn (OrderReceipt $r) => ! $r->isRefund() && (float) $r->amount > 0)->count();
        $refunds = $this->receipts->filter(fn (OrderReceipt $r) => $r->isRefund() && (float) $r->amount > 0)->count();
        if ($this->balanceBeforeClosing() <= 0) { // an abort's ₹0 balance never makes a project "fully paid"
            return 'FULLY PAID';
        }
        if ($paid === 0) {
            return 'NOT PAID';
        }
        if ($refunds > 0 && $this->receivedAmount() <= 0) {
            return 'REFUNDED'; // everything received was paid back
        }

        return $paid === 1 && $refunds === 0 ? 'ADVANCE RECEIVED' : 'PARTIALLY PAID';
    }

    /**
     * Statement transactions with a running balance, all from the stored
     * records: the invoice (confirmed date), then every recorded payment in
     * date order (refunds as negative "Payment Refunded" entries), then — if
     * the balance was written off or the project aborted — one closing entry
     * clearing it. The last balance always equals outstanding().
     */
    public function statementLedger(): array
    {
        $advance = $this->advanceReceipt();
        $balance = $this->invoiceAmount();
        $rows = [[
            'date' => $this->confirmed?->format('Y-m-d') ?? '', 'transaction' => 'Invoice',
            'details' => rtrim(rtrim(number_format((float) $this->sft, 3, '.', ''), '0'), '.').' SFT x '.OrderManagement::pdfAmount((float) $this->rate).((float) $this->tax ? ' + '.rtrim(rtrim(number_format((float) $this->tax, 2, '.', ''), '0'), '.').'% GST' : ''),
            'amount' => $balance, 'payment' => null, 'balance' => $balance,
        ]];
        $n = 0;
        foreach ($this->receiptsByDate()->filter(fn (OrderReceipt $r) => (float) $r->amount > 0) as $r) {
            if ($r->isRefund()) {
                // Money paid back: a negative payment that raises the balance again.
                $balance = round($balance + (float) $r->amount, 2);
                $rows[] = [
                    'date' => $r->date?->format('Y-m-d') ?? '', 'transaction' => 'Payment Refunded',
                    'details' => $this->refundDetails($r), 'amount' => null,
                    'payment' => -(float) $r->amount, 'balance' => $balance, 'refund' => true,
                ];
                continue;
            }
            $balance = round($balance - (float) $r->amount, 2);
            $rows[] = [
                'date' => $r->date?->format('Y-m-d') ?? '',
                'transaction' => $advance && $r->is($advance) ? 'Advance received' : 'Payment received ('.(++$n).')',
                'details' => $r->notes ?: '-', 'amount' => null, 'payment' => (float) $r->amount, 'balance' => $balance,
            ];
        }
        if ($this->written_off === 'Yes' && $balance > 0) {
            $rows[] = ['date' => '', 'transaction' => 'Adjustment', 'details' => 'Balance written off', 'amount' => -$balance, 'payment' => null, 'balance' => 0.0];
            $balance = 0.0;
        }
        if ($this->isAborted() && $balance > 0) {
            // The abort closes the account: the company writes off the unpaid invoice balance
            // (an adjustment in the Amount column — never a payment or a refund).
            $rows[] = [
                'date' => $this->completed_at?->format('Y-m-d') ?? '', 'transaction' => 'Project Aborted',
                'details' => 'Outstanding balance written off', 'amount' => -$balance, 'payment' => null, 'balance' => 0.0, 'closing' => true,
            ];
        }

        return $rows;
    }

    /** "Refunded – Project aborted – <note>" for refunds on aborted projects; "Refunded – <note>" otherwise. */
    private function refundDetails(OrderReceipt $r): string
    {
        $note = trim((string) $r->notes);
        if (! $this->isAborted()) {
            return 'Refunded'.($note !== '' ? ' – '.$note : '');
        }
        // The abort dialog's default note only repeats the label.
        $extra = $note !== '' && ! preg_match('/^project aborted( |$|–)/iu', $note) ? ' – '.$note : '';

        return 'Refunded – Project aborted'.$extra;
    }

    /** PROJ STATUS COMM as shown to people (legacy NO / YET TO START read as YET TO SHARE). */
    public function projectStatusLabel(): string
    {
        $key = config('cns.comm_reset.status', 'comm');
        $value = OrderManagement::normaliseStatuses($this->statuses ?? [])[$key] ?? '';

        return (string) (config("cns.statuses.$key.legacy_labels.$value") ?? $value);
    }

    /** Server-calculated amounts for Receivables and statements. */
    public function receivableSummary(): array
    {
        $advance = $this->advanceReceipt();

        return [
            'id'              => $this->code,
            'customer'        => $this->customer,
            'project'         => $this->project,
            'phone'           => $this->phone ?? '',
            'email'           => $this->email ?? '',
            'gstin'           => $this->gstin ?? '', // customer GSTIN (optional)
            'confirmed'       => $this->confirmed?->format('Y-m-d') ?? '',
            'taxable'         => round((float) $this->sft * (float) $this->rate, 2),
            'tax'             => (float) $this->tax,
            'invoice'         => $this->invoiceAmount(),
            'advance'         => $advance ? (float) $advance->amount : 0.0,
            'partial'         => round($this->paymentsAmount() - ($advance ? (float) $advance->amount : 0.0), 2),
            'payments'        => $this->paymentsAmount(), // all money received, before refunds
            'refunded'        => $this->refundedAmount(),
            'refund_status'   => $this->refundStatus(),
            'received'        => $this->receivedAmount(), // net of refunds = amount retained
            'outstanding'     => $this->outstanding(),
            'abort_write_off' => $this->abortWriteOff(), // unpaid balance written off on PROJECT ABORT
            'payment_status'  => $this->paymentStatus(),
            'written_off'     => $this->written_off === 'Yes',
            'completed'       => $this->isCompleted(),
            'archived'        => $this->isArchived(),
            'aborted'         => $this->isAborted(),
            'comm'            => OrderManagement::normaliseStatuses($this->statuses ?? [])['comm'] ?? null,
            'demo'            => OrderManagement::isDemoCode($this->code),
        ];
    }

    /**
     * End of the green period for the current PROJ STATUS COMM value: 4 days
     * after it was selected (server timestamp). Null when nothing has been
     * shared yet (YET TO SHARE / NO) or no selection time is known — red.
     */
    public function commFreshUntil(): ?\Illuminate\Support\Carbon
    {
        $value = $this->statuses[config('cns.comm_colour.status', 'comm')] ?? null;
        if (! $value || in_array($value, OrderManagement::commUnsetValues(), true) || ! $this->last_comm_change) {
            return null;
        }

        return $this->last_comm_change->copy()->addHours(OrderManagement::commFreshHours());
    }

    /** Green (true) while inside the 4-day period; red otherwise. The saved value never changes. */
    public function isCommFresh(): bool
    {
        $until = $this->commFreshUntil();

        return $until !== null && now()->lt($until);
    }

    public function isCompleted(): bool
    {
        return OrderManagement::isCompletionValue($this->statuses[config('cns.comm_reset.status', 'comm')] ?? null);
    }

    /** PROJECT ABORT: the project was stopped; it is archived with its full record. */
    public function isAborted(): bool
    {
        $value = $this->statuses[config('cns.comm_reset.status', 'comm')] ?? null;

        return $value !== null && in_array($value, OrderManagement::archiveValues(), true);
    }

    /**
     * Archived = PROJECT ABORT, or PROJECT COMPLETED **and** fully paid
     * (outstanding ₹0). A completed project with money still due stays in
     * Receivables; it moves to the Archive automatically once the final
     * payment brings the outstanding amount to ₹0. Worked out from the
     * record each time (never stored), so a project can't be in both.
     */
    public function isArchived(): bool
    {
        return $this->isAborted() || ($this->isCompleted() && $this->outstanding() <= 0);
    }

    /** Shape used by the dashboard's `orders` array. */
    public function toDashboard(): array
    {
        $data = [
            'id'         => $this->code,
            'customer'   => $this->customer,
            'project'    => $this->project,
            'phone'      => $this->phone ?? '',
            'email'      => $this->email ?? '',
            'gstin'      => $this->gstin ?? '',
            'confirmed'  => $this->confirmed?->format('Y-m-d') ?? '',
            'installationDate' => $this->installation_date?->format('Y-m-d') ?? '',
            'sft'        => $this->sft,
            'rate'       => $this->rate,
            'tax'        => $this->tax,
            'receipts'   => $this->receipts->map(fn (OrderReceipt $r) => [
                'date'   => $r->date?->format('Y-m-d') ?? '',
                'amount' => $r->amount,
                'kind'   => $r->kind ?: 'payment',
                'notes'  => $r->notes ?? '',
                'ref'    => $r->ref,
            ])->values()->all(),
            'writtenOff' => $this->written_off,
            'remarks'    => $this->remarks ?? '',
            'statuses'   => OrderManagement::normaliseStatuses($this->statuses ?? []),
            'completedAt' => OrderManagement::iso($this->completed_at),
            // PROJ STATUS COMM colour, decided on the server from the selection time.
            'commFreshUntil' => OrderManagement::iso($this->commFreshUntil()),
            'commFresh'      => $this->isCommFresh(),
        ];

        // Only present in the prototype once set, so only send them when set.
        if ($this->last_comm_change) {
            $data['lastCommChange'] = OrderManagement::iso($this->last_comm_change);
        }
        if ($this->last_comm_reset) {
            $data['lastCommReset'] = OrderManagement::iso($this->last_comm_reset);
        }

        return $data;
    }
}
