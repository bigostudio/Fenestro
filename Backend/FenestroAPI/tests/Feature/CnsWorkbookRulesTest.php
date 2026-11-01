<?php

namespace Tests\Feature;

use App\Models\OrderManagement\Order;
use App\Models\User;
use App\Support\OrderManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** Rules from the CNS workbook (MyFile.xlsx: CNS DASHBOARD V1.0/V2.0, ACCOUNTS). */
class CnsWorkbookRulesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private const TZ = 'Asia/Kolkata';

    protected function setUp(): void
    {
        parent::setUp();
        config(['cns.whatsapp.test_recipient' => null]);
        $admin = User::create(['name' => 'Admin', 'email' => 'a@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->token = auth('api')->login($admin);
    }

    private function eight(array $overrides = []): array
    {
        return array_merge([
            'comm' => 'YET TO SHARE', 'profile' => 'YET TO ORDER', 'sizeqc' => 'NO', 'glass' => 'YET TO ORDER',
            'prodHardware' => 'YET TO ORDER', 'installHardware' => 'YET TO ORDER', 'docs' => 'YET TO SHARE', 'exceptions' => 'YET TO SHARE',
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'PV-2610-001', 'customer' => 'ANAND RAO', 'project' => 'LAKE HOUSE', 'phone' => '9876543210',
            'confirmed' => '2026-10-02', 'sft' => 100, 'rate' => 1000, 'tax' => 18,
            'receipts' => [['date' => '2026-10-02', 'amount' => 50000, 'notes' => 'ADV']],
            'writtenOff' => 'No', 'remarks' => '', 'statuses' => $this->eight(), 'completedAt' => null,
        ], $overrides);
    }

    private function createOrder(array $o = [])
    {
        return $this->withToken($this->token)->postJson('/api/admin/order-management/orders', $this->payload($o));
    }

    private function update(string $code, array $o = [])
    {
        return $this->withToken($this->token)->putJson("/api/admin/order-management/orders/$code", $this->payload(['id' => $code] + $o));
    }

    // ── 1–2. Auto-created portfolio with workbook defaults ──────────────

    public function test_new_order_appears_in_cns_with_workbook_defaults(): void
    {
        // Even a payload with no statuses gets the 8 defaults.
        $this->createOrder(['statuses' => []])->assertCreated()
            ->assertJsonPath('data.statuses', $this->eight())
            ->assertJsonPath('data.completedAt', null);

        $orders = $this->withToken($this->token)->getJson('/api/admin/order-management/data')->json('orders');
        $this->assertCount(1, $orders); // one order = one portfolio, never duplicated
    }

    public function test_editing_an_order_updates_its_portfolio_without_duplicating(): void
    {
        $this->createOrder();
        $this->update('PV-2610-001', ['project' => 'LAKE HOUSE ANNEX', 'statuses' => $this->eight(['profile' => 'AVAILABLE'])])->assertOk();
        $orders = $this->withToken($this->token)->getJson('/api/admin/order-management/data')->json('orders');
        $this->assertCount(1, $orders);
        $this->assertSame('LAKE HOUSE ANNEX', $orders[0]['project']);
        $this->assertSame('AVAILABLE', $orders[0]['statuses']['profile']);
    }

    // ── 5–6. Completion timestamp (bottom for 8 days, then archived) ───

    public function test_completion_time_is_set_by_the_server_kept_and_cleared_on_reopen(): void
    {
        $this->createOrder();
        // A browser-supplied completedAt is ignored; the server records "now".
        $first = $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED']), 'completedAt' => '2020-01-01T00:00:00Z'])
            ->assertOk()->json('data.completedAt');
        $this->assertNotNull($first);
        $this->assertTrue(abs(Carbon::parse($first)->diffInSeconds(now())) < 60, 'completion time should be now, not the browser value');

        // Later edits while still completed keep the original completion time, even if
        // the browser sends something else (so the 8-day window can't be stretched).
        Order::where('code', 'PV-2610-001')->update(['completed_at' => now()->subDays(3)]);
        $kept = Order::where('code', 'PV-2610-001')->first()->completed_at;
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED', 'glass' => 'AVAILABLE']), 'completedAt' => null])->assertOk();
        $this->assertTrue(Order::where('code', 'PV-2610-001')->first()->completed_at->equalTo($kept));

        // Reopening clears it (back on the active dashboard); completing again restarts the 8 days.
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'YES'])])->assertOk()->assertJsonPath('data.completedAt', null);
        $again = $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED'])])->assertOk()->json('data.completedAt');
        $this->assertTrue(Carbon::parse($again)->gt($kept));

        // Records are never deleted by completion/archiving.
        $this->assertSame(1, Order::count());
        $this->assertCount(1, Order::first()->receipts);
    }

    public function test_project_completed_archives_once_and_restore_brings_it_back(): void
    {
        $this->createOrder();
        // Every other status (HAND OVER included) leaves the project active.
        foreach (['QSHARE', 'ADV RCVD', 'PART PAY RCVD', 'FULL PAY RCVD', 'MATRL ARIVD', 'PROD STRTD', 'INST STRTD', 'HAND OVER', 'FEEDBACK REQ', 'INSTAL DELAY', 'YET TO SHARE', 'YET TO START'] as $v) {
            $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => $v])])->assertOk()->assertJsonPath('data.completedAt', null);
            $this->assertFalse(Order::first()->isCompleted(), $v.' must not archive');
        }

        // PROJECT COMPLETED archives it; selecting it again keeps the same record and time.
        $at = $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED'])])->assertOk()->json('data.completedAt');
        $this->assertNotNull($at);
        $this->assertTrue(Order::first()->isCompleted());
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED', 'glass' => 'AVAILABLE'])])->assertOk()->assertJsonPath('data.completedAt', $at);
        $this->assertSame(1, Order::count());
        $this->assertCount(1, Order::first()->receipts); // payment records untouched

        // Restore (status back to YET TO SHARE) puts it back on the active dashboard.
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'YET TO SHARE'])])->assertOk()->assertJsonPath('data.completedAt', null);
        $this->assertFalse(Order::first()->isCompleted());
        $this->assertSame(1, Order::count());
    }

    public function test_production_status_column_is_removed_but_saved_values_are_kept(): void
    {
        $this->createOrder();
        $order = Order::first();
        $order->statuses = $order->statuses + ['production' => 'PRODUCTION STARTED'];
        $order->save();
        // The dashboard no longer sends it; saving other statuses must not wipe it.
        $this->update('PV-2610-001', ['statuses' => $this->eight(['profile' => 'AVAILABLE'])])->assertOk();
        $this->assertSame('PRODUCTION STARTED', Order::first()->statuses['production']);
        $this->assertSame('AVAILABLE', Order::first()->statuses['profile']);
    }

    // ── 4. Four-day communication reset (scheduled backend job) ─────────

    private function orderConfirmed(string $code, string $confirmed, string $comm = 'YES'): void
    {
        $this->createOrder(['id' => $code, 'confirmed' => $confirmed, 'statuses' => $this->eight(['comm' => $comm])])->assertCreated();
    }

    private function comm(string $code): string
    {
        return Order::where('code', $code)->first()->statuses['comm'];
    }

    /** Move the test clock and sign in again (a JWT is only valid around its issue time). */
    private function at(?string $time): void
    {
        Carbon::setTestNow($time ? Carbon::parse($time, self::TZ)->utc() : null); // app clock is UTC
        $this->resetAuth();
        $this->token = auth('api')->login(User::where('email', 'a@example.test')->first());
    }

    private function data(string $code): array
    {
        $orders = $this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertOk()->json('orders');

        return collect($orders)->firstWhere('id', $code);
    }

    public function test_new_projects_start_on_yet_to_share_which_is_red(): void
    {
        $this->createOrder(['statuses' => []])->assertCreated()
            ->assertJsonPath('data.statuses.comm', 'YET TO SHARE')
            ->assertJsonPath('data.commFresh', false)
            ->assertJsonPath('data.commFreshUntil', null);
    }

    public function test_selected_status_is_green_for_exactly_four_days_then_red_without_changing_value(): void
    {
        $this->at('2026-10-21 10:00:00');
        $this->createOrder();
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'ADV RCVD'])])->assertOk()
            ->assertJsonPath('data.commFresh', true);
        $until = Carbon::parse($this->data('PV-2610-001')['commFreshUntil']);
        $this->assertTrue($until->equalTo(Carbon::parse('2026-10-25 10:00:00', self::TZ)), 'expires 4 days after selection: '.$until);

        // Refreshing / editing other fields never restarts the period, and the browser can't set it.
        $this->at('2026-10-23 09:00:00');
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'ADV RCVD', 'glass' => 'AVAILABLE']), 'lastCommChange' => '2026-10-23T03:30:00Z'])->assertOk();
        $this->assertSame($until->toIso8601String(), Carbon::parse($this->data('PV-2610-001')['commFreshUntil'])->toIso8601String());
        $this->assertTrue($this->data('PV-2610-001')['commFresh']);

        // One minute before 4 days: still green. At 4 days: red, value unchanged.
        $this->at('2026-10-25 09:59:00');
        $this->assertTrue($this->data('PV-2610-001')['commFresh']);
        $this->at('2026-10-25 10:00:00');
        $d = $this->data('PV-2610-001');
        $this->assertFalse($d['commFresh']);
        $this->assertSame('ADV RCVD', $d['statuses']['comm']);

        // Selecting a new status restarts the 4 days.
        $this->at('2026-10-26 12:00:00');
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PART PAY RCVD'])])->assertOk()->assertJsonPath('data.commFresh', true);
        $this->assertTrue(Carbon::parse($this->data('PV-2610-001')['commFreshUntil'])->equalTo(Carbon::parse('2026-10-30 12:00:00', self::TZ)));
        $this->at(null);
    }

    public function test_colour_rule_applies_to_every_selectable_status(): void
    {
        $this->createOrder();
        foreach (['QSHARE', 'ADV RCVD', 'PART PAY RCVD', 'FULL PAY RCVD', 'MATRL ARIVD', 'PROD STRTD', 'INST STRTD', 'HAND OVER', 'FEEDBACK REQ', 'INSTAL DELAY', 'PROJECT COMPLETED'] as $v) {
            $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => $v])])->assertOk()->assertJsonPath('data.commFresh', true);
            $this->at(now()->addHours(97)->setTimezone(self::TZ)->toDateTimeString());
            $this->assertFalse($this->data('PV-2610-001')['commFresh'], $v.' should turn red after 4 days');
            $this->assertSame($v, $this->comm('PV-2610-001'));
            $this->at(null);
        }
        // YET TO SHARE is always red.
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'YET TO SHARE'])])->assertOk()->assertJsonPath('data.commFresh', false);
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'YET TO START'])])->assertOk()->assertJsonPath('data.commFresh', false);
    }

    public function test_server_command_records_the_colour_reset_once_and_never_changes_the_status(): void
    {
        $this->at('2026-10-21 10:00:00');
        $this->createOrder(['id' => 'PV-2610-001']);
        $this->createOrder(['id' => 'PV-2610-002']);
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'ADV RCVD'])])->assertOk();
        $this->at('2026-10-24 10:00:00');
        $this->update('PV-2610-002', ['statuses' => $this->eight(['comm' => 'QSHARE'])])->assertOk();

        $this->at('2026-10-25 10:30:00');
        $this->artisan('cns:reset-communication')->expectsOutputToContain('1 status colour(s) reset to red')->assertSuccessful();
        $first = Order::where('code', 'PV-2610-001')->first();
        $this->assertSame('ADV RCVD', $first->statuses['comm']);                  // value kept
        $this->assertTrue($first->last_comm_reset->equalTo(Carbon::parse('2026-10-25 10:00:00', self::TZ))); // exact expiry
        $this->assertNull(Order::where('code', 'PV-2610-002')->first()->last_comm_reset); // still green
        $this->assertFalse($first->isCompleted());                                 // never archives

        // Hourly runs don't record it twice.
        $recorded = $first->last_comm_reset->copy();
        Artisan::call('cns:reset-communication');
        $this->assertStringContainsString('0 status colour(s) reset to red (4 days passed), 1 already recorded', Artisan::output());
        $this->assertTrue(Order::where('code', 'PV-2610-001')->first()->last_comm_reset->equalTo($recorded));
        $this->assertSame('ADV RCVD', $this->comm('PV-2610-001'));
        $this->at(null);
    }

    public function test_scheduler_runs_the_colour_reset_hourly(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'cns:reset-communication'));
        $this->assertCount(1, $events);
        $this->assertSame('5 * * * *', $events->first()->expression);
        $this->assertSame('Asia/Kolkata', $events->first()->timezone);
    }

    // ── Receivables (replaces the CNS recovery brief) ───────────────────

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
    }

    public function test_receivables_lists_every_project_with_money_due_using_server_figures(): void
    {
        // invoice = 100 × 1000 × 1.18 = 1,18,000
        $this->createOrder(['id' => 'PV-2610-001', 'statuses' => $this->eight(['comm' => 'PROJECT COMPLETED']),
            'receipts' => [['date' => '2026-10-02', 'amount' => 30000, 'notes' => 'ADV'], ['date' => '2026-10-09', 'amount' => 20000, 'notes' => 'P1']]]);
        $this->createOrder(['id' => 'PV-2610-002', 'statuses' => $this->eight(['comm' => 'QSHARE']), 'receipts' => []]);       // active, nothing paid
        $this->createOrder(['id' => 'PV-2610-003', 'receipts' => [['date' => '2026-10-02', 'amount' => 118000, 'notes' => 'FULL']]]); // fully paid → excluded
        $this->createOrder(['id' => 'PV-2610-004', 'writtenOff' => 'Yes']);                                                        // written off → excluded

        $res = $this->withToken($this->token)->getJson('/api/admin/order-management/receivables')->assertOk();
        $rows = collect($res->json('data'))->keyBy('id');
        $this->assertSame(['PV-2610-002', 'PV-2610-001'], $rows->keys()->all());
        $r = $rows['PV-2610-001'];
        $this->assertEquals(118000, $r['invoice']);
        $this->assertEquals(30000, $r['advance']);
        $this->assertEquals(20000, $r['partial']);
        $this->assertEquals(50000, $r['received']);
        $this->assertEquals(68000, $r['outstanding']);
        $this->assertSame('PARTIALLY PAID', $r['payment_status']);
        $this->assertTrue($r['completed']);
        $this->assertSame('NOT PAID', $rows['PV-2610-002']['payment_status']);
        $this->assertEquals(118000 + 68000, $res->json('total'));
        // Read-only: nothing changed.
        $this->assertSame(4, Order::count());
        $this->assertSame(2, Order::where('code', 'PV-2610-001')->first()->receipts()->count());
    }

    public function test_statements_return_ledger_and_whatsapp_draft(): void
    {
        $this->createOrder(['id' => 'PV-2610-001', 'email' => 'rao@example.com', 'receipts' => [
            ['date' => '2026-10-09', 'amount' => 20000, 'notes' => 'BAL 1'],
            ['date' => '2026-10-02', 'amount' => 30000, 'notes' => 'ADVANCE'],
        ]]);
        $res = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001', 'PV-9999-999']])->assertOk();
        $st = $res->json('statements.0');
        $this->assertSame('PV-2610-001', $st['id']);
        $this->assertSame([['2026-10-02', 'Advance received', 30000], ['2026-10-09', 'Payment received (1)', 20000]],
            array_map(fn ($r) => [$r['date'], $r['label'], (int) $r['amount']], $st['receipts']));
        $this->assertEquals(68000, $st['outstanding']);
        $this->assertSame('rao@example.com', $st['email']);
        $this->assertSame('9876543210', $st['phone']);
        $this->assertSame('PARTIALLY PAID', $st['payment_status']); // advance + one more payment
        $this->assertEquals(30000, $st['advance']);
        $this->assertEquals(20000, $st['partial']);
        $this->assertSame("Dear ANAND RAO,\n\nGreetings from Prestige Ventures.\n\nPlease find your payment statement for LAKE HOUSE (Project ID: PV-2610-001).\n\nInvoice Amount: ₹1,18,000\nTotal Received: ₹50,000\nOutstanding Balance: ₹68,000\n\nThe statement contains the payment details recorded for your project. Kindly review it and let us know if you have any questions.\n\nThanks,\nPrestige Ventures – Customer Support\n(A uPVC Windows & Doors Manufacturing Company)", $st['draft']['text']);
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $st['draft']['url']);
        $this->assertSame([['order_id' => 'PV-9999-999', 'reason' => 'Project not found.']], $res->json('skipped'));
    }

    public function test_bulk_statements_each_match_their_own_project_and_phone(): void
    {
        $this->createOrder(['id' => 'PV-2610-001', 'customer' => 'NEHA KULKARNI', 'project' => 'MAPLE HEIGHTS', 'phone' => '9876500001', 'receipts' => [['date' => '2026-10-02', 'amount' => 30000, 'notes' => '']]]);
        $this->createOrder(['id' => 'PV-2610-002', 'customer' => 'KARTHIK REDDY', 'project' => 'OLD TOWN', 'phone' => '+91 98765-00002', 'receipts' => [['date' => '2026-10-02', 'amount' => 100000, 'notes' => '']]]);
        $this->createOrder(['id' => 'PV-2610-003', 'customer' => 'NO PHONE', 'project' => 'BLANK', 'phone' => '12345', 'receipts' => []]);

        $st = collect($this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-002', 'PV-2610-001', 'PV-2610-003']])
            ->assertOk()->json('statements'))->keyBy('id');

        $neha = $st['PV-2610-001'];
        $this->assertSame('NEHA KULKARNI', $neha['customer']);
        $this->assertEquals(88000, $neha['outstanding']);
        $this->assertStringContainsString('Dear NEHA KULKARNI,', $neha['draft']['text']);
        $this->assertStringContainsString("MAPLE HEIGHTS (Project ID: PV-2610-001).\n\nInvoice Amount: ₹1,18,000\nTotal Received: ₹30,000\nOutstanding Balance: ₹88,000", $neha['draft']['text']);
        $this->assertSame('919876500001', $neha['draft']['phone']);
        $this->assertStringStartsWith('https://wa.me/919876500001?text=Dear%20NEHA%20KULKARNI', $neha['draft']['url']);

        $karthik = $st['PV-2610-002'];
        $this->assertStringContainsString('Dear KARTHIK REDDY,', $karthik['draft']['text']);
        $this->assertStringContainsString('Outstanding Balance: ₹18,000', $karthik['draft']['text']);
        $this->assertSame('919876500002', $karthik['draft']['phone']); // normalised from "+91 98765-00002"
        $this->assertStringNotContainsString('Neha', $karthik['draft']['text']);

        $bad = $st['PV-2610-003'];
        $this->assertNull($bad['draft']['url']);
        $this->assertNull($bad['draft']['phone']);
        $this->assertStringContainsString('no valid 10-digit mobile number', $bad['draft']['reason']);
    }

    public function test_statement_drafts_can_be_switched_off_but_statements_still_work(): void
    {
        $this->createOrder(['id' => 'PV-2610-001']);
        config(['cns.whatsapp.statement_drafts_enabled' => false]);
        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->assertOk()->json('statements.0');
        $this->assertNull($st['draft']);
        $this->assertStringContainsString('switched off', $st['draft_off_reason']);
        $this->assertEquals(68000, $st['outstanding']);
    }

    public function test_statement_messages_are_on_by_default_even_in_production(): void
    {
        // Click-to-chat only pre-fills a chat the admin sends by hand, so no production safety switch is needed.
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');
        $cfg = require base_path('config/cns.php');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');
        $this->assertTrue($cfg['whatsapp']['statement_drafts_enabled']);
    }

    public function test_indian_numbers_with_or_without_country_code_open_the_same_chat(): void
    {
        foreach (['9876543210', '+91 98765 43210', '919876543210', '09876543210', '0091 98765 43210', '+91 0 98765 43210', '98765-43210'] as $raw) {
            $this->assertSame('919876543210', OrderManagement::normalisePhone($raw), $raw); // never 9191…
        }
        foreach (['', '12345', '5876543210', '98765432101', '+1 415 555 0100'] as $raw) {
            $this->assertNull(OrderManagement::normalisePhone($raw), $raw);              // never guessed
        }
        $this->createOrder(['id' => 'PV-2610-001', 'phone' => '+91 0 98765 43210']);
        $d = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0.draft');
        $this->assertSame('919876543210', $d['phone']);
        $this->assertStringStartsWith('https://wa.me/919876543210?text=', $d['url']);
    }

    public function test_statement_message_variants_for_paid_aborted_and_missing_phone(): void
    {
        $this->createOrder(['id' => 'PV-2610-001', 'receipts' => $this->receipts(118000)]);                                    // fully paid
        $this->createOrder(['id' => 'PV-2610-002', 'customer' => 'MEERA SHAH', 'phone' => '9876505678', 'receipts' => $this->receipts(20000),
            'statuses' => $this->eight(['comm' => 'PROJECT ABORT'])]);                                                       // aborted, ₹20,000 retained
        $this->createOrder(['id' => 'PV-2610-003', 'customer' => 'NO PHONE', 'phone' => '', 'receipts' => $this->receipts(1000)]); // no number
        $st = collect($this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001', 'PV-2610-002', 'PV-2610-003']])->json('statements'))->keyBy('id');

        $paid = $st['PV-2610-001']['draft'];
        $this->assertStringContainsString('Outstanding Balance: ₹0', $paid['text']);
        $this->assertStringContainsString('This project is fully paid and the outstanding balance is ₹0.', $paid['text']);
        $this->assertDoesNotMatchRegularExpression('/kindly (pay|arrange)|please pay|payment at the earliest/i', $paid['text']);
        $this->assertSame('https://wa.me/919876543210?text='.rawurlencode($paid['text']), $paid['url']);

        $ab = $st['PV-2610-002']['draft'];
        $this->assertStringContainsString('This project has been closed.', $ab['text']);
        foreach (['Total Payments: ₹20,000', 'Total Refunded: ₹0', 'Net Amount Retained: ₹20,000', 'Balance After Abort: ₹0'] as $line) {
            $this->assertStringContainsString($line, $ab['text']);
        }
        $this->assertStringNotContainsString('Outstanding Balance', $ab['text']);
        $this->assertDoesNotMatchRegularExpression('/kindly (pay|arrange)|please pay/i', $ab['text']);
        $this->assertSame('919876505678', $ab['phone']);

        $none = $st['PV-2610-003']['draft'];
        $this->assertNull($none['phone']);
        $this->assertNull($none['url']);                       // no invented number, no link
        $this->assertNotNull($none['text']);                   // the message can still be copied
        $this->assertNotEmpty($st['PV-2610-003']['ledger']);   // and the PDF can still be made
    }

    public function test_existing_projects_keep_their_status_when_the_default_changes(): void
    {
        $this->createOrder(['statuses' => $this->eight(['comm' => 'YET TO SHARE'])])->assertCreated();
        Order::first()->forceFill(['statuses' => ['comm' => 'NO'] + Order::first()->statuses])->save(); // older record
        $this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertOk()->assertJsonPath('orders.0.statuses.comm', 'NO');
        $this->assertSame('NO', $this->comm('PV-2610-001'));
    }

    private function receipts(float ...$amounts): array
    {
        return array_map(fn ($a, $i) => ['date' => '2026-10-'.str_pad((string) (2 + $i), 2, '0', STR_PAD_LEFT), 'amount' => $a, 'notes' => 'PAY '.($i + 1)], $amounts, array_keys($amounts));
    }

    private function ids(string $endpoint): array
    {
        $rows = $this->withToken($this->token)->getJson('/api/admin/order-management/'.$endpoint)->assertOk()->json('data');

        return $endpoint === 'archive' ? array_column(array_column($rows, 'order'), 'id') : array_column($rows, 'id');
    }

    public function test_archive_and_receivables_follow_completion_and_payment(): void
    {
        // Invoice for every order: 100 SFT × ₹1,000 + 18% = ₹1,18,000.
        $done = $this->eight(['comm' => 'PROJECT COMPLETED']);
        $this->createOrder(['id' => 'PV-2610-001', 'statuses' => $done, 'receipts' => $this->receipts(50000, 68000)]); // completed, ₹0 due
        $this->createOrder(['id' => 'PV-2610-002', 'statuses' => $done, 'receipts' => $this->receipts(108000)]);       // completed, ₹10,000 due
        $this->createOrder(['id' => 'PV-2610-003', 'statuses' => $done, 'receipts' => $this->receipts(68000)]);        // completed, ₹50,000 due
        $this->createOrder(['id' => 'PV-2610-004', 'statuses' => $this->eight(['comm' => 'INST STRTD']), 'receipts' => $this->receipts(108000)]); // active, ₹10,000 due
        $this->createOrder(['id' => 'PV-2610-005', 'statuses' => $this->eight(['comm' => 'HAND OVER']), 'receipts' => $this->receipts(118000)]);  // active, ₹0 due

        $this->assertSame(['PV-2610-001'], $this->ids('archive'));
        $receivables = $this->ids('receivables');
        sort($receivables);
        $this->assertSame(['PV-2610-002', 'PV-2610-003', 'PV-2610-004'], $receivables);
        $this->assertEmpty(array_intersect($this->ids('archive'), $receivables)); // never in both

        $rows = collect($this->withToken($this->token)->getJson('/api/admin/order-management/receivables')->json('data'))->keyBy('id');
        $this->assertSame(10000.0, (float) $rows['PV-2610-002']['outstanding']);
        $this->assertSame(50000.0, (float) $rows['PV-2610-003']['outstanding']);
        $this->assertTrue($rows['PV-2610-002']['completed']);
        $this->assertFalse($rows['PV-2610-004']['completed']);
        $this->assertFalse($rows['PV-2610-002']['archived']);

        // Final payment on a completed project → moves from Receivables to Archive (same record).
        $this->update('PV-2610-002', ['statuses' => $done, 'receipts' => $this->receipts(108000, 10000)])->assertOk();
        $this->assertContains('PV-2610-002', $this->ids('archive'));
        $this->assertNotContains('PV-2610-002', $this->ids('receivables'));
        $this->assertCount(2, Order::where('code', 'PV-2610-002')->first()->receipts); // payment history kept

        // Status changed away from PROJECT COMPLETED → leaves the Archive.
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'FEEDBACK REQ']), 'receipts' => $this->receipts(50000, 68000)])->assertOk();
        $this->assertNotContains('PV-2610-001', $this->ids('archive'));
        $this->assertSame(5, Order::count()); // nothing duplicated or deleted
    }

    public function test_project_abort_archives_at_once_and_keeps_every_record(): void
    {
        // ₹68,000 still due: PROJECT ABORT archives it anyway (PROJECT COMPLETED would not).
        $this->createOrder(['id' => 'PV-2610-001', 'email' => 'Anand@Example.com', 'receipts' => $this->receipts(30000, 20000), 'statuses' => $this->eight(['comm' => 'INST STRTD'])])->assertCreated();
        $this->createOrder(['id' => 'PV-2610-002', 'receipts' => $this->receipts(30000), 'statuses' => $this->eight(['comm' => 'PROJECT COMPLETED'])])->assertCreated();
        $this->assertContains('PV-2610-001', $this->ids('receivables'));

        $at = $this->update('PV-2610-001', ['email' => 'Anand@Example.com', 'receipts' => $this->receipts(30000, 20000), 'statuses' => $this->eight(['comm' => 'PROJECT ABORT'])])
            ->assertOk()->assertJsonPath('data.statuses.comm', 'PROJECT ABORT')->json('data.completedAt');
        $this->assertNotNull($at);
        $this->assertSame(['PV-2610-001'], $this->ids('archive'));                // aborted → Archive
        $this->assertNotContains('PV-2610-001', $this->ids('receivables'));       // never in both
        $this->assertContains('PV-2610-002', $this->ids('receivables'));          // completed with money due stays

        $row = $this->withToken($this->token)->getJson('/api/admin/order-management/archive')->json('data.0');
        $this->assertSame('ANAND RAO', $row['order']['customer']);
        $this->assertSame('9876543210', $row['order']['phone']);
        $this->assertSame('Anand@Example.com', $row['order']['email']);
        $this->assertCount(2, $row['order']['receipts']);                        // payment history kept
        $this->assertEquals(0, $row['summary']['outstanding']);                  // abort closes the account at ₹0
        $this->assertEquals(50000, $row['summary']['received']);                 // payments kept as they are
        $this->assertTrue($row['summary']['aborted']);
        $this->assertTrue($row['summary']['archived']);
        $this->assertSame(['PROJECT ABORT'], $this->withToken($this->token)->getJson('/api/admin/order-management/archive')->json('config.archive_values'));

        // Selecting it again keeps the original time; restore puts it back on the active board.
        $this->assertSame($at, $this->update('PV-2610-001', ['receipts' => $this->receipts(30000, 20000), 'statuses' => $this->eight(['comm' => 'PROJECT ABORT', 'glass' => 'AVAILABLE'])])->json('data.completedAt'));
        $this->update('PV-2610-001', ['receipts' => $this->receipts(30000, 20000), 'statuses' => $this->eight(['comm' => 'YET TO SHARE'])])
            ->assertOk()->assertJsonPath('data.completedAt', null);
        $this->assertNotContains('PV-2610-001', $this->ids('archive'));
        $this->assertContains('PV-2610-001', $this->ids('receivables'));
        $this->assertEquals(68000, $this->statement()['outstanding']);            // active again: balance due again
        $this->assertSame(2, Order::count());
        $this->assertSame(2, Order::where('code', 'PV-2610-001')->first()->receipts()->count());
    }

    public function test_project_abort_is_never_colour_reset(): void
    {
        $this->createOrder(['id' => 'PV-2610-001', 'statuses' => $this->eight(['comm' => 'PROJECT ABORT'])])->assertCreated();
        $this->assertTrue(OrderManagement::isCompletionValue('PROJECT ABORT'));
        $this->assertContains('PROJECT ABORT', config('cns.comm_reset.stop_values'));
    }

    public function test_customer_email_is_saved_validated_and_kept(): void
    {
        $this->createOrder(['email' => 'rao@example.com'])->assertCreated()->assertJsonPath('data.email', 'rao@example.com');
        $this->update('PV-2610-001', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        // An older dashboard that doesn't send the field keeps the saved email.
        $p = $this->payload(['id' => 'PV-2610-001']);
        unset($p['email']);
        $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-2610-001', $p)->assertOk()->assertJsonPath('data.email', 'rao@example.com');
        $this->update('PV-2610-001', ['email' => ''])->assertOk()->assertJsonPath('data.email', '');
        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0');
        $this->assertSame('', $st['email']);
    }

    public function test_archive_rows_carry_the_full_record(): void
    {
        $this->createOrder(['id' => 'PV-2610-001', 'receipts' => $this->receipts(118000)]);
        $profile = \App\Models\OrderManagement\InventoryProfile::create(['code' => 'EWSPL-1', 'item' => 'FRAME', 'base_stock' => 10, 'stock' => 10, 'unit_cost' => 1, 'unit' => 'PCS']);
        $profile->usages()->create(['usage_key' => 'PV-2610-001', 'qty' => 4]);
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED']), 'receipts' => $this->receipts(118000)])->assertOk();
        $this->update('PV-2610-001', ['statuses' => $this->eight(['comm' => 'PROJECT COMPLETED', 'glass' => 'AVAILABLE']), 'receipts' => $this->receipts(118000)])->assertOk(); // selected again

        $res = $this->withToken($this->token)->getJson('/api/admin/order-management/archive')->assertOk();
        $this->assertSame(['PV-2610-001'], array_column(array_column($res->json('data'), 'order'), 'id')); // once
        $res->assertJsonPath('data.0.order.customer', 'ANAND RAO')
            ->assertJsonPath('data.0.order.statuses.comm', 'PROJECT COMPLETED')
            ->assertJsonPath('data.0.order.statuses.glass', 'AVAILABLE')
            ->assertJsonPath('data.0.summary.invoice', 118000)
            ->assertJsonPath('data.0.summary.received', 118000)
            ->assertJsonPath('data.0.summary.outstanding', 0)
            ->assertJsonPath('data.0.summary.archived', true)
            ->assertJsonPath('data.0.usage.0.code', 'EWSPL-1')
            ->assertJsonPath('data.0.usage.0.qty', 4);
        $this->assertNotNull($res->json('data.0.order.completedAt'));
    }

    public function test_archive_endpoint_is_admin_only(): void
    {
        $this->resetAuth();
        $this->getJson('/api/admin/order-management/archive')->assertStatus(401);
        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->resetAuth();
        $this->withToken(auth('api')->login($partner))->getJson('/api/admin/order-management/archive')->assertStatus(403);
    }

    public function test_receivables_endpoints_are_admin_only(): void
    {
        $this->resetAuth();
        $this->getJson('/api/admin/order-management/receivables')->assertStatus(401);
        $this->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->assertStatus(401);

        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->resetAuth();
        $pt = auth('api')->login($partner);
        $this->withToken($pt)->getJson('/api/admin/order-management/receivables')->assertStatus(403);
        $this->withToken($pt)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->assertStatus(403);
    }

    public function test_outstanding_matches_the_dashboard_formula(): void
    {
        // Same maths as the dashboard: round(sft×rate, 2) × (1 + tax%) rounded, minus receipts.
        $this->createOrder(['sft' => 1250.5, 'rate' => 1450.25, 'tax' => 18,
            'receipts' => [['date' => '2026-10-02', 'amount' => 300000, 'notes' => 'A'], ['date' => '2026-10-05', 'amount' => 1000, 'notes' => 'B']]]);
        $o = Order::with('receipts')->first();
        $this->assertSame(round(round(1250.5 * 1450.25, 2) * 1.18, 2), $o->invoiceAmount()); // area and rate stay decimal
        $this->assertSame(round($o->invoiceAmount() - 301000, 2), $o->outstanding());
    }

    // ── Customer and project are upper case; other text is kept as entered ──

    public function test_customer_and_project_are_saved_upper_case_and_other_text_keeps_its_casing(): void
    {
        $this->createOrder(['customer' => 'Anand rao & Sons', 'project' => 'Lake House – Block B', 'email' => 'Anand.Rao@Example.com', 'remarks' => 'call after 5pm, ask for Mr. D\'Souza',
            'receipts' => [['date' => '2026-10-02', 'amount' => 50000, 'notes' => 'Advance via NEFT ref aBc123']]])->assertCreated()
            ->assertJsonPath('data.customer', 'ANAND RAO & SONS')
            ->assertJsonPath('data.project', 'LAKE HOUSE – BLOCK B')
            ->assertJsonPath('data.email', 'Anand.Rao@Example.com')
            ->assertJsonPath('data.remarks', 'call after 5pm, ask for Mr. D\'Souza')
            ->assertJsonPath('data.receipts.0.notes', 'Advance via NEFT ref aBc123');
        $this->update('PV-2610-001', ['customer' => 'anand RAO & sons', 'project' => 'Lake House – Block B', 'receipts' => [['date' => '2026-10-02', 'amount' => 50000, 'notes' => 'Advance via NEFT ref aBc123']]])
            ->assertOk()->assertJsonPath('data.customer', 'ANAND RAO & SONS');
        $this->assertSame('ANAND RAO & SONS', Order::first()->customer);
        $this->assertSame('LAKE HOUSE – BLOCK B', Order::first()->project);
        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0');
        $this->assertStringStartsWith("Dear ANAND RAO & SONS,\n", $st['draft']['text']);
        $this->assertStringContainsString('LAKE HOUSE – BLOCK B (Project ID: PV-2610-001)', $st['draft']['text']);
        $this->assertSame('Advance via NEFT ref aBc123', $st['ledger'][1]['details']);
    }

    public function test_an_older_mixed_case_record_is_shown_upper_case_but_not_rewritten(): void
    {
        $this->createOrder()->assertCreated();
        Order::query()->update(['customer' => 'Malika Enterprises', 'project' => 'Prestige Villa']); // as saved before the change
        $this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertOk()
            ->assertJsonPath('orders.0.customer', 'MALIKA ENTERPRISES')
            ->assertJsonPath('orders.0.project', 'PRESTIGE VILLA');
        $this->withToken($this->token)->getJson('/api/admin/order-management/receivables')->assertOk()
            ->assertJsonPath('data.0.customer', 'MALIKA ENTERPRISES');
        $this->assertSame('Malika Enterprises', Order::first()->customer); // reading never changes the record
    }

    // ── Whole numbers ──────────────────────────────────────────────────

    public function test_receipt_amounts_are_whole_rupees_but_stored_decimals_are_kept(): void
    {
        $this->createOrder(['receipts' => [['date' => '2026-10-02', 'amount' => 1000.5, 'notes' => 'X']]])
            ->assertStatus(422)->assertJsonValidationErrors('receipts.0.amount');
        $this->createOrder(['receipts' => [['date' => '2026-10-02', 'amount' => 1000, 'notes' => 'X']]])->assertCreated();
        // An older record with paise can still be edited without changing that amount …
        \App\Models\OrderManagement\OrderReceipt::query()->update(['amount' => 1000.5]);
        $this->update('PV-2610-001', ['remarks' => 'edited', 'receipts' => [['date' => '2026-10-02', 'amount' => 1000.5, 'notes' => 'X']]])->assertOk();
        // … but a new decimal amount is refused.
        $this->update('PV-2610-001', ['receipts' => [['date' => '2026-10-02', 'amount' => 1000.5, 'notes' => 'X'], ['date' => '2026-10-03', 'amount' => 99.9, 'notes' => 'Y']]])
            ->assertStatus(422)->assertJsonValidationErrors('receipts.1.amount');
        // Area, rate and GST stay decimal.
        $this->update('PV-2610-001', ['sft' => 120.375, 'rate' => 1450.5, 'tax' => 12.5, 'receipts' => [['date' => '2026-10-02', 'amount' => 1000.5, 'notes' => 'X']]])->assertOk();
    }

    public function test_inventory_and_stock_quantities_are_whole_numbers(): void
    {
        $inv = '/api/admin/order-management/inventory';
        $this->withToken($this->token)->postJson($inv, ['code' => 'EWSPL-1', 'item' => 'Frame', 'stock' => 2.5, 'usage' => []])->assertStatus(422);
        $this->withToken($this->token)->postJson($inv, ['code' => 'EWSPL-1', 'item' => 'Frame', 'stock' => 10, 'usage' => []])->assertCreated();
        $this->withToken($this->token)->putJson($inv.'/EWSPL-1', ['item' => 'Frame', 'stock' => 10, 'usage' => ['PV-2610-001' => 1.5]])->assertStatus(422);
        $this->withToken($this->token)->putJson($inv.'/EWSPL-1', ['item' => 'Frame', 'stock' => 10, 'usage' => ['PV-2610-001' => 2]])->assertOk();
        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory-stock/add', ['code' => 'EWSPL-1', 'qty' => 2.5])->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->withToken($this->token)->postJson('/api/admin/order-management/inventory-stock/add', ['code' => 'EWSPL-1', 'qty' => 3])->assertSuccessful();
    }

    // ── Refunds ────────────────────────────────────────────────────────

    private function refundOrder(array $rows, array $extra = [])
    {
        return $this->createOrder(['receipts' => array_map(fn ($r) => ['date' => $r[0], 'amount' => $r[1], 'notes' => $r[2] ?? '', 'kind' => $r[3] ?? 'payment'], $rows)] + $extra);
    }

    /** A refund row already stored (e.g. entered before refunds moved to Project Abort). */
    private function storedRefund(string $date, int $amount, string $notes): void
    {
        $o = Order::where('code', 'PV-2610-001')->first();
        $o->receipts()->create(['date' => $date, 'amount' => $amount, 'kind' => 'refund', 'notes' => $notes, 'position' => 50 + $o->receipts()->count()]);
    }

    private function statement(): array
    {
        return $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0');
    }

    public function test_normal_payments_without_refunds_are_unchanged(): void
    {
        $this->refundOrder([['2026-10-02', 30000, 'ADV'], ['2026-10-09', 20000, 'PART']])->assertCreated();
        $s = $this->statement();
        $this->assertEquals([118000, 30000, 20000, 0, 50000, 68000], [$s['invoice'], $s['advance'], $s['partial'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame('PARTIALLY PAID', $s['payment_status']);
        $this->assertEquals(0, $s['abort_write_off']);                          // active project: nothing written off
        $this->assertNotContains('Project Aborted', array_column($s['ledger'], 'transaction'));
    }

    public function test_stored_refund_on_an_active_project_reduces_received_and_raises_outstanding(): void
    {
        $this->refundOrder([['2026-10-02', 50000, 'ADV'], ['2026-10-09', 20000, 'PART']])->assertCreated();
        $this->storedRefund('2026-10-15', 15000, 'Cheque returned');
        $s = $this->statement();
        $this->assertEquals([50000, 20000, 15000, 55000, 63000], [$s['advance'], $s['partial'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame('PARTIALLY PAID', $s['payment_status']);
        $last = end($s['ledger']);
        $this->assertSame(['Payment Refunded', 'Refunded – Cheque returned', -15000.0, 63000.0, true], [$last['transaction'], $last['details'], (float) $last['payment'], (float) $last['balance'], $last['refund']]);
        $this->assertEquals($s['outstanding'], $last['balance']); // ledger ends at the outstanding amount
        $this->assertCount(3, Order::first()->receipts);         // payment and refund rows both kept
    }

    public function test_full_refund_of_an_aborted_project_with_several_payments_and_refunds(): void
    {
        $this->refundOrder([['2026-10-02', 30000, 'ADV'], ['2026-10-05', 20000, 'PART']], ['statuses' => $this->eight(['comm' => 'INST STRTD'])])->assertCreated();
        $this->abortWith(25000, 'req-two-0001', ['notes' => 'Refund 1 of 2', 'refundDate' => '2026-10-20'])->assertOk();
        $this->abortWith(25000, 'req-two-0002', ['notes' => 'Refund 2 of 2', 'refundDate' => '2026-10-21'])->assertOk(); // already aborted: refund only
        $s = $this->statement();
        $this->assertTrue($s['aborted']);
        $this->assertEquals([50000, 50000, 0, 0], [$s['payments'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame('REFUNDED', $s['payment_status']);                       // not "fully paid", nothing invented
        $this->assertSame(['Invoice', 'Advance received', 'Payment received (1)', 'Payment Refunded', 'Payment Refunded', 'Project Aborted'], array_column($s['ledger'], 'transaction'));
        $this->assertSame([118000.0, 88000.0, 68000.0, 93000.0, 118000.0, 0.0], array_map(fn ($r) => (float) $r['balance'], $s['ledger']));
        $this->assertSame(['Refunded – Project aborted – Refund 1 of 2', 'Refunded – Project aborted – Refund 2 of 2'], array_values(array_column(array_filter($s['ledger'], fn ($r) => ! empty($r['refund'])), 'details')));
        $this->assertEquals($s['outstanding'], end($s['ledger'])['balance']);
        foreach (['Total Payments: ₹50,000', 'Total Refunded: ₹50,000', 'Net Amount Retained: ₹0', 'Balance After Abort: ₹0'] as $line) {
            $this->assertStringContainsString($line, $s['draft']['text']);
        }
        $this->assertContains('PV-2610-001', $this->ids('archive'));            // aborted → Archive, record kept
    }

    public function test_refunds_cannot_be_added_changed_or_removed_through_the_order_form(): void
    {
        // A new order cannot carry a refund.
        $this->refundOrder([['2026-10-02', 30000, 'ADV'], ['2026-10-09', 5000, 'Refund', 'refund']])
            ->assertStatus(422)->assertJsonValidationErrors('receipts.1.kind');
        $this->refundOrder([['2026-10-02', 30000, 'ADV']])->assertCreated();
        $this->storedRefund('2026-10-09', 5000, 'Refund A');
        $this->storedRefund('2026-10-10', 5000, 'Refund B');
        $this->assertEquals(20000, $this->statement()['received']);
        $pay = ['date' => '2026-10-02', 'amount' => 30000, 'notes' => 'ADV', 'kind' => 'payment'];
        $a = ['date' => '2026-10-09', 'amount' => 5000, 'notes' => 'Refund A', 'kind' => 'refund'];
        $b = ['date' => '2026-10-10', 'amount' => 5000, 'notes' => 'Refund B', 'kind' => 'refund'];
        // Saving again with the stored refunds sent back unchanged never deducts them a second time.
        $this->update('PV-2610-001', ['remarks' => 'checked', 'receipts' => [$pay, $a, $b]])->assertOk();
        $this->assertEquals(20000, $this->statement()['received']);
        // Adding, duplicating or changing a refund through the form is refused; nothing changes.
        $this->update('PV-2610-001', ['receipts' => [$pay, $a, $b, ['date' => '2026-10-11', 'amount' => 1000, 'notes' => 'extra', 'kind' => 'refund']]])
            ->assertStatus(422)->assertJsonValidationErrors('receipts.3.kind');
        $this->update('PV-2610-001', ['receipts' => [$pay, $a, $a]])->assertStatus(422)->assertJsonValidationErrors('receipts.2.kind');
        $this->update('PV-2610-001', ['receipts' => [$pay, ['amount' => 4000] + $a, $b]])->assertStatus(422)->assertJsonValidationErrors('receipts.1.kind');
        $this->assertEquals(20000, $this->statement()['received']);
        // Leaving the refund rows out does not delete them.
        $this->update('PV-2610-001', ['receipts' => [$pay + ['notes' => 'ADV']]])->assertOk();
        $this->assertSame(2, Order::first()->receipts->where('kind', 'refund')->count());
        $this->assertEquals(20000, $this->statement()['received']);
        // Payments can never drop below the stored refunds.
        $this->update('PV-2610-001', ['receipts' => [['amount' => 9000] + $pay]])->assertStatus(422)->assertJsonValidationErrors('receipts');
        $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-2610-001', $this->payload(['id' => 'PV-2610-001', 'receipts' => [['date' => '2026-10-02', 'amount' => 30000, 'notes' => 'ADV', 'kind' => 'chargeback']]]))
            ->assertStatus(422)->assertJsonValidationErrors('receipts.0.kind');
    }

    public function test_refunds_never_count_as_the_advance_or_latest_payment_in_messages(): void
    {
        $this->refundOrder([['2026-10-02', 30000, 'ADV'], ['2026-10-09', 20000, 'PART']])->assertCreated();
        $this->storedRefund('2026-10-01', 5000, 'early refund');
        $this->storedRefund('2026-10-12', 2000, 'Refund');
        $o = Order::with('receipts')->first();
        $this->assertSame(30000.0, (float) $o->advanceReceipt()->amount);  // first *payment*, not the earlier refund
        $this->assertSame(20000.0, (float) $o->latestReceipt()->amount);   // latest *payment*, not the later refund
        $s = $this->statement();
        $this->assertEquals([30000, 20000, 7000, 43000], [$s['advance'], $s['partial'], $s['refunded'], $s['received']]);
    }

    // ── Delete Project ──────────────────────────────────────────────────

    private function deleteOrder(string $code, ?string $confirm)
    {
        return $this->withToken($this->token)->deleteJson("/api/admin/order-management/orders/$code", $confirm === null ? [] : ['confirm' => $confirm]);
    }

    private function deleteCheck(string $code)
    {
        return $this->withToken($this->token)->getJson("/api/admin/order-management/orders/$code/delete-check");
    }

    public function test_a_project_with_no_history_is_deleted_only_after_typing_its_id(): void
    {
        $this->createOrder(['receipts' => [['date' => '2026-10-02', 'amount' => 0, 'notes' => '']]])->assertCreated(); // an empty ₹0 row is not history
        $this->deleteCheck('PV-2610-001')->assertOk()->assertJsonPath('data.deletable', true)->assertJsonPath('data.reasons', []);
        $this->deleteOrder('PV-2610-001', null)->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->deleteOrder('PV-2610-001', 'PV-2610-002')->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame(1, Order::count());
        $this->deleteOrder('PV-2610-001', 'pv-2610-001')->assertOk()->assertJsonPath('deleted', 'PV-2610-001');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, \App\Models\OrderManagement\OrderReceipt::count());
        $this->deleteOrder('PV-2610-001', 'PV-2610-001')->assertNotFound();
        $this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertOk()->assertJsonCount(0, 'orders');
    }

    public function test_projects_with_payments_refunds_statements_inventory_or_a_closed_status_cannot_be_deleted(): void
    {
        $blocked = function (string $code, string $reason) {
            $this->deleteCheck($code)->assertOk()->assertJsonPath('data.deletable', false);
            $this->assertStringContainsString($reason, implode(' | ', $this->deleteCheck($code)->json('data.reasons')));
            $this->deleteOrder($code, $code)->assertStatus(409)->assertJsonFragment(['reasons' => $this->deleteCheck($code)->json('data.reasons')]);
            $this->assertTrue(Order::where('code', $code)->exists(), "$code was deleted");
        };
        // Payments.
        $this->createOrder()->assertCreated();
        $blocked('PV-2610-001', '1 payment recorded');
        $this->assertSame(1, Order::where('code', 'PV-2610-001')->first()->receipts()->count()); // history intact
        // Payment + refund (aborted with a refund).
        $this->createOrder(['id' => 'PV-2610-002', 'statuses' => $this->eight(['comm' => 'INST STRTD'])])->assertCreated();
        $this->withToken($this->token)->postJson('/api/admin/order-management/orders/PV-2610-002/abort', ['refundAmount' => 10000, 'refundDate' => '2026-10-20', 'requestId' => 'del-test-0001', 'confirm' => true])->assertOk();
        $blocked('PV-2610-002', '1 refund recorded');
        // Aborted with no payments at all: kept in the Archive.
        $this->createOrder(['id' => 'PV-2610-003', 'receipts' => [], 'statuses' => $this->eight(['comm' => 'PROJECT ABORT'])])->assertCreated();
        $blocked('PV-2610-003', 'kept in the Archive');
        // A statement was sent.
        $this->createOrder(['id' => 'PV-2610-004', 'receipts' => []])->assertCreated();
        \App\Models\OrderManagement\StatementSend::create(['order_id' => Order::where('code', 'PV-2610-004')->value('id'), 'recipient' => '919876543210', 'fingerprint' => str_repeat('a', 64), 'filename' => 'Statement.pdf', 'status' => 'accepted']);
        $blocked('PV-2610-004', '1 payment statement sent');
        // Inventory used on the project.
        $this->createOrder(['id' => 'PV-2610-005', 'receipts' => []])->assertCreated();
        $inv = '/api/admin/order-management/inventory';
        $this->withToken($this->token)->postJson($inv, ['code' => 'EWSPL-9', 'item' => 'Frame', 'stock' => 10, 'usage' => ['PV-2610-005' => 2]])->assertCreated();
        $blocked('PV-2610-005', 'inventory used');
        $this->assertSame(5, Order::count());
    }

    // ── Project Abort with an admin-chosen refund ───────────────────────

    private function abortWith(int $refund, string $requestId, array $extra = [])
    {
        return $this->withToken($this->token)->postJson('/api/admin/order-management/orders/PV-2610-001/abort', array_merge([
            'refundAmount' => $refund, 'refundDate' => '2026-10-20', 'notes' => 'Refund by NEFT', 'requestId' => $requestId, 'confirm' => true,
        ], $extra)); // overrides win
    }

    private function paidTwice(): void
    {
        // Invoice ₹1,18,000; payments ₹30,000 + ₹20,000 = ₹50,000.
        $this->refundOrder([['2026-10-02', 30000, 'ADV'], ['2026-10-09', 20000, 'PART']], ['statuses' => $this->eight(['comm' => 'INST STRTD'])])->assertCreated();
    }

    public function test_abort_summary_shows_received_previous_refunds_and_refundable(): void
    {
        $this->paidTwice();
        $this->withToken($this->token)->getJson('/api/admin/order-management/orders/PV-2610-001/abort-summary')->assertOk()
            ->assertJsonPath('data.payments', 50000)->assertJsonPath('data.refunded', 0)
            ->assertJsonPath('data.refundable', 50000)->assertJsonPath('data.aborted', false)->assertJsonPath('data.refund_status', 'NO REFUND');
    }

    public function test_abort_with_full_refund(): void
    {
        $this->paidTwice();
        $this->abortWith(50000, 'req-full-0001')->assertOk()->assertJsonPath('data.statuses.comm', 'PROJECT ABORT')
            ->assertJsonPath('summary.refunded', 50000)->assertJsonPath('summary.refundable', 0)->assertJsonPath('summary.refund_status', 'FULL REFUND');
        $s = $this->statement();
        $this->assertEquals([118000, 50000, 50000, 0, 0], [$s['invoice'], $s['payments'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame(['REFUNDED', 'FULL REFUND', true], [$s['payment_status'], $s['refund_status'], $s['aborted']]);
        $kinds = Order::first()->receipts->map(fn ($r) => [$r->kind, (float) $r->amount])->all();
        $this->assertSame([['payment', 30000.0], ['payment', 20000.0], ['refund', 50000.0]], $kinds); // originals kept, refund added
        [$refundRow, $closing] = array_slice($s['ledger'], -2);
        $this->assertSame(['2026-10-20', 'Payment Refunded', 'Refunded – Project aborted – Refund by NEFT', -50000.0, 118000.0], [$refundRow['date'], $refundRow['transaction'], $refundRow['details'], (float) $refundRow['payment'], (float) $refundRow['balance']]);
        $this->assertSame(['Project Aborted', 'Outstanding balance written off', -118000.0, null, 0.0], [$closing['transaction'], $closing['details'], (float) $closing['amount'], $closing['payment'], (float) $closing['balance']]);
        $this->assertArrayNotHasKey('refund', $closing);                         // the write-off is neither a payment nor a refund
        $this->assertEquals(118000, $s['abort_write_off']);
        $this->assertEquals($s['invoice'], $s['received'] + $s['abort_write_off']); // invoice = retained + written off
        $this->assertContains('PV-2610-001', $this->ids('archive'));
        $this->assertNotNull(Order::first()->completed_at);
    }

    public function test_abort_with_partial_refund_keeps_the_retained_amount(): void
    {
        $this->paidTwice();
        $this->abortWith(20000, 'req-part-0001')->assertOk()->assertJsonPath('summary.refund_status', 'PARTIAL REFUND');
        $s = $this->statement();
        $this->assertEquals([50000, 20000, 30000, 0], [$s['payments'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame(['Payment Refunded', 'Project Aborted'], array_column(array_slice($s['ledger'], -2), 'transaction'));
        $this->assertSame([88000.0, 0.0], array_map(fn ($r) => (float) $r['balance'], array_slice($s['ledger'], -2)));
        $this->assertEquals([88000, 118000], [$s['abort_write_off'], $s['received'] + $s['abort_write_off']]);
        $this->assertSame(['PARTIALLY PAID', 'PARTIAL REFUND'], [$s['payment_status'], $s['refund_status']]);
        $this->assertSame('PROJECT ABORT', $s['project_status']); // project status kept apart from the refund status
    }

    public function test_abort_with_zero_refund_records_no_refund(): void
    {
        $this->paidTwice();
        $this->abortWith(0, 'req-zero-0001', ['refundDate' => null])->assertOk()
            ->assertJsonPath('data.statuses.comm', 'PROJECT ABORT')->assertJsonPath('summary.refund_status', 'NO REFUND');
        $this->assertSame(0, Order::first()->receipts->where('kind', 'refund')->count());
        $s = $this->statement();
        $this->assertEquals([50000, 0, 50000, 0], [$s['payments'], $s['refunded'], $s['received'], $s['outstanding']]);
        $this->assertSame('PARTIALLY PAID', $s['payment_status']);                            // two payments kept; never "fully paid"
        $this->assertSame([], array_filter($s['ledger'], fn ($r) => ! empty($r['refund'])));    // nothing labelled as refunded
        $this->assertSame(['Project Aborted', -68000.0, 0.0], [end($s['ledger'])['transaction'], (float) end($s['ledger'])['amount'], (float) end($s['ledger'])['balance']]);
        $this->assertEquals(68000, $s['abort_write_off']);
    }

    public function test_a_refund_cannot_be_dated_before_the_payment_it_returns(): void
    {
        $this->paidTwice(); // ₹30,000 on 02 Oct, ₹20,000 on 09 Oct
        $this->abortWith(40000, 'req-date-0001', ['refundDate' => '2026-10-05'])->assertStatus(422)->assertJsonValidationErrors('refundDate');
        $this->abortWith(5000, 'req-date-0002', ['refundDate' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('refundDate');
        $this->assertFalse(Order::first()->isAborted());                       // nothing recorded
        $this->abortWith(30000, 'req-date-0003', ['refundDate' => '2026-10-05'])->assertOk();
        $this->abortWith(20000, 'req-date-0004', ['refundDate' => '2026-10-08'])->assertStatus(422); // ₹20,000 not paid until 09 Oct
        $this->abortWith(20000, 'req-date-0005', ['refundDate' => '2026-10-09'])->assertOk()->assertJsonPath('summary.refund_status', 'FULL REFUND');
        $s = $this->statement();
        $this->assertEquals([50000, 50000, 0, 0], [$s['payments'], $s['refunded'], $s['received'], $s['outstanding']]);
    }

    public function test_refund_above_the_amount_paid_is_refused_and_nothing_changes(): void
    {
        $this->paidTwice();
        $this->abortWith(50001, 'req-over-0001')->assertStatus(422)->assertJsonValidationErrors('refundAmount');
        $o = Order::first();
        $this->assertFalse($o->isAborted());
        $this->assertSame(0, $o->receipts->where('kind', 'refund')->count());
        // After a partial refund, only the remainder can still be refunded.
        $this->abortWith(30000, 'req-part-0002')->assertOk();
        $this->abortWith(20001, 'req-over-0002')->assertStatus(422);
        $this->abortWith(20000, 'req-rest-0002')->assertOk()->assertJsonPath('summary.refund_status', 'FULL REFUND');
    }

    public function test_repeated_confirmation_and_later_edits_never_double_count(): void
    {
        $this->paidTwice();
        $this->abortWith(20000, 'req-same-0001')->assertOk()->assertJsonPath('replayed', false);
        $this->abortWith(20000, 'req-same-0001')->assertOk()->assertJsonPath('replayed', true);   // same dialog again
        $this->abortWith(20000, 'req-same-0001')->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Order::first()->receipts->where('kind', 'refund')->count());
        $this->assertEquals(30000, $this->statement()['received']);
        // Editing the order afterwards (all receipts sent back as they are) keeps exactly one refund.
        $o = $this->withToken($this->token)->getJson('/api/admin/order-management/data')->json('orders.0');
        $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-2610-001', ['remarks' => 'edited after abort'] + $o)->assertOk();
        $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-2610-001', ['remarks' => 'edited again'] + $o)->assertOk();
        $after = Order::first();
        $this->assertSame(1, $after->receipts->where('kind', 'refund')->count());
        $this->assertSame('req-same-0001', $after->receipts->firstWhere('kind', 'refund')->ref); // reference survives edits
        $this->assertEquals(30000, $this->statement()['received']);
        $this->assertSame('PROJECT ABORT', $after->statuses['comm']);
    }

    public function test_abort_requires_confirmation_whole_rupees_and_no_future_date(): void
    {
        $this->paidTwice();
        $this->abortWith(1000, 'req-v-00001', ['confirm' => false])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->abortWith(0, 'req-v-00002', ['refundAmount' => 1000.5])->assertStatus(422)->assertJsonValidationErrors('refundAmount');
        $this->abortWith(1000, 'req-v-00003', ['refundDate' => now()->addDays(3)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('refundDate');
        $this->abortWith(1000, 'bad id!')->assertStatus(422)->assertJsonValidationErrors('requestId');
        $this->assertFalse(Order::first()->isAborted());
    }

    public function test_rupee_formatting_uses_indian_grouping(): void
    {
        $this->assertSame('₹18,38,750', OrderManagement::rupees(1838750));
        $this->assertSame('₹1,000.50', OrderManagement::rupees(1000.5));
        $this->assertSame('₹999', OrderManagement::rupees(999));
        $this->assertSame('₹1,23,45,678.09', OrderManagement::rupees(12345678.09));
    }
}
