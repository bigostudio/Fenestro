<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Report data for the admin portal's "Export report" menus. Admin-only
 * (routes/api.php) and read-only: the browser draws the PDF / Excel file
 * from this JSON. The report type is limited by the route; each report
 * only accepts its own filters, which match the page's search box and
 * dropdowns.
 */
class ReportController extends Controller
{
    public function show(Request $request, string $type): JsonResponse
    {
        $rules = ['q' => 'nullable|string|max:100'];
        foreach (AdminReports::FILTERS[$type] ?? [] as $field => $allowed) {
            $rules[$field] = ['nullable', 'string', Rule::in($allowed)];
        }
        if ($type === 'action-hub') {
            $rules = ActionHubController::filterRules(); // the page's own filters
        }
        $params = $request->validate($rules);

        return response()->json(AdminReports::build($type, array_filter($params, fn ($v) => $v !== null)));
    }
}
