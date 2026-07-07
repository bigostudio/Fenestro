<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apertures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('measurement_id')->constrained()->cascadeOnDelete();
            $table->string('aperture_ref_id');         // ap_001, ap_002 ...

            // Location fields
            $table->string('project_tower')->nullable();
            $table->string('floor')->nullable();
            $table->string('flat')->nullable();
            $table->string('w_id')->nullable();
            $table->string('location')->nullable();
            $table->string('series', 5)->default('60');

            // Window/door types (JSON array: ["FX","OPW"])
            $table->json('types')->nullable();

            // PND
            $table->text('pnd_note')->nullable();

            // 2T / 2.5T / W3T / D3T
            $table->string('lock_type')->nullable();
            $table->string('mesh_type')->nullable();
            $table->string('mesh_color')->nullable();

            // FX
            $table->string('fx_grill')->nullable();
            $table->string('fx_scaffolding_ack')->nullable();

            // VW (VWFL / VWFR / VWoF)
            $table->string('vw_louver_type')->nullable();
            $table->string('vw_grill')->nullable();
            $table->string('vw_grill_ack')->nullable();

            // OPW
            $table->string('opw_opening_v')->nullable();
            $table->string('opw_opening_h')->nullable();
            $table->string('opw_hinge')->nullable();
            $table->string('opw_handle')->nullable();

            // OPD
            $table->string('opd_opening_v')->nullable();
            $table->string('opd_opening_h')->nullable();
            $table->string('opd_hinge')->nullable();
            $table->string('opd_handle')->nullable();
            $table->string('opd_espag_handle')->nullable();
            $table->string('opd_panel')->nullable();
            $table->json('opd_panel_combo')->nullable();
            $table->string('opd_bottom_frame')->nullable();

            // Glass
            $table->string('glass_finish')->nullable();
            $table->string('glass_finish_other')->nullable();
            $table->string('glass_thickness', 5)->nullable();
            $table->string('glass_type')->nullable();
            $table->boolean('glass_toughened_ack')->default(false);

            // Profile
            $table->string('profile_color')->nullable();

            // Dimensions
            $table->string('width', 10)->nullable();
            $table->string('height', 10)->nullable();
            $table->string('diagonal', 10)->nullable();
            $table->string('diagonal_auto_calc', 10)->nullable();
            $table->text('diagonal_bypass_ack')->nullable();

            // Notes & sketch
            $table->text('notes')->nullable();
            $table->mediumText('sketch')->nullable();

            $table->timestamp('date_time_saved')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apertures');
    }
};
