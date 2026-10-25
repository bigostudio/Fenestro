<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables backing the Prestige Order Management dashboard (/admin/orders).
 * Prefixed `om_` to keep them separate from the Fenestro partner `leads`
 * table, which holds different data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('om_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // e.g. PV-2610-001
            $table->string('customer');
            $table->string('project');
            $table->string('phone', 20)->nullable();
            $table->date('confirmed');
            $table->decimal('sft', 14, 3)->default(0);
            $table->decimal('rate', 14, 3)->default(0);
            $table->decimal('tax', 5, 2)->default(0);
            $table->enum('written_off', ['No', 'Yes'])->default('No');
            $table->text('remarks')->nullable();
            $table->json('statuses');                       // CNS readiness statuses keyed by status key
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_comm_change')->nullable();
            $table->timestamp('last_comm_reset')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('om_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('om_orders')->cascadeOnDelete();
            $table->date('date')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('position')->default(0); // keeps the order receipts were entered in
            $table->timestamps();
        });

        Schema::create('om_inventory_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();         // profile code, e.g. EWSPL-LF-1001
            $table->string('item');
            $table->decimal('base_stock', 14, 3)->default(0); // stock before project usage is deducted
            $table->decimal('stock', 14, 3)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('om_inventory_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('om_inventory_profiles')->cascadeOnDelete();
            // Usually an om_orders.code; may also be a legacy project/customer name, so not a foreign key.
            $table->string('usage_key');
            $table->decimal('qty', 14, 3);
            $table->timestamps();
            $table->unique(['profile_id', 'usage_key']);
        });

        Schema::create('om_inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();          // e.g. LD-12345678
            $table->string('name');
            $table->string('phone', 10);
            $table->string('contact_status', 64);
            $table->string('outcome', 32)->nullable();
            $table->string('appointment', 19)->nullable(); // local "YYYY-MM-DDTHH:MM" exactly as entered
            $table->string('appointment_result', 32)->nullable();
            $table->string('building_type', 32);
            $table->string('referred_by')->nullable();
            $table->string('map', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('om_inquiries');
        Schema::dropIfExists('om_inventory_usages');
        Schema::dropIfExists('om_inventory_profiles');
        Schema::dropIfExists('om_order_receipts');
        Schema::dropIfExists('om_orders');
    }
};
