<?php

namespace App\Exports;

use App\Models\Measurement;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MeasurementExport implements WithMultipleSheets
{
    public function __construct(private Measurement $measurement) {}

    public function sheets(): array
    {
        $groups = $this->measurement->apertures->groupBy(function ($aperture) {
            $types = $aperture->types ?? [];
            sort($types);
            return implode(' & ', $types) ?: 'Untyped';
        });

        // A workbook needs at least one sheet: a measurement with no apertures yet (e.g. created
        // from a lead before any opening was recorded) exports the column headings only.
        if ($groups->isEmpty()) {
            return [new ApertureGroupSheet('Apertures', collect())];
        }

        return $groups->map(fn ($apertures, $sheetName) =>
            new ApertureGroupSheet($sheetName, $apertures)
        )->values()->toArray();
    }
}
