<?php

namespace Tests\Feature;

use App\Models\OrderManagement\InventoryProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inventory → Edit profile (PUT /admin/order-management/inventory/{code}/profile): profile code,
 * item description and unit only. Stock, unit cost, project usage and stock history are never
 * changed by it, and a renamed profile keeps all of them.
 */
class InventoryProfileEditTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/admin/order-management';

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->tokenFor('admin');
        // Profile A: opening stock 50, used 10 on a project, cost 100; then +5 through Add Stock (history).
        $this->withToken($this->admin)->postJson(self::API.'/inventory', [
            'code' => 'EWSPL-1001', 'item' => 'Outer frame', 'stock' => 50, 'baseStock' => 50, 'unitCost' => 100, 'usage' => ['PV-2610-001' => 10],
        ])->assertCreated();
        $this->withToken($this->admin)->postJson(self::API.'/inventory-stock/add', ['code' => 'EWSPL-1001', 'qty' => 5, 'unit' => 'pcs'])->assertSuccessful();
        $this->withToken($this->admin)->postJson(self::API.'/inventory', [
            'code' => 'EWSPL-2002', 'item' => 'Sash', 'stock' => 8, 'baseStock' => 8, 'unitCost' => 50, 'usage' => [],
        ])->assertCreated();
    }

    private function tokenFor(string $role): string
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.'@example.test', 'phone' => (string) random_int(6000000000, 9999999999),
            'password' => 'Correct-Horse-9!', 'role' => $role, 'is_active' => 1,
        ]);

        return auth('api')->login($user);
    }

    private function edit(string $code, array $body, ?string $token = null)
    {
        return $this->withToken($token ?? $this->admin)->putJson(self::API.'/inventory/'.rawurlencode($code).'/profile', $body);
    }

    private function snapshot(string $code): array
    {
        $p = InventoryProfile::with('usages')->where('code', $code)->firstOrFail();

        return [
            'id' => $p->id, 'stock' => $p->stock, 'base_stock' => $p->base_stock, 'unit_cost' => $p->unit_cost,
            'usage' => $p->usages->pluck('qty', 'usage_key')->all(), 'current' => $p->currentStock(),
            'movements' => DB::table('om_stock_movements')->where('profile_id', $p->id)->count(),
        ];
    }

    public function test_renaming_changes_only_code_description_and_unit(): void
    {
        $before = $this->snapshot('EWSPL-1001');
        $this->assertSame(45.0, $before['current']); // 50 + 5 − 10
        $this->assertSame(1, $before['movements']);

        $res = $this->edit('EWSPL-1001', ['code' => ' EWSPL-1001-N ', 'item' => ' Outer frame (new) ', 'unit' => 'pcs'])->assertOk()
            ->assertJsonPath('previous_code', 'EWSPL-1001')
            ->assertJsonPath('data.code', 'EWSPL-1001-N')
            ->assertJsonPath('data.item', 'Outer frame (new)')
            ->assertJsonPath('data.unit', 'pcs');
        $this->assertSame(10, (int) $res->json('data.usage.PV-2610-001'));

        $after = $this->snapshot('EWSPL-1001-N');
        $this->assertSame($before, $after, 'stock, cost, usage or stock history changed');
        $this->assertNull(InventoryProfile::where('code', 'EWSPL-1001')->first());

        // The dashboard data (row + summary inputs) carries the new code with the same numbers.
        $row = collect($this->withToken($this->admin)->getJson(self::API.'/data')->assertOk()->json('inventory'))->firstWhere('code', 'EWSPL-1001-N');
        $this->assertSame([55.0, 100.0], [(float) $row['baseStock'], (float) $row['unitCost']]);
    }

    public function test_stock_cost_and_usage_in_the_request_are_ignored(): void
    {
        $before = $this->snapshot('EWSPL-1001');
        $this->edit('EWSPL-1001', [
            'code' => 'EWSPL-1001', 'item' => 'Outer frame', 'unit' => '',
            'stock' => 999, 'baseStock' => 999, 'unitCost' => 1, 'usage' => ['PV-2610-001' => 0, 'X' => 3],
        ])->assertOk()->assertJsonPath('data.unit', '');
        $this->assertSame($before, $this->snapshot('EWSPL-1001'));
    }

    public function test_duplicate_codes_are_refused_ignoring_letter_case(): void
    {
        foreach (['EWSPL-2002', 'ewspl-2002', ' Ewspl-2002 '] as $taken) {
            $this->edit('EWSPL-1001', ['code' => $taken, 'item' => 'Outer frame'])->assertUnprocessable()
                ->assertJsonValidationErrors(['code' => 'That profile code already exists.']);
        }
        $this->assertNotNull(InventoryProfile::where('code', 'EWSPL-1001')->first());
        $this->assertSame('Sash', InventoryProfile::where('code', 'EWSPL-2002')->value('item'));

        // Changing only the letter case of its OWN code is not a duplicate.
        $this->edit('EWSPL-1001', ['code' => 'ewspl-1001', 'item' => 'Outer frame'])->assertOk()->assertJsonPath('data.code', 'ewspl-1001');
    }

    public function test_invalid_input_is_refused_and_nothing_changes(): void
    {
        $cases = [
            [['code' => '', 'item' => 'X'], 'code'],
            [['code' => '   ', 'item' => 'X'], 'code'],
            [['code' => 'A/B', 'item' => 'X'], 'code'],
            [['code' => 'A<B', 'item' => 'X'], 'code'],
            [['code' => "A'B", 'item' => 'X'], 'code'],
            [['code' => str_repeat('A', 101), 'item' => 'X'], 'code'],
            [['code' => 'EWSPL-1001', 'item' => ''], 'item'],
            [['code' => 'EWSPL-1001', 'item' => '<b>x</b>'], 'item'],
            [['code' => 'EWSPL-1001', 'item' => 'X', 'unit' => 'kg!'], 'unit'],
            [['code' => 'EWSPL-1001', 'item' => 'X', 'unit' => str_repeat('m', 21)], 'unit'],
        ];
        foreach ($cases as [$body, $field]) {
            $this->edit('EWSPL-1001', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $p = InventoryProfile::where('code', 'EWSPL-1001')->firstOrFail();
        $this->assertSame(['Outer frame', 'pcs'], [$p->item, $p->unit]);
    }

    public function test_unknown_code_and_non_admins_are_refused(): void
    {
        $this->edit('NOPE', ['code' => 'X', 'item' => 'Y'])->assertNotFound();
        $staff = $this->tokenFor('internal_team');
        $this->edit('EWSPL-1001', ['code' => 'X', 'item' => 'Y'], $staff)->assertForbidden();
        $partner = User::create(['name' => 'P', 'phone' => '9123466001', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->edit('EWSPL-1001', ['code' => 'X', 'item' => 'Y'], auth('api')->login($partner))->assertForbidden();
        $this->assertNotNull(InventoryProfile::where('code', 'EWSPL-1001')->first());
    }

    public function test_existing_actions_keep_working_after_a_rename(): void
    {
        $this->edit('EWSPL-1001', ['code' => 'EWSPL-1001-N', 'item' => 'Outer frame', 'unit' => 'pcs'])->assertOk();

        // Add Stock: by the new code; the old code is unknown.
        $this->withToken($this->admin)->postJson(self::API.'/inventory-stock/add', ['code' => 'EWSPL-1001-N', 'qty' => 2])->assertSuccessful();
        $this->withToken($this->admin)->postJson(self::API.'/inventory-stock/add', ['code' => 'EWSPL-1001', 'qty' => 2])->assertUnprocessable();
        $this->assertSame(47.0, $this->snapshot('EWSPL-1001-N')['current']);
        $this->assertSame(2, $this->snapshot('EWSPL-1001-N')['movements']);

        // Set cost / Edit usage (the dashboard's own save of the record) on the new code.
        $this->withToken($this->admin)->putJson(self::API.'/inventory/EWSPL-1001-N', [
            'code' => 'EWSPL-1001-N', 'item' => 'Outer frame', 'stock' => 57, 'baseStock' => 57, 'unitCost' => 120, 'usage' => ['PV-2610-001' => 12],
        ])->assertOk()->assertJsonPath('data.code', 'EWSPL-1001-N');
        $s = $this->snapshot('EWSPL-1001-N');
        $this->assertSame([120.0, 45.0], [$s['unit_cost'], $s['current']]);

        // Delete check follows the new code (it is used on a project, so it is kept).
        $this->withToken($this->admin)->getJson(self::API.'/inventory/EWSPL-1001-N/delete-check')->assertOk()->assertJsonPath('data.deletable', false);
        $this->withToken($this->admin)->getJson(self::API.'/inventory/EWSPL-1001/delete-check')->assertNotFound();

        // The inventory report shows the new code.
        $this->withToken($this->admin)->getJson('/api/admin/reports/inventory')->assertOk()->assertSee('EWSPL-1001-N');
    }
}
