<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt type: 'payment' (money received) or 'refund' (money paid back to
 * the customer, e.g. when a project is aborted). Additive: every existing
 * receipt stays a payment and keeps its amount; refunds are separate rows, so
 * the original payment and the refund are both kept for the audit history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('om_order_receipts', 'kind')) {
            Schema::table('om_order_receipts', function (Blueprint $table) {
                $table->string('kind', 10)->default('payment')->after('amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('om_order_receipts', 'kind')) {
            Schema::table('om_order_receipts', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
