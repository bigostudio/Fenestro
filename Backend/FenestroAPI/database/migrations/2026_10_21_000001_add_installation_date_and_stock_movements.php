<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only — existing rows are untouched:
 *  - om_orders.installation_date      expected installation date (PROD STRTD message)
 *  - om_inventory_profiles.unit       unit of measurement for a profile (optional)
 *  - om_stock_movements               audit trail of stock additions (Add Stock / Bulk Data)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('om_orders', function (Blueprint $table) {
            $table->date('installation_date')->nullable()->after('confirmed');
        });

        Schema::table('om_inventory_profiles', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('item');
        });

        Schema::create('om_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('om_inventory_profiles')->cascadeOnDelete();
            $table->decimal('qty', 14, 3);                     // quantity added (always > 0)
            $table->string('unit', 20)->nullable();
            $table->date('movement_date');
            $table->string('reference')->nullable();           // supplier / invoice / reference
            $table->text('remarks')->nullable();
            $table->string('source', 10)->default('add');      // add | bulk | create
            $table->decimal('balance_after', 14, 3);           // base stock after this addition
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['profile_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('om_stock_movements');
        Schema::table('om_inventory_profiles', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
        Schema::table('om_orders', function (Blueprint $table) {
            $table->dropColumn('installation_date');
        });
    }
};
