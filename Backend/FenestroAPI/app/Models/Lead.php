<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer lead. lead_no (PV-LD-YYMM-NNN), status_code, lifecycle and the RAG rules are managed by
 * App\Support\LeadWorkflow — never set lead_no or lifecycle directly. `status` is the legacy coarse
 * status (new / contacted / scheduled / completed) still filled for older screens.
 */
class Lead extends Model
{
    protected $fillable = [
        'referral_partner_id',
        'client_name',
        'client_phone',
        'type',
        'notes',
        'status',
        'status_code',
        'assigned_to',
        'scheduled_at',
        'measured_at',
        'to_partner',
        'to_customer',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'measured_at'  => 'datetime',
        'closed_at'    => 'datetime',
        'to_partner'   => 'boolean',
        'to_customer'  => 'boolean',
        'is_demo'      => 'boolean',
        'version'      => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referral_partner_id');
    }

    public function measurement(): HasOne
    {
        return $this->hasOne(Measurement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(LeadEvent::class);
    }
}
