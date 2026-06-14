<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = pending, 1 = approved, 0 = rejected
            $table->tinyInteger('is_active')->unsigned()->nullable()->default(null)->after('role');
        });

        // Migrate existing data
        DB::table('users')->where('status', 'approved')->orWhere('status', 'active')->update(['is_active' => 1]);
        DB::table('users')->where('status', 'rejected')->update(['is_active' => 0]);
        // pending rows stay NULL (default)

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'active'])->default('pending')->after('role');
        });

        DB::table('users')->where('is_active', 1)->update(['status' => 'approved']);
        DB::table('users')->where('is_active', 0)->update(['status' => 'rejected']);
        DB::table('users')->whereNull('is_active')->update(['status' => 'pending']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
