<?php

namespace App\Models\OrderManagement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReceipt extends Model
{
    protected $table = 'om_order_receipts';

    protected $fillable = ['order_id', 'date', 'amount', 'kind', 'notes', 'ref', 'position'];

    public const KINDS = ['payment', 'refund'];

    /** Money paid back to the customer (shown as "Payment Refunded"). */
    public function isRefund(): bool
    {
        return $this->kind === 'refund';
    }

    protected $casts = [
        'date'   => 'date:Y-m-d',
        'amount' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
