<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Inventory → Add Stock and Bulk Data. Admin-only (routes/api.php).
 * Stock additions increase the profile's base stock (current stock = base
 * stock − project usage, exactly as before) and are recorded in
 * om_stock_movements as an audit trail. Shortage rules are unchanged.
 */
class InventoryStockController extends Controller
{
    private const MAX_QTY = 999999;

    /** Shared row rules (Add Stock and each Bulk Data row). */
    private function rowRules(bool $isNew): array
    {
        return [
            'code'      => ['required', 'string', 'max:100', 'not_regex:/[\/\\\\<>"\']/'],
            'item'      => array_filter([$isNew ? 'required' : 'nullable', 'string', 'max:255', 'not_regex:/[<>]/']),
            // A new profile code may start with an opening quantity of 0; adding stock needs more than 0.
            'qty'       => ['required', 'integer', $isNew ? 'min:0' : 'gt:0', 'max:'.self::MAX_QTY],
            'unit'      => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9 .\/-]+$/'],
            'date'      => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now('Asia/Kolkata')->toDateString()],
            'reference' => ['nullable', 'string', 'max:255', 'not_regex:/[<>]/'],
            'remarks'   => ['nullable', 'string', 'max:1000', 'not_regex:/[<>]/'],
            'unitCost'  => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    private function messages(): array
    {
        return [
            'qty.gt'              => 'Quantity must be greater than zero.',
            'qty.min'             => 'Opening quantity cannot be negative.',
            'qty.required'        => 'Quantity is required.',
            'qty.numeric'         => 'Quantity must be a number.',
            'qty.integer'         => 'Quantity must be a whole number.',
            'item.required'       => 'New materials need an item description.',
            'code.required'       => 'Profile code is required.',
            'code.not_regex'      => 'Profile codes cannot contain / \\ < > or quotes.',
            'date.before_or_equal'=> 'The date cannot be in the future.',
            'date.date_format'    => 'Use a date in YYYY-MM-DD format.',
            'unit.regex'          => 'Unit may only contain letters, numbers, spaces, dots, slashes and hyphens.',
        ];
    }

    private function findProfile(string $code): ?InventoryProfile
    {
        return InventoryProfile::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();
    }

    /** Apply one addition (inside a transaction). */
    private function addToProfile(InventoryProfile $profile, array $row, string $source, int $userId): StockMovement
    {
        $profile = InventoryProfile::whereKey($profile->id)->lockForUpdate()->first();
        $qty = round((float) $row['qty'], 3);
        $profile->base_stock = round($profile->base_stock + $qty, 3);
        $profile->stock = round($profile->stock + $qty, 3);
        if (! $profile->unit && ! empty($row['unit'])) {
            $profile->unit = $row['unit'];
        }
        $profile->updated_by = $userId;
        $profile->save();

        return StockMovement::create([
            'profile_id'    => $profile->id,
            'qty'           => $qty,
            'unit'          => $row['unit'] ?? $profile->unit,
            'movement_date' => $row['date'] ?? now('Asia/Kolkata')->toDateString(),
            'reference'     => $row['reference'] ?? null,
            'remarks'       => $row['remarks'] ?? null,
            'source'        => $source,
            'balance_after' => $profile->base_stock,
            'created_by'    => $userId,
        ]);
    }

    // ── Add Stock (one material) ───────────────────────────────────────

    public function add(Request $request): JsonResponse
    {
        $data = $request->validate($this->rowRules(false), $this->messages());
        $profile = $this->findProfile($data['code']);
        if (! $profile) {
            return response()->json(['message' => 'Unknown profile code. Add the profile first (or use Bulk Data to create it).', 'errors' => ['code' => ['Unknown profile code.']]], 422);
        }

        $movement = DB::transaction(fn () => $this->addToProfile($profile, $data, 'add', $request->user()->id));

        return response()->json([
            'data'     => $profile->fresh('usages')->toDashboard(),
            'movement' => $movement->only(['qty', 'unit', 'movement_date', 'reference', 'balance_after']),
        ], 201);
    }

    // ── Bulk Data (review, then save) ──────────────────────────────────

