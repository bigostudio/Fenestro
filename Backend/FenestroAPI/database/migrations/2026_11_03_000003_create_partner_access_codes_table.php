<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator-granted temporary access codes for approved referral partners (see
 * App\Support\PartnerAccessCode). Only a keyed hash of each code is stored. Rows are never
 * deleted on revoke/expiry, so the table doubles as the audit trail of who granted access when.
 *
 * Additive only: no existing table or account changes. Rollback drops the table, which ends
 * every temporary access (partners fall back to the normal WhatsApp OTP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_access_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->string('granted_by', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable(); // revoked_by_admin | replaced | too_many_attempts | ...
            $table->string('revoked_by', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_access_codes');
    }
};
