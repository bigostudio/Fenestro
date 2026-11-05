<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads workflow (2026-11-03): Lead ID (PV-LD-YYMM-NNN), the 14 status codes, assignment,
 * measurement schedule / completion, lifecycle (active / closed / archived), WhatsApp recipient
 * choice, edit version, demo flag, a monthly ID counter and a history table.
 *
 * Existing leads are kept: each gets a Lead ID (month of its created_at, in id order) and a status
 * code mapped from the legacy status (new → LEAD-RCVD, contacted → ATT-1-CALL-LATER,
 * scheduled → MSRMT-SCH, completed → MSRMT-FULL; a submitted measurement → MSRMT-FULL dated by the
 * measurement). The legacy `status` column is kept and still filled for older screens. Each
 * mapping is written to the lead's history.
 *
 * referral_partner_id becomes nullable so the office can record a lead with no referring partner.
 *
 * Rollback: drops the new tables and columns (Lead IDs, codes, schedules, notes history are lost;
 * the legacy status and notes stay). referral_partner_id stays nullable on rollback (making it
 * NOT NULL again would fail for leads created without a partner).
 */
return new class extends Migration
{
    private const LEGACY = ['new' => 'LEAD-RCVD', 'contacted' => 'ATT-1-CALL-LATER', 'scheduled' => 'MSRMT-SCH', 'completed' => 'MSRMT-FULL'];

    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('referral_partner_id')->nullable()->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('lead_no', 20)->nullable()->unique()->after('id');
            $table->string('status_code', 30)->default('LEAD-RCVD')->after('status');
            $table->string('assigned_to', 40)->nullable()->after('status_code');
            $table->dateTime('scheduled_at')->nullable()->after('assigned_to');   // UTC
            $table->dateTime('measured_at')->nullable()->after('scheduled_at');    // UTC, MSRMT-FULL
            $table->string('lifecycle', 10)->default('active')->after('measured_at'); // active | closed | archived
            $table->string('closure_reason', 80)->nullable()->after('lifecycle');
            $table->timestamp('closed_at')->nullable()->after('closure_reason');
            $table->boolean('to_partner')->default(false)->after('closed_at');
            $table->boolean('to_customer')->default(false)->after('to_partner');
            $table->unsignedInteger('version')->default(1)->after('to_customer');
            $table->boolean('is_demo')->default(false)->after('version');
            $table->foreignId('created_by')->nullable()->after('is_demo')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->index(['lifecycle', 'status_code']);
        });

        Schema::create('lead_id_counters', function (Blueprint $table) {
            $table->string('period', 4)->primary(); // YYMM, business time zone
            $table->unsignedInteger('last_serial')->default(0);
            $table->timestamps();
        });

        Schema::create('lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('event', 40);           // created, status_changed, assigned, scheduled, note_edited, closed, archived, reopened, whatsapp_draft, migrated
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->dateTime('old_scheduled_at')->nullable();
            $table->dateTime('new_scheduled_at')->nullable();
            $table->string('old_assignee', 40)->nullable();
            $table->string('new_assignee', 40)->nullable();
            $table->text('old_notes')->nullable();   // the previous notes text when notes were edited
            $table->json('details')->nullable();     // never phone numbers or tokens
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['lead_id', 'id']);
        });

        $this->backfill();
    }

    private function backfill(): void
    {
        $tz = (string) config('leads.timezone', 'Asia/Kolkata');
        $prefix = (string) config('leads.id_prefix', 'PV-LD');
        $serials = [];
        $submitted = DB::table('measurements')->where('status', 'submitted')->orderBy('id')->get(['lead_id', 'date'])->keyBy('lead_id');

        DB::table('leads')->orderBy('id')->each(function ($lead) use ($tz, $prefix, &$serials, $submitted) {
            $created = Carbon::parse($lead->created_at ?? now(), 'UTC')->setTimezone($tz);
            $period = $created->format('ym');
            $serials[$period] = ($serials[$period] ?? 0) + 1;
            $code = self::LEGACY[$lead->status] ?? 'LEAD-RCVD';
            $measuredAt = null;
            if ($m = $submitted->get($lead->id)) {
                $code = 'MSRMT-FULL';
                $measuredAt = Carbon::parse($m->date, $tz)->setTime(12, 0)->utc();
            } elseif ($code === 'MSRMT-FULL') {
                $measuredAt = Carbon::parse($lead->updated_at ?? $lead->created_at ?? now(), 'UTC'); // best known date
            }
            DB::table('leads')->where('id', $lead->id)->update([
                'lead_no' => sprintf('%s-%s-%03d', $prefix, $period, $serials[$period]),
                'status_code' => $code,
                'measured_at' => $measuredAt,
            ]);
            DB::table('lead_events')->insert([
                'lead_id' => $lead->id, 'event' => 'migrated', 'new_status' => $code,
                'details' => json_encode(['legacy_status' => $lead->status, 'measured_at_source' => $measuredAt ? ($m ? 'measurement date' : 'last update') : null]),
                'created_at' => now(),
            ]);
        });

        foreach ($serials as $period => $last) {
            DB::table('lead_id_counters')->insert(['period' => $period, 'last_serial' => $last, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_events');
        Schema::dropIfExists('lead_id_counters');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropIndex(['lifecycle', 'status_code']);
            $table->dropUnique(['lead_no']);
        });
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['lead_no', 'status_code', 'assigned_to', 'scheduled_at', 'measured_at', 'lifecycle', 'closure_reason',
                'closed_at', 'to_partner', 'to_customer', 'version', 'is_demo', 'created_by', 'updated_by']);
        });
    }
};
