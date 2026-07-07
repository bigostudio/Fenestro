<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Aperture extends Model
{
    protected $fillable = [
        'measurement_id',
        'aperture_ref_id',
        'project_tower',
        'floor',
        'flat',
        'w_id',
        'location',
        'series',
        'types',
        'pnd_note',
        'lock_type',
        'mesh_type',
        'mesh_color',
        'fx_grill',
        'fx_scaffolding_ack',
        'vw_louver_type',
        'vw_grill',
        'vw_grill_ack',
        'opw_opening_v',
        'opw_opening_h',
        'opw_hinge',
        'opw_handle',
        'opd_opening_v',
        'opd_opening_h',
        'opd_hinge',
        'opd_handle',
        'opd_espag_handle',
        'opd_panel',
        'opd_panel_combo',
        'opd_bottom_frame',
        'glass_finish',
        'glass_finish_other',
        'glass_thickness',
        'glass_type',
        'glass_toughened_ack',
        'profile_color',
        'width',
        'height',
        'diagonal',
        'diagonal_auto_calc',
        'diagonal_bypass_ack',
        'notes',
        'sketch',
        'date_time_saved',
    ];

    protected $casts = [
        'types'          => 'array',
        'opd_panel_combo' => 'array',
        'glass_toughened_ack' => 'boolean',
        'date_time_saved' => 'datetime',
    ];

    public function measurement(): BelongsTo
    {
        return $this->belongsTo(Measurement::class);
    }
}
