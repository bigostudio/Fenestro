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

        return $groups->map(fn ($apertures, $sheetName) =>
            new ApertureGroupSheet($sheetName, $apertures)
        )->values()->toArray();
    }
}
