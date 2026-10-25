<?php

namespace App\Models\OrderManagement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to send a payment statement through the WhatsApp Cloud API. */
class StatementSend extends Model
{
    protected $table = 'om_statement_sends';

    protected $fillable = [
        'order_id', 'recipient', 'fingerprint', 'filename', 'status', 'message_id', 'error', 'created_by',
        'delivery_status', 'delivery_error', 'delivery_updated_at',
    ];

    protected $casts = ['delivery_updated_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
