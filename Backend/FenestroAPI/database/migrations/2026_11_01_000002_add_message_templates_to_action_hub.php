<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Action Hub WhatsApp message history (requested 2026-11-01): one ticket can now have
 * several messages (Request Received, Service Scheduled, Completed, …), one row per
 * message and occasion (`dedupe_key`), all with the action's single ticket number.
 * Existing rows are the ticket's "request received" message. Additive: no row is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->string('template', 40)->default('request_received');
            // One message per action and occasion, e.g. "request_received", "schedule:1794300000"
            // (that appointment), "cancel:…". Repeated clicks reuse the row; it is never sent twice.
            $table->string('dedupe_key', 80)->nullable();
            $table->dateTime('scheduled_for')->nullable();       // appointment shown in the message (UTC)
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_by_name', 120)->nullable();      // snapshot
            $table->timestamp('accepted_at')->nullable();
        });

        DB::table('action_hub_messages')->whereNull('dedupe_key')->update(['dedupe_key' => 'request_received']);

        Schema::table('action_hub_messages', function (Blueprint $table) {
            // New unique index first (it also serves the action_id foreign key), then drop the old one.
            $table->unique(['action_id', 'dedupe_key']);
        });
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->dropUnique(['action_id']);
        });

        // One serial per month shared by PV-SG / PV-SV / PV-MSRMT / PV-INSTL (row prefix "*"),
        // continuing after the highest number already issued this month so none is reused.
        foreach (DB::table('action_hub_ticket_counters')->where('prefix', '!=', '*')->select('period')->distinct()->pluck('period') as $period) {
            DB::table('action_hub_ticket_counters')->insertOrIgnore([
                'prefix' => '*', 'period' => $period,
                'last_serial' => (int) DB::table('action_hub_ticket_counters')->where('period', $period)->where('prefix', '!=', '*')->max('last_serial'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('action_hub_ticket_counters')->where('prefix', '*')->delete();
        // Only the first message of each action can stay under the old one-per-action rule.
        DB::table('action_hub_messages')->where('dedupe_key', '!=', 'request_received')->delete();
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->unique('action_id'); // first, so the foreign key always has an index
        });
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->dropUnique(['action_id', 'dedupe_key']);
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['template', 'dedupe_key', 'scheduled_for', 'sent_by_name', 'accepted_at']);
        });
    }
};
