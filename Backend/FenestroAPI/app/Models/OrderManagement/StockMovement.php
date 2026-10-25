<?php

namespace App\Models\OrderManagement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stock addition (Inventory → Add Stock / Bulk Data). Audit trail; never edited. */
class StockMovement extends Model
{
    protected $table = 'om_stock_movements';

    protected $fillable = ['profile_id', 'qty', 'unit', 'movement_date', 'reference', 'remarks', 'source', 'balance_after', 'created_by'];

    protected $casts = [
        'qty'           => 'float',
        'balance_after' => 'float',
        'movement_date' => 'date:Y-m-d',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(InventoryProfile::class, 'profile_id');
    }
}
