<?php

namespace App\Models\OrderManagement;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryProfile extends Model
{
    protected $table = 'om_inventory_profiles';

    protected $fillable = ['code', 'item', 'unit', 'base_stock', 'stock', 'unit_cost', 'updated_by'];

    public function movements(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StockMovement::class, 'profile_id')->latest('id');
    }

    protected $casts = [
        'base_stock' => 'float',
        'stock'      => 'float',
        'unit_cost'  => 'float',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(InventoryUsage::class, 'profile_id')->orderBy('id');
    }

    /** Shape used by the dashboard's `inventory` array. */
    public function toDashboard(): array
    {
        $usage = [];
        foreach ($this->usages as $u) {
            $usage[$u->usage_key] = $u->qty;
        }

        return [
            'code'      => $this->code,
            'item'      => $this->item,
            'unit'      => $this->unit ?? '',
            'stock'     => $this->stock,
            'baseStock' => $this->base_stock,
            'unitCost'  => $this->unit_cost,
            // Empty PHP arrays encode as [] — the dashboard expects an object.
            'usage'     => (object) $usage,
        ];
    }
}
