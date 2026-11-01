<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Measurement;
use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\InventoryUsage;
use App\Models\OrderManagement\StockMovement;
use App\Models\User;
use App\Support\AdminReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->tokenFor('admin');
    }

    private function tokenFor(string $role, ?int $isActive = 1): string
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.random_int(1, 99999).'@example.test',
            'phone' => (string) random_int(6000000000, 9999999999),
            'password' => 'Correct-Horse-9!',
            'role' => $role,
            'is_active' => $isActive,
        ]);

        return auth('api')->login($user);
    }

    private function saveOrder(array $overrides = []): void
    {
        $this->withToken($this->admin)->postJson('/api/admin/order-management/orders', $overrides + [
            'id' => 'PV-2610-001',
            'customer' => 'Malika Enterprises',
            'project' => 'Prestige Villa',
            'phone' => '8431474740',
            'confirmed' => '2026-10-02',
            'sft' => 100,
            'rate' => 1000,
            'tax' => 18,
            'receipts' => [['date' => '2026-10-03', 'amount' => 50000, 'notes' => 'ADVANCE']],
            'writtenOff' => 'No',
            'remarks' => '',
            'statuses' => ['comm' => 'QSHARE'],
            'completedAt' => null,
        ])->assertSuccessful();
    }

    private function report(string $type, array $query = []): TestResponse
    {
        return $this->withToken($this->admin)->getJson('/api/admin/reports/'.$type.($query ? '?'.http_build_query($query) : ''));
    }

    private static function section(array $report, string $title): array
    {
        return collect($report['sections'])->firstWhere('title', $title);
    }

    /** Three projects: active with money due, completed with money due, completed and fully paid (archived). */
    private function threeProjects(): void
    {
        $this->saveOrder();
        $this->saveOrder(['id' => 'PV-2610-002', 'customer' => 'Ravi Kumar', 'project' => 'Lake House', 'phone' => '9000000001',
            'confirmed' => '2026-09-10', 'statuses' => ['comm' => 'PROJECT COMPLETED'], 'receipts' => [['date' => '2026-09-11', 'amount' => 10000, 'notes' => '']]]);
        $this->saveOrder(['id' => 'PV-2610-003', 'customer' => 'Asha', 'project' => 'Hill View', 'phone' => '9000000002',
            'confirmed' => '2026-08-01', 'sft' => 10, 'rate' => 100, 'statuses' => ['comm' => 'PROJECT COMPLETED'],
            'receipts' => [['date' => '2026-08-02', 'amount' => 1180, 'notes' => 'FULL']]]);
    }

    public function test_reports_need_an_admin(): void
    {
        $partner = $this->tokenFor('referral_partner');
        $internal = $this->tokenFor('internal_team');
        // Each request must stand on its own token, not on a user the guard remembers from login().
        $fresh = function () {
            $this->app['auth']->forgetGuards();
            // jwt-auth keeps the token parsed by login() in its own singletons.
            $this->app->forgetInstance('tymon.jwt');
            $this->app->forgetInstance('tymon.jwt.auth');

            return $this;
        };

        $fresh()->getJson('/api/admin/reports/orders')->assertStatus(401);
        $fresh()->withToken($partner)->getJson('/api/admin/reports/orders')->assertStatus(403);
        $fresh()->withToken($internal)->getJson('/api/admin/reports/leads')->assertStatus(403);
        $fresh()->withToken($this->admin)->getJson('/api/admin/reports/leads')->assertOk();
    }

    public function test_unknown_types_and_invalid_filters_are_rejected(): void
    {
        $this->report('users')->assertNotFound();
        $this->report('orders', ['filter' => 'everything'])->assertStatus(422)->assertJsonValidationErrors('filter');
        $this->report('cns', ['view' => 'x'])->assertStatus(422)->assertJsonValidationErrors('view');
        $this->report('orders', ['q' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_every_report_works_on_an_empty_database(): void
    {
        foreach (AdminReports::TYPES as $type) {
            $r = $this->report($type)->assertOk()->json();
            $this->assertNotEmpty($r['title'], $type);
            $this->assertNotEmpty($r['generated_at'], $type);
            foreach ($r['sections'] as $s) {
                if ($s['rows'] === []) {
                    $this->assertNull($s['totals'], "$type / {$s['title']}");
                    $this->assertNotSame('', $s['empty']);
                }
            }
        }
    }

    public function test_orders_report_uses_server_maths_and_the_page_filters(): void
    {
        $this->threeProjects();

        $r = $this->report('orders')->assertOk()->json();
        $orders = self::section($r, 'Orders');
        $this->assertCount(3, $orders['rows']);
        // 100 × 1000 × 1.18 = 118,000 invoice; 50,000 received.
        $first = collect($orders['rows'])->firstWhere('id', 'PV-2610-001');
        $this->assertSame([118000, 50000, 68000], [$first['invoice'], $first['received'], $first['outstanding']]);
        $this->assertSame('MALIKA ENTERPRISES', $first['customer']); // upper case like the website; the PDF proper-cases it
        $this->assertTrue(collect($orders['columns'])->firstWhere('key', 'customer')['proper']);
        $this->assertArrayNotHasKey('proper', collect($orders['columns'])->firstWhere('key', 'id'));
        $this->assertEquals(118000 + 118000 + 1180, $orders['totals']['invoice']);
        $this->assertEquals(68000 + 108000, $orders['totals']['outstanding']);
        $this->assertCount(3, self::section($r, 'Payment history')['rows']);

        $this->assertSame(['PV-2610-001'], array_column(self::section($this->report('orders', ['filter' => 'active'])->json(), 'Orders')['rows'], 'id'));
        $this->assertSame(['PV-2610-002', 'PV-2610-001'], array_column(self::section($this->report('orders', ['filter' => 'outstanding'])->json(), 'Orders')['rows'], 'id'));
        $search = $this->report('orders', ['q' => 'lake'])->json();
        $this->assertSame(['PV-2610-002'], array_column(self::section($search, 'Orders')['rows'], 'id'));
        $this->assertSame([['label' => 'Search', 'value' => 'lake']], $search['filters']);
    }

    public function test_receivables_and_archive_reports_hold_the_same_projects_as_their_pages(): void
    {
        $this->threeProjects();

        $page = $this->withToken($this->admin)->getJson('/api/admin/order-management/receivables')->json();
        $r = $this->report('receivables')->json();
        $rows = self::section($r, 'Receivables')['rows'];
        $this->assertEqualsCanonicalizing(array_column($page['data'], 'id'), array_column($rows, 'id'));
        $this->assertEquals($page['total'], self::section($r, 'Receivables')['totals']['outstanding']);
        $this->assertSame(['PV-2610-002'], array_column(self::section($this->report('receivables', ['filter' => 'completed'])->json(), 'Receivables')['rows'], 'id'));

        $archivePage = $this->withToken($this->admin)->getJson('/api/admin/order-management/archive')->json('data');
        $archive = self::section($this->report('archive')->json(), 'Archived projects');
        $this->assertSame(array_column(array_column($archivePage, 'order'), 'id'), array_column($archive['rows'], 'id'));
        $this->assertSame(['PV-2610-003'], array_column($archive['rows'], 'id'));
        $this->assertSame('Completed – fully paid', $archive['rows'][0]['outcome']);
        $this->assertEquals(0, $archive['rows'][0]['outstanding']);
    }

    public function test_inventory_report_matches_the_inventory_page_maths(): void
    {
        $this->saveOrder();
        $short = InventoryProfile::create(['code' => 'A1', 'item' => 'Frame', 'unit' => 'NOS', 'base_stock' => 2, 'stock' => 2, 'unit_cost' => 100]);
        InventoryUsage::create(['profile_id' => $short->id, 'usage_key' => 'PV-2610-001', 'qty' => 5]);
        InventoryProfile::create(['code' => 'B2', 'item' => 'Sash', 'unit' => 'NOS', 'base_stock' => 10, 'stock' => 10, 'unit_cost' => 50]);
        StockMovement::create(['profile_id' => $short->id, 'qty' => 2, 'unit' => 'NOS', 'movement_date' => '2026-10-01', 'reference' => 'GRN-1', 'source' => 'add', 'balance_after' => 2]);

        $r = $this->report('inventory')->assertOk()->json();
        $rows = collect(self::section($r, 'Stock by profile')['rows'])->keyBy('code');
        $this->assertEquals(-3, $rows['A1']['current']);
        $this->assertEquals(3, $rows['A1']['shortage_qty']);
        $this->assertSame('SHORTAGE', $rows['A1']['short']);
        $this->assertEquals(round(100 * 5.8 * 3 * 1.18, 2), $rows['A1']['shortage_value']); // 2,053.20
        $this->assertEquals(round(50 * 5.8 * 10 * 1.18, 2), $rows['B2']['stock_value']);    // 3,422
        $this->assertCount(1, self::section($r, 'Stock movements')['rows']);
        $this->assertSame('PV-2610-001', self::section($r, 'Project usage')['rows'][0]['project_id']);

        $this->assertSame(['A1'], array_column(self::section($this->report('inventory', ['filter' => 'shortage'])->json(), 'Stock by profile')['rows'], 'code'));

        // CNS: the project using the short profile shows the alert.
        $cns = self::section($this->report('cns')->json(), 'Project status board');
        $this->assertSame('A1 (-3)', $cns['rows'][0]['alerts']);
    }

    public function test_cns_report_follows_the_view_filter(): void
    {
        $this->threeProjects();
        $ids = fn (string $view) => array_column(self::section($this->report('cns', ['view' => $view])->json(), 'Project status board')['rows'], 'id');

        $this->assertSame(['PV-2610-001'], $ids('active'));
        $this->assertSame(['PV-2610-002'], $ids('recovery'));
        $this->assertSame(['PV-2610-003'], $ids('archived'));
        $this->assertSame(['PV-2610-001', 'PV-2610-002', 'PV-2610-003'], $ids('all')); // active first, then newest confirmed
    }

    public function test_command_center_and_analytics_totals_add_up(): void
    {
        $this->threeProjects();
        $value = fn (array $r, string $label) => collect($r['summary'])->firstWhere('label', $label)['value'];

        $cc = $this->report('command-center')->json();
        $this->assertSame(3, $value($cc, 'Total projects'));
        $this->assertSame(1, $value($cc, 'Active projects'));
        $this->assertSame(1, $value($cc, 'Archived projects'));
        $this->assertEquals(176000, $value($cc, 'Outstanding balance'));

        $an = $this->report('analytics')->json();
        $this->assertEquals(237180, $value($an, 'Invoice value'));
        $this->assertEquals(61180, $value($an, 'Total received'));
        $this->assertEquals(3, self::section($an, 'Orders by confirmed month')['totals']['orders']);
    }

    public function test_leads_report_includes_every_lead_not_just_one_page(): void
    {
        $partner = User::create(['name' => 'Partner One', 'phone' => '9111111111', 'password' => 'Correct-Horse-9!', 'role' => 'referral_partner', 'is_active' => 1, 'company' => 'Acme']);
        foreach (range(1, 18) as $i) {
            Lead::create(['referral_partner_id' => $partner->id, 'client_name' => "Client $i", 'client_phone' => '9000000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'type' => $i % 2 ? 'B2B' : 'B2C']);
        }
        $lead = Lead::first();
        Measurement::create(['lead_id' => $lead->id, 'customer_name' => 'X', 'customer_phone' => '9000000000', 'type' => 'B2B', 'measured_by' => 'Y', 'date' => '2026-10-01', 'status' => 'submitted']);

        $r = $this->report('leads')->assertOk()->json();
        $this->assertCount(18, self::section($r, 'Leads')['rows']); // the /leads list pages at 15
        $this->assertCount(1, self::section($this->report('leads', ['status' => 'completed'])->json(), 'Leads')['rows']);

        $partners = self::section($this->report('partners')->json(), 'Partners');
        $this->assertSame(18, $partners['rows'][0]['leads']);
        $this->assertArrayNotHasKey('password', $partners['rows'][0]);

        $business = $this->report('business')->json();
        $this->assertSame(18, collect($business['summary'])->firstWhere('label', 'Total leads')['value']);
    }

    public function test_reports_change_nothing(): void
    {
        $this->threeProjects();
        $before = $this->withToken($this->admin)->getJson('/api/admin/order-management/data')->json('orders');
        foreach (AdminReports::TYPES as $type) {
            $this->report($type)->assertOk();
        }
        $this->assertSame($before, $this->withToken($this->admin)->getJson('/api/admin/order-management/data')->json('orders'));
    }
}
