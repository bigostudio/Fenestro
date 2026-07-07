<?php

namespace App\Http\Controllers;

use App\Exports\MeasurementExport;
use App\Http\Requests\MeasurementRequest;
use App\Models\Measurement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeasurementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sqftSub = DB::table('apertures')
            ->selectRaw('COALESCE(SUM((width / 304.8) * (height / 304.8)), 0)')
            ->whereColumn('measurement_id', 'measurements.id');

        $query = Measurement::query()
            ->selectRaw('measurements.*')
            ->selectSub($sqftSub, 'total_sq_ft')
            ->withCount('apertures as total_units')
            ->with(['lead:id,client_name,client_phone,type,notes,status']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('customer_name')) {
            $query->where('customer_name', 'like', '%' . $request->customer_name . '%');
        }
        if ($request->filled('lead_id')) {
            $query->where('lead_id', $request->lead_id);
        }

        $measurements = $query->latest()->paginate(15);

        $measurements->getCollection()->transform(fn ($m) => [
            'id'            => $m->id,
            'leadId'        => $m->lead_id,
            'lead'          => $m->lead ? [
                'id'          => $m->lead->id,
                'clientName'  => $m->lead->client_name,
                'clientPhone' => $m->lead->client_phone,
                'type'        => $m->lead->type,
                'notes'       => $m->lead->notes,
                'status'      => $m->lead->status,
            ] : null,
            'customerName'  => $m->customer_name,
            'customerPhone' => $m->customer_phone,
            'type'          => $m->type,
            'measuredBy'    => $m->measured_by,
            'date'          => $m->date,
            'status'        => $m->status,
            'totalUnits'    => $m->total_units,
            'totalSqFt'     => round((float) $m->total_sq_ft, 2),
            'createdAt'     => $m->created_at->toISOString(),
            'updatedAt'     => $m->updated_at->toISOString(),
        ]);

        return response()->json([
            'success'  => true,
            'response' => $measurements,
        ]);
    }

    public function store(MeasurementRequest $request): JsonResponse
    {
        $measurement = Measurement::create([
            'lead_id'            => $request->leadId,
            'customer_name'      => $request->customerName,
            'customer_phone'     => $request->customerPhone,
            'type'               => $request->type,
            'measured_by'        => $request->measuredBy,
            'date'               => Carbon::parse($request->date),
            'status'             => $request->status,
        ]);

        foreach ($request->apertures as $ap) {
            $measurement->apertures()->create($this->mapAperture($measurement->id, $ap));
        }

        $measurement->load(['lead', 'apertures']);

        return response()->json([
            'success'  => true,
            'message'  => 'Measurement created successfully.',
            'response' => $this->buildResponse($measurement),
        ], 201);
    }

    public function update(MeasurementRequest $request, Measurement $measurement): JsonResponse
    {
        $measurement->update([
            'lead_id'       => $request->leadId,
            'customer_name' => $request->customerName,
            'customer_phone'=> $request->customerPhone,
            'type'          => $request->type,
            'measured_by'   => $request->measuredBy,
            'date'          => Carbon::parse($request->date),
            'status'        => $request->status,
        ]);

        $incomingIds = collect($request->apertures)->pluck('id')->filter()->values();

        $measurement->apertures()
            ->whereNotIn('aperture_ref_id', $incomingIds)
            ->delete();

        foreach ($request->apertures as $ap) {
            $measurement->apertures()->updateOrCreate(
                ['aperture_ref_id' => $ap['id']],
                $this->mapAperture($measurement->id, $ap)
            );
        }

        $measurement->load(['lead', 'apertures']);

        return response()->json([
            'success'  => true,
            'message'  => 'Measurement updated successfully.',
            'response' => $this->buildResponse($measurement),
        ]);
    }

    public function exportExcel(Measurement $measurement): BinaryFileResponse
    {
        $measurement->load('apertures');
        $filename = 'measurement_' . $measurement->id . '_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new MeasurementExport($measurement), $filename);
    }

    public function show(Measurement $measurement): JsonResponse
    {
        $measurement->load(['lead', 'apertures']);

        return response()->json([
            'success'  => true,
            'response' => $this->buildResponse($measurement),
        ]);
    }

    private function buildResponse(Measurement $measurement): array
    {
        $totalSqFt = $measurement->apertures->sum(function ($ap) {
            $w = (float) $ap->width;
            $h = (float) $ap->height;
            if ($w <= 0 || $h <= 0) return 0;
            return ($w / 304.8) * ($h / 304.8);
        });

        $lead = $measurement->lead;

        return [
            'id'            => $measurement->id,
            'leadId'        => $measurement->lead_id,
            'lead'          => $lead ? [
                'id'          => $lead->id,
                'clientName'  => $lead->client_name,
                'clientPhone' => $lead->client_phone,
                'type'        => $lead->type,
                'notes'       => $lead->notes,
                'status'      => $lead->status,
            ] : null,
            'customerName'  => $measurement->customer_name,
            'customerPhone' => $measurement->customer_phone,
            'type'          => $measurement->type,
            'measuredBy'    => $measurement->measured_by,
            'date'          => $measurement->date,
            'status'        => $measurement->status,
            'totalUnits'    => $measurement->apertures->count(),
            'totalSqFt'     => round($totalSqFt, 2),
            'createdAt'     => $measurement->created_at->toISOString(),
            'updatedAt'     => $measurement->updated_at->toISOString(),
            'apertures'     => $measurement->apertures->map(fn ($ap) => [
                'id'                => $ap->aperture_ref_id,
                'projectTower'      => $ap->project_tower,
                'floor'             => $ap->floor,
                'flat'              => $ap->flat,
                'wId'               => $ap->w_id,
                'location'          => $ap->location,
                'series'            => $ap->series,
                'types'             => $ap->types,
                'pndNote'           => $ap->pnd_note,
                'lockType'          => $ap->lock_type,
                'meshType'          => $ap->mesh_type,
                'meshColor'         => $ap->mesh_color,
                'fxGrill'           => $ap->fx_grill,
                'fxScaffoldingAck'  => $ap->fx_scaffolding_ack,
                'vwLouverType'      => $ap->vw_louver_type,
                'vwGrill'           => $ap->vw_grill,
                'vwGrillAck'        => $ap->vw_grill_ack,
                'opwOpeningV'       => $ap->opw_opening_v,
                'opwOpeningH'       => $ap->opw_opening_h,
                'opwHinge'          => $ap->opw_hinge,
                'opwHandle'         => $ap->opw_handle,
                'opdOpeningV'       => $ap->opd_opening_v,
                'opdOpeningH'       => $ap->opd_opening_h,
                'opdHinge'          => $ap->opd_hinge,
                'opdHandle'         => $ap->opd_handle,
                'opdEspagHandle'    => $ap->opd_espag_handle,
                'opdPanel'          => $ap->opd_panel,
                'opdPanelCombo'     => $ap->opd_panel_combo,
                'opdBottomFrame'    => $ap->opd_bottom_frame,
                'glassFinish'       => $ap->glass_finish,
                'glassFinishOther'  => $ap->glass_finish_other,
                'glassThickness'    => $ap->glass_thickness,
                'glassType'         => $ap->glass_type,
                'glassToughenedAck' => $ap->glass_toughened_ack,
                'profileColor'      => $ap->profile_color,
                'width'             => $ap->width,
                'height'            => $ap->height,
                'diagonal'          => $ap->diagonal,
                'diagonalAutoCalc'  => $ap->diagonal_auto_calc,
                'diagonalBypassAck' => $ap->diagonal_bypass_ack,
                'notes'             => $ap->notes,
                'sketch'            => $ap->sketch,
                'dateTimeSaved'     => $ap->date_time_saved,
            ])->values(),
        ];
    }

    private function mapAperture(int $measurementId, array $ap): array
    {
        return [
            'measurement_id'     => $measurementId,
            'aperture_ref_id'    => $ap['id'],
            'project_tower'      => $ap['projectTower'] ?? null,
            'floor'              => $ap['floor'] ?? null,
            'flat'               => $ap['flat'] ?? null,
            'w_id'               => $ap['wId'] ?? null,
            'location'           => $ap['location'] ?? null,
            'series'             => $ap['series'] ?? '60',
            'types'              => $ap['types'] ?? [],
            'pnd_note'           => $ap['pndNote'] ?? null,
            'lock_type'          => $ap['lockType'] ?? null,
            'mesh_type'          => $ap['meshType'] ?? null,
            'mesh_color'         => $ap['meshColor'] ?? null,
            'fx_grill'           => $ap['fxGrill'] ?? null,
            'fx_scaffolding_ack' => $ap['fxScaffoldingAck'] ?? null,
            'vw_louver_type'     => $ap['vwLouverType'] ?? null,
            'vw_grill'           => $ap['vwGrill'] ?? null,
            'vw_grill_ack'       => $ap['vwGrillAck'] ?? null,
            'opw_opening_v'      => $ap['opwOpeningV'] ?? null,
            'opw_opening_h'      => $ap['opwOpeningH'] ?? null,
            'opw_hinge'          => $ap['opwHinge'] ?? null,
            'opw_handle'         => $ap['opwHandle'] ?? null,
            'opd_opening_v'      => $ap['opdOpeningV'] ?? null,
            'opd_opening_h'      => $ap['opdOpeningH'] ?? null,
            'opd_hinge'          => $ap['opdHinge'] ?? null,
            'opd_handle'         => $ap['opdHandle'] ?? null,
            'opd_espag_handle'   => $ap['opdEspagHandle'] ?? null,
            'opd_panel'          => $ap['opdPanel'] ?? null,
            'opd_panel_combo'    => $ap['opdPanelCombo'] ?? [],
            'opd_bottom_frame'   => $ap['opdBottomFrame'] ?? null,
            'glass_finish'       => $ap['glassFinish'] ?? null,
            'glass_finish_other' => $ap['glassFinishOther'] ?? null,
            'glass_thickness'    => $ap['glassThickness'] ?? null,
            'glass_type'         => $ap['glassType'] ?? null,
            'glass_toughened_ack'=> $ap['glassToughenedAck'] ?? false,
            'profile_color'      => $ap['profileColor'] ?? null,
            'width'              => $ap['width'] ?? null,
            'height'             => $ap['height'] ?? null,
            'diagonal'           => $ap['diagonal'] ?? null,
            'diagonal_auto_calc' => $ap['diagonalAutoCalc'] ?? null,
            'diagonal_bypass_ack'=> $ap['diagonalBypassAck'] ?? null,
            'notes'              => $ap['notes'] ?? null,
            'sketch'             => $ap['sketch'] ?? null,
            'date_time_saved'    => isset($ap['dateTimeSaved']) ? Carbon::parse($ap['dateTimeSaved']) : null,
        ];
    }
}
