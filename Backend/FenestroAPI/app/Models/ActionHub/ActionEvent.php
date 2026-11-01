<?php

namespace App\Models\ActionHub;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Action Hub audit history: one row per event, written once and never changed. */
class ActionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'action_hub_events';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'action_id', 'event', 'source', 'user_id', 'user_name', 'customer', 'project', 'old_status', 'new_status',
        'old_scheduled_at', 'new_scheduled_at', 'old_assignee', 'new_assignee', 'reason', 'details', 'dedupe_key', 'created_at',
    ];

    protected $casts = [
        'old_scheduled_at' => 'datetime',
        'new_scheduled_at' => 'datetime',
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }
}
