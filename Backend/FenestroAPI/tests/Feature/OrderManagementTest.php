<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $role, int $isActive = 1): string
    {
        $user = User::create([
            'name'      => ucfirst($role),
            'email'     => $role.'@example.test',
            'phone'     => (string) random_int(6000000000, 9999999999),
            'password'  => 'Correct-Horse-9!',
            'role'      => $role,
            'is_active' => $isActive,
        ]);

        return auth('api')->login($user);
    }

    private function order(array $overrides = []): array
    {
        return $overrides + [
            'id'         => 'PV-2610-001',
            'customer'   => 'AKRAM',
            'project'    => 'PRESTIGE HOUSE, CPT',
            'phone'      => '8431474740',
            'confirmed'  => '2026-10-02',
            'sft'        => 1250,
            'rate'       => 1450,
            'tax'        => 18,
            'receipts'   => [['date' => '2026-10-03', 'amount' => 300000, 'notes' => 'ADVANCE']],
            'writtenOff' => 'No',
            'remarks'    => '',
            'statuses'   => [
                'comm' => 'NO', 'profile' => 'AVAILABLE', 'sizeqc' => 'YES', 'glass' => 'AWAITING DELIVERY',
                'prodHardware' => 'AVAILABLE', 'installHardware' => 'YET TO ORDER', 'docs' => 'SHARED',
                'exceptions' => 'YET TO SHARE',
            ],
            'completedAt' => null,
        ];
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/admin/order-management/data')->assertStatus(401);
        $this->postJson('/api/admin/order-management/orders', $this->order())->assertStatus(401);
    }

    public function test_non_admin_users_are_forbidden(): void
    {
        $partner = $this->tokenFor('referral_partner');

        $this->withToken($partner)->getJson('/api/admin/order-management/data')->assertStatus(403);
        $this->withToken($partner)->postJson('/api/admin/order-management/orders', $this->order())->assertStatus(403);
        $this->withToken($partner)->putJson('/api/admin/order-management/inventory/X', [])->assertStatus(403);
    }

    public function test_starts_empty_with_no_demo_data(): void
    {
        $this->withToken($this->tokenFor('admin'))
            ->getJson('/api/admin/order-management/data')
            ->assertOk()
            ->assertJsonPath('orders', [])
            ->assertJsonPath('inventory', [])
            ->assertJsonMissingPath('leads') // Leads & Inquiries removed
            ->assertJsonCount(8, 'config.statuses'); // the workbook's 8 CNS statuses (PRODUCTION STATUS removed)
    }

    public function test_order_round_trip_keeps_the_dashboard_shape(): void
    {
        $admin = $this->tokenFor('admin');

        $this->withToken($admin)->postJson('/api/admin/order-management/orders', $this->order())
            ->assertCreated()
            ->assertJsonPath('data.id', 'PV-2610-001')
            ->assertJsonPath('data.receipts.0.amount', 300000)
            ->assertJsonPath('data.statuses.glass', 'AWAITING DELIVERY');

        $updated = $this->order(['statuses' => ['comm' => 'PROJECT COMPLETED'] + $this->order()['statuses'], 'completedAt' => '2026-10-17T08:30:00.000Z', 'receipts' => []]);
        $this->withToken($admin)->putJson('/api/admin/order-management/orders/PV-2610-001', $updated)
            ->assertOk()
            ->assertJsonPath('data.statuses.comm', 'PROJECT COMPLETED')
            ->assertJsonPath('data.receipts', []);
        // Completion time is recorded by the server, not taken from the browser.
        $this->assertNotSame('2026-10-17T08:30:00.000Z', \App\Models\OrderManagement\Order::first()->completed_at?->toISOString());
        $this->assertNotNull(\App\Models\OrderManagement\Order::first()->completed_at);

        $this->withToken($admin)->getJson('/api/admin/order-management/data')
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.phone', '8431474740');
    }

    public function test_duplicate_order_code_gets_a_fresh_code(): void
    {
        $admin = $this->tokenFor('admin');
        $this->withToken($admin)->postJson('/api/admin/order-management/orders', $this->order())->assertCreated();

        $second = $this->withToken($admin)->postJson('/api/admin/order-management/orders', $this->order(['customer' => 'PRASANNA']))
            ->assertCreated()
            ->json('data.id');

        $this->assertNotSame('PV-2610-001', $second);
        $this->assertMatchesRegularExpression('/^PV-\d{4}-\d{3}$/', $second);
    }

    public function test_order_validation_rejects_unknown_status_and_html(): void
    {
        $admin = $this->tokenFor('admin');

        $bad = $this->order();
        $bad['statuses']['comm'] = 'MAYBE';
        $this->withToken($admin)->postJson('/api/admin/order-management/orders', $bad)->assertStatus(422);

        $this->withToken($admin)->postJson('/api/admin/order-management/orders', $this->order(['customer' => '<img src=x onerror=alert(1)>']))
            ->assertStatus(422);

        // Quotes would break out of an HTML attribute in the prototype.
        $this->withToken($admin)->postJson('/api/admin/order-management/orders', $this->order(['project' => 'X" onmouseover="alert(1)']))
            ->assertStatus(422)
            ->assertJsonPath('errors.project.0', 'Project name cannot contain < > or double quotes.');
    }

    public function test_inventory_create_update_and_usage(): void
    {
        $admin = $this->tokenFor('admin');

        $this->withToken($admin)->postJson('/api/admin/order-management/inventory', [
            'code' => 'EWSPL-LF-4008 - WG', 'item' => 'SLIDING SLEEK DOOR SASH WITH GASKET', 'stock' => 10, 'baseStock' => 10, 'unitCost' => 250, 'usage' => new \stdClass,
        ])->assertCreated()->assertJsonPath('data.usage', []);

        $this->withToken($admin)->postJson('/api/admin/order-management/inventory', [
            'code' => 'EWSPL-LF-4008 - WG', 'item' => 'DUP', 'stock' => 0, 'usage' => [],
        ])->assertStatus(422);

        $this->withToken($admin)->postJson('/api/admin/order-management/inventory', [
            'code' => "BAD'/CODE", 'item' => 'X', 'stock' => 0, 'usage' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->withToken($admin)->postJson('/api/admin/order-management/inventory', [
            'code' => 'EWSPL (R) #2', 'item' => 'ODD BUT VALID', 'stock' => 0, 'usage' => [],
        ])->assertCreated();

        $this->withToken($admin)->putJson('/api/admin/order-management/inventory/'.rawurlencode('EWSPL-LF-4008 - WG'), [
            'code' => 'EWSPL-LF-4008 - WG', 'item' => 'SLIDING SLEEK DOOR SASH WITH GASKET', 'stock' => 10, 'baseStock' => 10, 'unitCost' => 250,
            'usage' => ['PV-2610-001' => 4],
        ])->assertOk()->assertJsonPath('data.usage.PV-2610-001', 4);

        $this->withToken($admin)->getJson('/api/admin/order-management/data')
            ->assertJsonPath('inventory.0.baseStock', 10)
            ->assertJsonPath('inventory.0.unitCost', 250);
    }

    public function test_leads_and_inquiries_endpoints_are_gone(): void
    {
        $admin = $this->tokenFor('admin');
        $this->withToken($admin)->postJson('/api/admin/order-management/inquiries', ['name' => 'X'])->assertNotFound();
        $this->withToken($admin)->putJson('/api/admin/order-management/inquiries/LD-12345678', ['name' => 'X'])->assertNotFound();
    }
}
