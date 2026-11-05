<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-partner temporary access codes are replaced by ONE common partner access code shared by all
 * approved partners (App\Support\PartnerCommonCode).
 *
 *  - partner_common_codes: one row per code ever set; at most one is active (revoked_at null).
 *    Only a salted HMAC of the code is stored (keyed with APP_KEY). Revoked rows stay as the audit
 *    trail (who set / revoked it, when, why).
 *  - partner_access_codes (per-partner codes) is dropped, so the old mechanism cannot stay active.
 *    Sessions opened with an old per-partner code are already treated as ended by the session check.
 *
 * Rollback restores the empty per-partner table (no codes) and drops the common-code table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_common_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_salt', 32);
            $table->string('code_hash', 64);
            $table->string('created_by', 255)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable(); // replaced | revoked_by_admin | too_many_attempts
            $table->string('revoked_by', 255)->nullable();
            $table->timestamps();

            $table->index('revoked_at');
        });

        Schema::dropIfExists('partner_access_codes');
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_common_codes');

        if (! Schema::hasTable('partner_access_codes')) {
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
                $table->string('revoked_reason', 40)->nullable();
                $table->string('revoked_by', 255)->nullable();
                $table->timestamps();
                $table->index(['user_id', 'revoked_at']);
            });
        }
    }
};
