<?php

namespace App\Models\ActionHub;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One Action Hub appointment / follow-up. Status rules: App\Support\ActionHub. */
class Action extends Model
{
    protected $table = 'action_hub_actions';

    protected $fillable = [
        'customer', 'project', 'phone', 'description', 'action_type', 'action_other', 'scheduled_at',
        'status', 'assigned_to', 'version', 'status_changed_at', 'created_by', 'updated_by', 'ticket_no',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'status_changed_at' => 'datetime',
        'version' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(ActionEvent::class, 'action_id')->orderBy('id');
    }

    /** The ticket's most recently used WhatsApp message (the row and report show its state). */
    public function message(): HasOne
    {
        return $this->hasOne(Message::class, 'action_id')->ofMany(['updated_at' => 'max', 'id' => 'max']);
    }

    /** Every WhatsApp message of the ticket, oldest first (eligible actions created from 2026-11-01 only). */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'action_id')->orderBy('id');
    }
}
