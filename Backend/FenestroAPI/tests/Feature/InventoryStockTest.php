<?php

namespace Tests\Feature;

use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::create(['name' => 'Admin', 'email' => 'a@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->token = auth('api')->login($admin);
        $p = InventoryProfile::create(['code' => 'EWSPL-LF-1001', 'item' => '2 TRACK OUTER FRAME', 'base_stock' => 2, 'stock' => 2, 'unit_cost' => 250]);
        $p->usages()->create(['usage_key' => 'PV-2610-001', 'qty' => 5]); // current stock -3 → shortage
    }

    private function stockPost(string $path, array $body)
    {
        return $this->withToken($this->token)->postJson('/api/admin/order-management/inventory-stock/'.$path, $body);
    }

    public function test_add_stock_updates_balance_and_records_history(): void
    {
        $this->stockPost('add', ['code' => 'ewspl-lf-1001', 'qty' => 10, 'unit' => 'PCS', 'date' => '2026-10-20', 'reference' => 'INV-778 / ACME PROFILES', 'remarks' => 'Truck 2'])
            ->assertCreated()
            ->assertJsonPath('data.baseStock', 12)
            ->assertJsonPath('data.unit', 'PCS')
            ->assertJsonPath('data.usage.PV-2610-001', 5)    // project usage untouched
            ->assertJsonPath('movement.balance_after', 12);

        $p = InventoryProfile::first();
        $this->assertSame(12.0, $p->base_stock);
        $m = StockMovement::first();
        $this->assertSame(10.0, $m->qty);
        $this->assertSame('INV-778 / ACME PROFILES', $m->reference);
        $this->assertSame('add', $m->source);
        $this->assertNotNull($m->created_by);
    }

    public function test_add_stock_rejects_invalid_input_and_changes_nothing(): void
    {
        foreach ([
            ['qty' => 0], ['qty' => -5], ['qty' => 'abc'], ['qty' => 10000000],
            ['qty' => 5, 'date' => '2099-01-01'], ['qty' => 5, 'unit' => '<b>'], ['qty' => 5, 'code' => 'NOPE-1'],
        ] as $bad) {
            $this->stockPost('add', $bad + ['code' => 'EWSPL-LF-1001'])->assertStatus(422);
        }
        $this->assertSame(2.0, InventoryProfile::first()->base_stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_bulk_review_marks_create_vs_add_and_reports_row_errors(): void
    {
        $res = $this->stockPost('bulk', ['rows' => [
            ['code' => 'EWSPL-LF-1001', 'qty' => 4],                                       // add to existing
            ['code' => 'NEW-PROFILE-9', 'item' => 'NEW SASH', 'qty' => 6, 'unit' => 'PCS'], // create new
            ['code' => 'NEW-PROFILE-10', 'qty' => 3],                                       // new without item → error
            ['code' => 'ewspl-lf-1001', 'qty' => 1],                                        // duplicate in batch → error
            ['code' => 'EWSPL-LF-1001X', 'item' => 'X', 'qty' => -1],                      // negative → error
        ]])->assertOk();

        $rows = $res->json('rows');
        $this->assertSame(['add', 'create', 'create', 'add', 'create'], array_column($rows, 'action'));
        $this->assertEquals(-3, $rows[0]['current_stock']);
        $this->assertEquals(1, $rows[0]['stock_after']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame([], $rows[1]['errors']);
        $this->assertStringContainsString('item description', $rows[2]['errors'][0]);
        $this->assertStringContainsString('row 1', $rows[3]['errors'][0]);
        $this->assertStringContainsString('cannot be negative', $rows[4]['errors'][0]);
        $this->assertSame(2, $res->json('valid'));
        $this->assertSame(3, $res->json('invalid'));
        $this->assertSame(1, InventoryProfile::count()); // review saves nothing
    }

    public function test_bulk_commit_refuses_invalid_rows_then_saves_all_valid_rows(): void
    {
        $this->stockPost('bulk', ['commit' => true, 'rows' => [['code' => 'EWSPL-LF-1001', 'qty' => 4], ['code' => 'NEW-1', 'qty' => 2]]])
            ->assertStatus(422)->assertJsonPath('committed', false);
        $this->assertSame(1, InventoryProfile::count());
        $this->assertSame(2.0, InventoryProfile::first()->base_stock);

        $res = $this->stockPost('bulk', ['commit' => true, 'rows' => [
            ['code' => 'EWSPL-LF-1001', 'qty' => 4, 'reference' => 'BULK-1'],
            ['code' => 'NEW-1', 'item' => 'NEW FRAME', 'qty' => 2, 'unit' => 'PCS', 'unitCost' => 300],
        ]])->assertCreated()->assertJsonPath('committed', true)->assertJsonPath('created', 1)->assertJsonPath('added', 1);

        $this->assertSame(6.0, InventoryProfile::where('code', 'EWSPL-LF-1001')->first()->base_stock);
        $new = InventoryProfile::where('code', 'NEW-1')->first();
        $this->assertSame(2.0, $new->base_stock);
        $this->assertSame(300.0, $new->unit_cost);
        $this->assertSame(2, StockMovement::count());
        $this->assertCount(2, $res->json('profiles'));
    }

    public function test_a_new_profile_code_can_start_with_quantity_zero(): void
    {
        // Bulk Data: a new code with opening quantity 0 is created at 0 (no stock movement, nothing was received).
        $this->stockPost('bulk', ['commit' => true, 'rows' => [['code' => 'ZERO-1', 'item' => 'NEW MULLION', 'qty' => 0, 'unitCost' => 120]]])
            ->assertCreated()->assertJsonPath('created', 1)
            ->assertJsonPath('profiles.0.code', 'ZERO-1')
            ->assertJsonPath('profiles.0.baseStock', 0)
            ->assertJsonPath('profiles.0.stock', 0);
        $p = InventoryProfile::where('code', 'ZERO-1')->first();
        $this->assertSame(0.0, $p->base_stock);
        $this->assertSame(0.0, $p->stock);
        $this->assertSame(0, StockMovement::count());

        // Still returned as 0 (not empty) when the page is reloaded.
        $loaded = collect($this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertOk()->json('inventory'))->firstWhere('code', 'ZERO-1');
        $this->assertSame(0, $loaded['baseStock']);
        $this->assertSame(0, $loaded['stock']);

        // "+ Add profile" (the Inventory page's own form) also saves an opening stock of 0.
        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory', ['code' => 'ZERO-2', 'item' => 'NEW SASH', 'stock' => 0, 'baseStock' => 0, 'unitCost' => 0, 'usage' => []])
            ->assertCreated()->assertJsonPath('data.stock', 0)->assertJsonPath('data.baseStock', 0);
    }

    public function test_new_profile_codes_still_reject_negative_or_non_numeric_quantities_and_adding_zero_stock(): void
    {
        $rows = $this->stockPost('bulk', ['rows' => [
            ['code' => 'NEG-1', 'item' => 'X', 'qty' => -1],
            ['code' => 'TXT-1', 'item' => 'X', 'qty' => 'abc'],
            ['code' => 'DEC-1', 'item' => 'X', 'qty' => 1.5],
            ['code' => 'EWSPL-LF-1001', 'qty' => 0], // adding 0 to an existing code is still refused
        ]])->assertOk()->json('rows');
        $this->assertStringContainsString('cannot be negative', $rows[0]['errors'][0]);
        $this->assertStringContainsString('whole number', $rows[1]['errors'][0]);
        $this->assertStringContainsString('whole number', $rows[2]['errors'][0]);
        $this->assertStringContainsString('greater than zero', $rows[3]['errors'][0]);

        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory', ['code' => 'TXT-2', 'item' => 'X', 'stock' => 'abc', 'usage' => []])->assertStatus(422);
        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory', ['code' => 'DEC-2', 'item' => 'X', 'stock' => 1.5, 'usage' => []])->assertStatus(422);
        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory', ['code' => 'NEG-2', 'item' => 'X', 'stock' => -1, 'usage' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['stock' => '0 or more']);
        $this->assertSame(1, InventoryProfile::count());
    }

    // ── Delete Profile Code ───────────────────────────────────────────

    private function deleteProfile(string $code, ?string $confirm)
    {
        return $this->withToken($this->token)->deleteJson('/api/admin/order-management/inventory/'.rawurlencode($code), $confirm === null ? [] : ['confirm' => $confirm]);
    }

    public function test_an_unused_profile_code_is_deleted_only_after_typing_its_code(): void
    {
        InventoryProfile::create(['code' => 'SPARE-1', 'item' => 'SPARE SASH', 'base_stock' => 0, 'stock' => 0, 'unit_cost' => 50]);
        $this->withToken($this->token)->getJson('/api/admin/order-management/inventory/SPARE-1/delete-check')->assertOk()
            ->assertJsonPath('data.deletable', true)->assertJsonPath('data.reasons', [])->assertJsonPath('data.warnings', [])->assertJsonPath('data.item', 'SPARE SASH');

        $this->deleteProfile('SPARE-1', null)->assertStatus(422);
        $this->deleteProfile('SPARE-1', 'SPARE-2')->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertTrue(InventoryProfile::where('code', 'SPARE-1')->exists());

        $this->deleteProfile('SPARE-1', 'spare-1')->assertOk()->assertJsonPath('deleted', 'SPARE-1');
        $this->assertFalse(InventoryProfile::where('code', 'SPARE-1')->exists());
        $this->assertTrue(InventoryProfile::where('code', 'EWSPL-LF-1001')->exists()); // nothing else touched
        $this->deleteProfile('SPARE-1', 'SPARE-1')->assertNotFound();
    }

    public function test_a_profile_code_with_project_usage_or_stock_history_cannot_be_deleted(): void
    {
        // EWSPL-LF-1001 is used on PV-2610-001 (setUp).
        $this->withToken($this->token)->getJson('/api/admin/order-management/inventory/EWSPL-LF-1001/delete-check')->assertOk()
            ->assertJsonPath('data.deletable', false)->assertJsonPath('data.current_stock', -3);
        $this->deleteProfile('EWSPL-LF-1001', 'EWSPL-LF-1001')->assertStatus(409);
        $p = InventoryProfile::with('usages')->where('code', 'EWSPL-LF-1001')->first();
        $this->assertNotNull($p);
        $this->assertEquals(5, $p->usages->first()->qty);

        // Stock history (Add Stock) also keeps a code.
        InventoryProfile::create(['code' => 'HIST-1', 'item' => 'WITH HISTORY', 'base_stock' => 0, 'stock' => 0, 'unit_cost' => 0]);
        $this->stockPost('add', ['code' => 'HIST-1', 'qty' => 3])->assertCreated();
        $check = $this->withToken($this->token)->getJson('/api/admin/order-management/inventory/HIST-1/delete-check')->assertOk();
        $check->assertJsonPath('data.deletable', false);
        $this->assertStringContainsString('stock transaction', $check->json('data.reasons.0'));
        $this->deleteProfile('HIST-1', 'HIST-1')->assertStatus(409);
        $this->assertSame(1, StockMovement::count());

        // Stock without history: deletable, with a warning about the balance.
        InventoryProfile::create(['code' => 'OPEN-4', 'item' => 'OPENING STOCK ONLY', 'base_stock' => 4, 'stock' => 4, 'unit_cost' => 0]);
        $this->withToken($this->token)->getJson('/api/admin/order-management/inventory/OPEN-4/delete-check')->assertOk()
            ->assertJsonPath('data.deletable', true)->assertJsonPath('data.warnings.0', 'Its current stock of 4 will no longer be listed.');
    }

    public function test_profile_code_deletion_is_admin_only(): void
    {
        InventoryProfile::create(['code' => 'SPARE-2', 'item' => 'X', 'base_stock' => 0, 'stock' => 0, 'unit_cost' => 0]);
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->deleteJson('/api/admin/order-management/inventory/SPARE-2', ['confirm' => 'SPARE-2'])->assertStatus(401);
        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $pt = auth('api')->login($partner);
        $this->withToken($pt)->getJson('/api/admin/order-management/inventory/SPARE-2/delete-check')->assertStatus(403);
        $this->withToken($pt)->deleteJson('/api/admin/order-management/inventory/SPARE-2', ['confirm' => 'SPARE-2'])->assertStatus(403);
        $this->assertTrue(InventoryProfile::where('code', 'SPARE-2')->exists());
    }

    public function test_stock_endpoints_are_admin_only(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->postJson('/api/admin/order-management/inventory-stock/add', ['code' => 'EWSPL-LF-1001', 'qty' => 5])->assertStatus(401);
        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $pt = auth('api')->login($partner);
        $this->withToken($pt)->postJson('/api/admin/order-management/inventory-stock/add', ['code' => 'EWSPL-LF-1001', 'qty' => 5])->assertStatus(403);
        $this->withToken($pt)->postJson('/api/admin/order-management/inventory-stock/bulk', ['rows' => [['code' => 'X', 'qty' => 1]]])->assertStatus(403);
        $this->assertSame(2.0, InventoryProfile::first()->base_stock);
    }
}
