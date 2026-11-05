<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner login codes are stored hashed (HMAC-SHA256, 64 hex characters) with a per-code
 * failed-attempt counter.
 *
 * Additive only: the users table is not rebuilt and no account data changes. The legacy
 * plaintext `otp` column stays (unused) so a code rollback still works; any plaintext code
 * still in it is cleared — those codes live at most 10 minutes, so partners mid-login simply
 * request a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'otp_hash')) {
                $table->string('otp_hash', 64)->nullable()->after('otp');
            }
            if (! Schema::hasColumn('users', 'otp_attempts')) {
                $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at');
            }
        });

        DB::table('users')->whereNotNull('otp')->update(['otp' => null, 'otp_expires_at' => null]);
    }

    /**
     * Rollback drops the two columns: codes issued after the upgrade stop working (partners
     * request a new one). The cleared plaintext codes are not restored.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['otp_hash', 'otp_attempts']);
        });
    }
};
