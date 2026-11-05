<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral-partner profiles for STAFF accounts (dual role: e.g. an administrator who is also a
 * referral partner). The users row stays the one identity — same id, phone, role and admin
 * credentials; the partner side (status, company, who decided) lives here, one row per user.
 * Ordinary referral partners keep using users.role / users.is_active and get no row.
 *
 * Additive only. Rollback drops the table: staff lose partner access, admin access is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete(); // one profile per user
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('status', 10)->default('pending'); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_profiles');
    }
};
