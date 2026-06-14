<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 10)->unique()->after('name');
            $table->string('company')->nullable()->after('phone');
            $table->enum('role', ['admin', 'referral_partner'])->default('referral_partner')->after('company');
            $table->enum('status', ['pending', 'approved', 'rejected', 'active'])->default('pending')->after('role');
            $table->string('otp', 6)->nullable()->after('status');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'company', 'role', 'status', 'otp', 'otp_expires_at']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
