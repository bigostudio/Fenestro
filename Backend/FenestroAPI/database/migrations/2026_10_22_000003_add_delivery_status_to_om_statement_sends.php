<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery status reported by the WhatsApp webhook for each statement
 * (sent / delivered / read / failed). Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('om_statement_sends', function (Blueprint $table) {
            $table->string('delivery_status', 12)->nullable()->after('error');
            $table->string('delivery_error', 255)->nullable()->after('delivery_status');
            $table->timestamp('delivery_updated_at')->nullable()->after('delivery_error');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('om_statement_sends', function (Blueprint $table) {
            $table->dropIndex(['message_id']);
            $table->dropColumn(['delivery_status', 'delivery_error', 'delivery_updated_at']);
        });
    }
};
