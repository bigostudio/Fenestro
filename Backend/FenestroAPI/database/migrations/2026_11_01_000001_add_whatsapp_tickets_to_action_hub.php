<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action Hub service tickets and their WhatsApp message (requested 2026-11-01).
 * Only adds a nullable column and two new tables: existing actions keep
 * ticket_no = null and get no message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_hub_actions', function (Blueprint $table) {
            // PV-SG-2611-001 … set once when an eligible action is created, never changed.
            $table->string('ticket_no', 30)->nullable()->unique();
        });

        // One row per ticket prefix and month (YYMM). The serial only goes up, so a
        // number is never handed out twice even if an action is removed.
        Schema::create('action_hub_ticket_counters', function (Blueprint $table) {
            $table->string('prefix', 20);
            $table->string('period', 4);
            $table->unsignedInteger('last_serial')->default(0);
            $table->timestamps();
            $table->primary(['prefix', 'period']);
        });

        // The "request received" WhatsApp message of one ticket: current state of
        // delivery (each attempt is also written to action_hub_events). No message
        // text and only a masked number are stored.
        Schema::create('action_hub_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->unique()->constrained('action_hub_actions');
            $table->string('ticket_no', 30);
            $table->string('service_type', 40);          // Snag / Service / Measurement / Installation
            $table->string('status', 20)->default('pending'); // pending, sending, accepted, failed, not_configured
            $table->string('recipient_masked', 20)->nullable();
            $table->string('message_id', 128)->nullable()->index(); // WhatsApp message id (webhook statuses)
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('delivery_status', 20)->nullable(); // sent, delivered, read, failed (webhook)
            $table->text('delivery_error')->nullable();
            $table->timestamp('delivery_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_hub_messages');
        Schema::dropIfExists('action_hub_ticket_counters');
        Schema::table('action_hub_actions', function (Blueprint $table) {
            $table->dropUnique(['ticket_no']);
            $table->dropColumn('ticket_no');
        });
    }
};
