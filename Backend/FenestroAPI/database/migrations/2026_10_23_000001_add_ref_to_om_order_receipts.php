<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request reference for refunds recorded by the Project Abort dialog. The same
 * confirmation sent twice (double click, network retry) finds its refund by this
 * reference and records nothing new. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('om_order_receipts', 'ref')) {
            Schema::table('om_order_receipts', function (Blueprint $table) {
                $table->string('ref', 64)->nullable()->after('notes');
                $table->index(['order_id', 'ref']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('om_order_receipts', 'ref')) {
            Schema::table('om_order_receipts', function (Blueprint $table) {
                $table->dropIndex(['order_id', 'ref']);
                $table->dropColumn('ref');
            });
        }
    }
};
