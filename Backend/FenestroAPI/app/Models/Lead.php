<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    protected $fillable = [
        'referral_partner_id',
        'client_name',
        'client_phone',
        'type',
        'notes',
        'status',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referral_partner_id');
    }

    public function measurement(): HasOne
    {
        return $this->hasOne(Measurement::class);
    }
}
