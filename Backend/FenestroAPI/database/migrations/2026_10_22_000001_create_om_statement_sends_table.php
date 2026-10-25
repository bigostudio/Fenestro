<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log of payment statements sent through the WhatsApp Cloud API (one row per
 * attempt). Used to show results and to stop a retry or double click from
 * sending the same statement twice. Additive: no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('om_statement_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('om_orders')->cascadeOnDelete();
            $table->string('recipient', 20);
            $table->string('fingerprint', 64)->index();   // order + figures + payments + message + recipient
            $table->string('filename', 120);
            $table->string('status', 12);                 // accepted | failed
            $table->string('message_id', 120)->nullable(); // WhatsApp message id when accepted
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('om_statement_sends');
    }
};
