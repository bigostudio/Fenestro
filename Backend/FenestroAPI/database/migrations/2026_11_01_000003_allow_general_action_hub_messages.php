<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Action Hub "General Message" for Task / Contact Lead / Other (requested 2026-11-01):
 * those actions have no ticket, so their message rows have no ticket number. Only makes
 * two columns nullable; no row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->string('ticket_no', 30)->nullable()->change();
            $table->string('service_type', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('action_hub_messages')->whereNull('ticket_no')->delete(); // general messages cannot exist without a ticket
        Schema::table('action_hub_messages', function (Blueprint $table) {
            $table->string('ticket_no', 30)->nullable(false)->change();
            $table->string('service_type', 40)->nullable(false)->change();
        });
    }
};
