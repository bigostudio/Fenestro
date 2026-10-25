<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer email on Order Management orders (shown as "Email:" on payment
 * statements). Additive and nullable: existing orders are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('om_orders', 'email')) {
            Schema::table('om_orders', function (Blueprint $table) {
                $table->string('email', 150)->nullable()->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('om_orders', 'email')) {
            Schema::table('om_orders', function (Blueprint $table) {
                $table->dropColumn('email');
            });
        }
    }
};
