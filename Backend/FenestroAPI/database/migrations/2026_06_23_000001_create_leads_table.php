<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_partner_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_name');
            $table->string('client_phone', 10);
            $table->enum('type', ['B2B', 'B2C']);
            $table->text('notes')->nullable();
            $table->enum('status', ['new', 'contacted', 'scheduled', 'completed'])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
