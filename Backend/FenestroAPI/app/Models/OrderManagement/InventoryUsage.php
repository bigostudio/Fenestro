<?php

namespace App\Models\OrderManagement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryUsage extends Model
{
    protected $table = 'om_inventory_usages';

    protected $fillable = ['profile_id', 'usage_key', 'qty'];

    protected $casts = [
        'qty' => 'float',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(InventoryProfile::class, 'profile_id');
    }
}
