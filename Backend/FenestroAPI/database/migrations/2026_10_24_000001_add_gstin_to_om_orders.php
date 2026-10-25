<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer GSTIN on Order Management orders (shown as "GSTIN" under BILLED TO
 * on payment statements). Additive and nullable: existing orders are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('om_orders', 'gstin')) {
            Schema::table('om_orders', function (Blueprint $table) {
                $table->string('gstin', 15)->nullable()->after('email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('om_orders', 'gstin')) {
            Schema::table('om_orders', function (Blueprint $table) {
                $table->dropColumn('gstin');
            });
        }
    }
};
