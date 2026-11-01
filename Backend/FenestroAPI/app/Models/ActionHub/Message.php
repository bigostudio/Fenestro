<?php

namespace App\Models\ActionHub;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp message of a ticket (Request Received, Service Scheduled, …): one row per
 * action and occasion (dedupe_key), so a message is never sent twice (App\Support\ActionHubWhatsapp).
 * No message text and only a masked number are stored.
 */
class Message extends Model
{
    protected $table = 'action_hub_messages';

    protected $fillable = [
        'action_id', 'ticket_no', 'service_type', 'template', 'dedupe_key', 'scheduled_for', 'status', 'recipient_masked',
        'message_id', 'error', 'attempts', 'last_attempt_at', 'accepted_at', 'sent_by', 'sent_by_name',
        'delivery_status', 'delivery_error', 'delivery_updated_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'scheduled_for' => 'datetime',
        'last_attempt_at' => 'datetime',
        'accepted_at' => 'datetime',
        'delivery_updated_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class, 'action_id');
    }
}
