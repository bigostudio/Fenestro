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

    // ── Stock maths: identical to the Inventory page's currentStock()/isShort()/profileValue() ──

    /** Stock value = unit cost × 5.8 × quantity, plus 18% GST (the unit cost is entered before GST). */
    public const VALUE_FACTOR = 5.8;

    public const GST_RATE = 0.18;

    /** Quantity used by projects (positive usage entries only). */
    public function usageTotal(): float
    {
        return (float) $this->usages->sum(fn (InventoryUsage $u) => max(0.0, (float) $u->qty));
    }

    /** Current stock = base stock − project usage (may be negative). */
    public function currentStock(): float
    {
        return round((float) ($this->base_stock ?? $this->stock ?? 0) - $this->usageTotal(), 3);
    }

    /** Shortage: nothing left in stock while a project still uses this profile. */
    public function isShort(): bool
    {
        return $this->currentStock() <= 0 && $this->usages->contains(fn (InventoryUsage $u) => (float) $u->qty > 0);
    }

    public function valueOf(float $qty): float
    {
        $base = max(0.0, (float) $this->unit_cost) * self::VALUE_FACTOR * max(0.0, $qty);

        return round($base * (1 + self::GST_RATE), 2);
    }

    public function stockValue(): float
    {
        return $this->valueOf($this->currentStock());
    }

    /** Value of the stock still to buy (only when current stock is below zero). */
    public function shortageValue(): float
    {
        return $this->valueOf(-$this->currentStock());
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