    /**
     * Validates every row and reports, per row, whether it CREATES a new
     * material or ADDS stock to an existing one. With commit=true, rows are
     * saved in one transaction — only if every row is valid (invalid rows are
     * never silently skipped).
     */
    public function bulk(Request $request): JsonResponse
    {
        $request->validate([
            'rows'   => 'required|array|min:1|max:500',
            'rows.*' => 'array',
            'commit' => 'boolean',
        ]);

        $report = [];
        $seen = [];
        foreach (array_values($request->input('rows')) as $i => $raw) {
            $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_intersect_key((array) $raw, array_flip(['code', 'item', 'qty', 'unit', 'date', 'reference', 'remarks', 'unitCost'])));
            $row = array_map(fn ($v) => $v === '' ? null : $v, $row);
            $existing = isset($row['code']) && is_string($row['code']) ? $this->findProfile($row['code']) : null;
            $isNew = ! $existing;

            $v = Validator::make($row, $this->rowRules($isNew), $this->messages());
            $errors = $v->errors()->all();

            $key = mb_strtolower((string) ($row['code'] ?? ''));
            if ($key !== '' && isset($seen[$key])) {
                $errors[] = 'This profile code already appears in row '.$seen[$key].'. Combine the quantities into one row.';
            } elseif ($key !== '') {
                $seen[$key] = $i + 1;
            }

            $current = $existing ? round($existing->base_stock - $existing->usages->sum(fn ($u) => max(0, $u->qty)), 3) : null;
            $report[] = [
                'row'           => $i + 1,
                'code'          => $existing?->code ?? ($row['code'] ?? ''),
                'action'        => $isNew ? 'create' : 'add',
                'item'          => $existing?->item ?? ($row['item'] ?? ''),
                'qty'           => is_numeric($row['qty'] ?? null) ? (float) $row['qty'] : null,
                'unit'          => $row['unit'] ?? $existing?->unit ?? '',
                'current_stock' => $current,
                'stock_after'   => $current !== null && is_numeric($row['qty'] ?? null) ? round($current + (float) $row['qty'], 3) : (is_numeric($row['qty'] ?? null) ? (float) $row['qty'] : null),
                'errors'        => $errors,
                '_data'         => $row,
            ];
        }

        $invalid = count(array_filter($report, fn ($r) => $r['errors']));
        $public = array_map(fn ($r) => array_diff_key($r, ['_data' => 1]), $report);

        if (! $request->boolean('commit') || $invalid) {
            return response()->json([
                'committed' => false,
                'rows'      => $public,
                'valid'     => count($report) - $invalid,
                'invalid'   => $invalid,
                'message'   => $invalid ? "$invalid row(s) need correcting before anything can be saved." : 'All rows are valid. Review and save.',
            ], $request->boolean('commit') && $invalid ? 422 : 200);
        }

        $userId = $request->user()->id;
        $profiles = DB::transaction(function () use ($report, $userId) {
            $touched = [];
            foreach ($report as $r) {
                $row = $r['_data'];
                if ($r['action'] === 'create') {
                    $profile = InventoryProfile::create([
                        'code' => $row['code'], 'item' => $row['item'], 'unit' => $row['unit'] ?? null,
                        'base_stock' => 0, 'stock' => 0, 'unit_cost' => $row['unitCost'] ?? 0, 'updated_by' => $userId,
                    ]);
                    if ((float) $row['qty'] > 0) { // opening quantity 0: nothing received, so no stock movement
                        $this->addToProfile($profile, $row, 'create', $userId);
                    }
                } else {
                    $profile = $this->findProfile($row['code']);
                    $this->addToProfile($profile, $row, 'bulk', $userId);
                }
                $touched[$profile->id] = $profile->id;
            }

            return InventoryProfile::with('usages')->whereIn('id', $touched)->get();
        });

        return response()->json([
            'committed' => true,
            'rows'      => $public,
            'created'   => count(array_filter($report, fn ($r) => $r['action'] === 'create')),
            'added'     => count(array_filter($report, fn ($r) => $r['action'] === 'add')),
            'profiles'  => $profiles->map->toDashboard()->values(),
        ], 201);
    }
}
