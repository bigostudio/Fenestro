<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Action Hub (Workspace → Action Hub): appointments / follow-ups, their
 * permanent audit history and the admin-managed holiday list used for the
 * working-day rule. Additive: no existing table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_hub_actions', function (Blueprint $table) {
            $table->id();
            $table->string('customer', 255);              // saved in upper case
            $table->string('project', 255);               // saved in upper case
            $table->string('phone', 10);                  // 10-digit Indian mobile
            $table->text('description')->nullable();
            $table->string('action_type', 40);
            $table->string('action_other', 120)->nullable(); // what "Other" means
            $table->dateTime('scheduled_at');             // UTC; entered in config('action_hub.timezone')
            $table->string('status', 20)->default('On Track');
            $table->string('assigned_to', 40);
            $table->unsignedInteger('version')->default(1); // stale-edit check
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        // Never updated or deleted by the application.
        Schema::create('action_hub_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('action_id')->nullable()->constrained('action_hub_actions'); // null: a rejected create
            $table->string('event', 40);                  // created, submitted, edited, status_changed, auto_get_ready…
            $table->string('source', 10);                 // manual | automatic
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 120)->nullable(); // snapshot, kept if the account changes
            $table->string('customer', 255)->nullable();  // snapshots at the time of the event
            $table->string('project', 255)->nullable();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->dateTime('old_scheduled_at')->nullable();
            $table->dateTime('new_scheduled_at')->nullable();
            $table->string('old_assignee', 40)->nullable();
            $table->string('new_assignee', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('details')->nullable();          // changed field names, errors… never phone numbers or tokens
            $table->string('dedupe_key', 120)->nullable()->unique(); // retries / repeated scheduler runs
            $table->timestamp('created_at', 6)->useCurrent();  // exact server time of the event
            $table->index(['action_id', 'id']);
            $table->index(['event', 'created_at']);
        });

        Schema::create('action_hub_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_hub_events');
        Schema::dropIfExists('action_hub_holidays');
        Schema::dropIfExists('action_hub_actions');
    }
};
