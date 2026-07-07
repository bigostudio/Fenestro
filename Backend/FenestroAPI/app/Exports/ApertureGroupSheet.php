<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ApertureGroupSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        private string $sheetTitle,
        private Collection $apertures
    ) {}

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function headings(): array
    {
        return [
            'W-ID',
            'Location',
            'Project / Tower',
            'Floor',
            'Flat',
            'Series',
            'Types',
            'Lock Type',
            'Mesh Type',
            'Mesh Color',
            'FX Grill',
            'FX Scaffolding Ack',
            'VW Louver Type',
            'VW Grill',
            'VW Grill Ack',
            'OPW Opening (V)',
            'OPW Opening (H)',
            'OPW Hinge',
            'OPW Handle',
            'OPD Opening (V)',
            'OPD Opening (H)',
            'OPD Hinge',
            'OPD Handle',
            'OPD Espag Handle',
            'OPD Panel',
            'OPD Panel Combo',
            'OPD Bottom Frame',
            'Glass Finish',
            'Glass Finish (Other)',
            'Glass Thickness (mm)',
            'Glass Type',
            'Profile Color',
            'Width (mm)',
            'Height (mm)',
            'Diagonal (mm)',
            'Diagonal Auto Calc (mm)',
            'PND Note',
            'Notes',
            'Date / Time Saved',
        ];
    }

    public function array(): array
    {
        return $this->apertures->map(fn ($ap) => [
            $ap->w_id,
            $ap->location,
            $ap->project_tower,
            $ap->floor,
            $ap->flat,
            $ap->series,
            implode(', ', $ap->types ?? []),
            $ap->lock_type,
            $ap->mesh_type,
            $ap->mesh_color,
            $ap->fx_grill,
            $ap->fx_scaffolding_ack,
            $ap->vw_louver_type,
            $ap->vw_grill,
            $ap->vw_grill_ack,
            $ap->opw_opening_v,
            $ap->opw_opening_h,
            $ap->opw_hinge,
            $ap->opw_handle,
            $ap->opd_opening_v,
            $ap->opd_opening_h,
            $ap->opd_hinge,
            $ap->opd_handle,
            $ap->opd_espag_handle,
            $ap->opd_panel,
            implode(', ', $ap->opd_panel_combo ?? []),
            $ap->opd_bottom_frame,
            $ap->glass_finish,
            $ap->glass_finish_other,
            $ap->glass_thickness,
            $ap->glass_type,
            $ap->profile_color,
            $ap->width,
            $ap->height,
            $ap->diagonal,
            $ap->diagonal_auto_calc,
            $ap->pnd_note,
            $ap->notes,
            $ap->date_time_saved?->format('Y-m-d H:i:s'),
        ])->toArray();
    }
}
