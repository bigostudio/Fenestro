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
        $this->assertStringContainsString('greater than zero', $rows[4]['errors'][0]);
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
