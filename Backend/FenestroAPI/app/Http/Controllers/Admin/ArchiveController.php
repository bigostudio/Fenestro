<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderManagement\InventoryUsage;
use App\Models\OrderManagement\Order;
use App\Support\OrderManagement;
use Illuminate\Http\JsonResponse;

/**
 * Archive page (/admin/archive). Admin-only (routes/api.php). Read-only.
 *
 * Lists every archived project — PROJ STATUS COMM = PROJECT ABORT, or
 * PROJECT COMPLETED and fully paid (outstanding ₹0; Order::isArchived) — with
 * its full record: order details in the dashboard shape (so Restore can save
 * it back through the normal order endpoint), payment figures calculated by
 * the server (same maths as Customer & Orders / Receivables) and the
 * inventory usage recorded against it. Archiving never deletes anything:
 * it is only the completion status on the order itself, so a project can
 * never appear twice.
 */
class ArchiveController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = Order::with('receipts')->get()
            ->filter(fn (Order $o) => $o->isArchived())
            ->sortByDesc(fn (Order $o) => $o->completed_at?->getTimestamp() ?? 0)
            ->values();

        $usage = InventoryUsage::with('profile')
            ->whereIn('usage_key', $orders->pluck('code'))
            ->get()
            ->filter(fn (InventoryUsage $u) => $u->profile && (float) $u->qty != 0.0)
            ->groupBy('usage_key');

        return response()->json([
            'data' => $orders->map(fn (Order $o) => [
                'order'   => $o->toDashboard(),
                'summary' => $o->receivableSummary(),
                'usage'   => ($usage->get($o->code) ?? collect())->map(fn (InventoryUsage $u) => [
                    'code' => $u->profile->code,
                    'item' => $u->profile->item,
                    'unit' => $u->profile->unit ?? '',
                    'qty'  => (float) $u->qty,
                ])->values(),
            ])->values(),
            'config' => [
                'statuses'          => OrderManagement::statusesForDashboard(),
                'completion_values' => OrderManagement::completionValues(),
                'archive_values'    => OrderManagement::archiveValues(),
            ],
        ]);
    }
}
