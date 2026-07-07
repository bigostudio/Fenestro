<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'leadId'                            => 'required|integer|exists:leads,id',
            'customerName'                      => 'required|string|max:255',
            'customerPhone'                     => 'required|string|max:15',
            'type'                              => 'required|in:B2B,B2C',
            'measuredBy'                        => 'required|string|max:255',
            'date'                              => 'required|string',
            'status'                            => 'required|in:draft,submitted',

            'apertures'                         => 'required|array|min:1',
            'apertures.*.id'                    => 'required|string',
            'apertures.*.projectTower'          => 'nullable|string',
            'apertures.*.floor'                 => 'nullable|string',
            'apertures.*.flat'                  => 'nullable|string',
            'apertures.*.wId'                   => 'nullable|string',
            'apertures.*.location'              => 'nullable|string',
            'apertures.*.series'                => 'nullable|in:60,50',
            'apertures.*.types'                 => 'nullable|array',
            'apertures.*.types.*'               => 'string|in:2T,2.5T,W3T,D3T,FX,OPW,OPD,VWFL,VWFR,VWoF,PND',
            'apertures.*.pndNote'               => 'nullable|string',
            'apertures.*.lockType'              => 'nullable|string',
            'apertures.*.meshType'              => 'nullable|string',
            'apertures.*.meshColor'             => 'nullable|string',
            'apertures.*.fxGrill'               => 'nullable|string',
            'apertures.*.fxScaffoldingAck'      => 'nullable|string',
            'apertures.*.vwLouverType'          => 'nullable|string',
            'apertures.*.vwGrill'               => 'nullable|string',
            'apertures.*.vwGrillAck'            => 'nullable|string',
            'apertures.*.opwOpeningV'           => 'nullable|string',
            'apertures.*.opwOpeningH'           => 'nullable|string',
            'apertures.*.opwHinge'              => 'nullable|string',
            'apertures.*.opwHandle'             => 'nullable|string',
            'apertures.*.opdOpeningV'           => 'nullable|string',
            'apertures.*.opdOpeningH'           => 'nullable|string',
            'apertures.*.opdHinge'              => 'nullable|string',
            'apertures.*.opdHandle'             => 'nullable|string',
            'apertures.*.opdEspagHandle'        => 'nullable|string',
            'apertures.*.opdPanel'              => 'nullable|string',
            'apertures.*.opdPanelCombo'         => 'nullable|array',
            'apertures.*.opdBottomFrame'        => 'nullable|string',
            'apertures.*.glassFinish'           => 'nullable|string',
            'apertures.*.glassFinishOther'      => 'nullable|string',
            'apertures.*.glassThickness'        => 'nullable|string',
            'apertures.*.glassType'             => 'nullable|string',
            'apertures.*.glassToughenedAck'     => 'nullable|boolean',
            'apertures.*.profileColor'          => 'nullable|string',
            'apertures.*.width'                 => 'nullable|string|max:4',
            'apertures.*.height'                => 'nullable|string|max:4',
            'apertures.*.diagonal'              => 'nullable|string|max:4',
            'apertures.*.diagonalAutoCalc'      => 'nullable|string',
            'apertures.*.diagonalBypassAck'     => 'nullable|string',
            'apertures.*.notes'                 => 'nullable|string',
            'apertures.*.sketch'                => 'nullable|string',
            'apertures.*.dateTimeSaved'         => 'nullable|string',
        ];
    }
}
