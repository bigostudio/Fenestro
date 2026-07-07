<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Measurement extends Model
{
    protected $fillable = [
        'lead_id',
        'customer_name',
        'customer_phone',
        'type',
        'measured_by',
        'date',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function apertures(): HasMany
    {
        return $this->hasMany(Aperture::class);
    }
}
