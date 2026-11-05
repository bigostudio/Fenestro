<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a lead's history (never edited or deleted with the lead's status changes). */
class LeadEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'old_scheduled_at' => 'datetime',
        'new_scheduled_at' => 'datetime',
        'details'          => 'array',
        'created_at'       => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
