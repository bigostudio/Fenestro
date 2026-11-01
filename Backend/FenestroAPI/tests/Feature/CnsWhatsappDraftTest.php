<?php

namespace Tests\Feature;

use App\Models\OrderManagement\Order;
use App\Models\User;
use App\Support\OrderManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PROJ STATUS COMM drafts (10 approved messages, 2026-10-21). */
class CnsWhatsappDraftTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private const SIGN_CS = "\n\nThanks,\nPrestige Ventures – Customer Support\n(A uPVC Windows & Doors Manufacturing Company)"; // cns.whatsapp.signature

    protected function setUp(): void
    {
        parent::setUp();
        config(['cns.whatsapp.test_recipient' => null, 'cns.whatsapp.google_review_url' => null]);
        $admin = User::create(['name' => 'Admin', 'email' => 'a@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->token = auth('api')->login($admin);
    }

    private function statuses(array $overrides = []): array
    {
        $out = [];
        foreach (config('cns.statuses') as $key => $s) {
            if ($s['enabled'] ?? true) {
                $out[$key] = $s['default'];
            }
        }

        return array_merge($out, $overrides);
    }

    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 'PV-2610-001', 'customer' => 'ANAND RAO', 'project' => 'LAKE HOUSE', 'phone' => '9876543210',
            'confirmed' => '2026-10-02', 'installationDate' => '2026-11-15', 'sft' => 100, 'rate' => 1000, 'tax' => 18,
            'receipts' => [
                ['date' => '2026-10-02', 'amount' => 35400, 'notes' => 'ADVANCE'],
                ['date' => '2026-10-12', 'amount' => 20000, 'notes' => 'PART'],
            ],
            'writtenOff' => 'No', 'remarks' => '', 'statuses' => $this->statuses(), 'completedAt' => null,
        ], $overrides);
    }

    private function create(array $overrides = []): void
    {
        $this->withToken($this->token)->postJson('/api/admin/order-management/orders', $this->order($overrides))->assertCreated();
    }

    private function saveStatuses(string $code, array $statusOverrides, array $orderOverrides = [])
    {
        return $this->withToken($this->token)->putJson(
            "/api/admin/order-management/orders/$code",
            $this->order(['id' => $code, 'statuses' => $this->statuses($statusOverrides)] + $orderOverrides)
        );
    }

    private function draftFor(string $value, array $orderOverrides = [], string $code = 'PV-2610-001'): array
    {
        return $this->saveStatuses($code, ['comm' => $value], $orderOverrides)->assertOk()->json('whatsapp_drafts.0') ?? [];
    }

    public function test_comm_dropdown_offers_exactly_the_12_options(): void
    {
        $data = $this->withToken($this->token)->getJson('/api/admin/order-management/data')->json('config');
        $cfg = collect($data['statuses'])->keyBy('key');
        $this->assertSame(['QSHARE', 'ADV RCVD', 'PART PAY RCVD', 'FULL PAY RCVD', 'MATRL ARIVD', 'PROD STRTD', 'INST STRTD', 'HAND OVER', 'FEEDBACK REQ', 'INSTAL DELAY', 'YET TO SHARE', 'PROJECT COMPLETED', 'PROJECT ABORT'], $cfg['comm']['options']);
        // NO is only the stored "no status yet" value (shown as a placeholder), never an option.
        $this->assertSame('YET TO SHARE', $cfg['comm']['def']);
        $this->assertSame(['NO', 'YES', 'YET TO START'], $cfg['comm']['legacy']); // kept on older records, never offered
        $this->assertSame(['NO' => 'YET TO SHARE', 'YET TO START' => 'YET TO SHARE'], (array) $cfg['comm']['legacy_labels']);
        $this->assertNotContains('YET TO SHARE', $cfg['comm']['done']);
        $this->assertNotContains('YET TO START', $cfg['comm']['done']);   // red by default
        $this->assertNotContains('NO', $cfg['comm']['done']);         // NO is red (pending)
        $this->assertContains('QSHARE', $cfg['comm']['done']);        // communication steps are green
        $this->assertContains('HAND OVER', $cfg['comm']['done']);
        $this->assertSame(['PROJECT COMPLETED', 'PROJECT ABORT'], $data['completion_values']);
        $this->assertSame(['PROJECT ABORT'], $data['archive_values']);
        $this->assertSame(96, $data['comm_fresh_hours']);
        // The 8 workbook statuses; PRODUCTION STATUS removed (PROD HARDWARE AVL / PRODUCTION DOC SHARED stay).
        $this->assertSame(['comm', 'profile', 'sizeqc', 'glass', 'prodHardware', 'installHardware', 'docs', 'exceptions'], $cfg->keys()->all());
    }

    public function test_each_approved_message_with_real_order_values(): void
    {
        config(['cns.whatsapp.google_review_url' => 'https://g.page/r/example-review']);
        $this->create();
        $expected = [
            'QSHARE'        => "Dear ANAND RAO,\n\nYour quotation has been shared. We look forward to your confirmation.".self::SIGN_CS,
            'ADV RCVD'      => "Dear ANAND RAO,\n\nYour advance payment of amount ₹35,400 towards uPVC window/doors for project LAKE HOUSE has been received.".self::SIGN_CS,
            'PART PAY RCVD' => "Dear ANAND RAO,\n\nPayment of amount ₹20,000 has been received. Please find your updated ledger statement.".self::SIGN_CS,
            'FULL PAY RCVD' => "Dear ANAND RAO,\n\nFull payment received towards your project LAKE HOUSE. Please find your final ledger statement.".self::SIGN_CS,
            'MATRL ARIVD'   => "Dear ANAND RAO,\n\nMaterial for your project LAKE HOUSE has arrived. Production will begin shortly.".self::SIGN_CS,
            'PROD STRTD'    => "Dear ANAND RAO,\n\nProduction has started for your LAKE HOUSE project. Expected installation date: 15 Nov 2026.".self::SIGN_CS,
            'INST STRTD'    => "Dear ANAND RAO,\n\nInstallation of uPVC windows/doors has started for your project LAKE HOUSE.".self::SIGN_CS,
            'HAND OVER'     => "Dear ANAND RAO,\n\nInstallation and project LAKE HOUSE handover are complete.\n\nThank you for choosing us!".self::SIGN_CS,
            'FEEDBACK REQ'  => "Dear ANAND RAO,\n\nWe value your feedback. Kindly share your experience on: https://g.page/r/example-review\n\nThanks,\nPrestige Ventures – Customer Support\n(A uPVC Windows & Doors Manufacturing Company)",
            'INSTAL DELAY'  => "Dear ANAND RAO,\n\nPlease confirm your installation date at the earliest. Delays affect our planned schedules and financial commitments.".self::SIGN_CS,
        ];
        foreach ($expected as $value => $text) {
            $this->saveStatuses('PV-2610-001', ['comm' => 'NO'])->assertOk(); // back to NO so each pick is a change
            $d = $this->draftFor($value);
            $this->assertSame($text, $d['text'], $value);
            $this->assertSame('919876543210', $d['phone'], $value);
            $this->assertSame('https://wa.me/919876543210?text='.rawurlencode($text), $d['url'], $value);
            $this->assertSame([], $d['missing']);
            $this->assertStringNotContainsString('{', $d['text']);
        }
    }

    public function test_missing_values_produce_no_text_and_say_what_is_missing(): void
    {
        $this->create(['receipts' => [], 'installationDate' => null]);
        foreach ([
            'ADV RCVD'      => ['advance_amount', 'No advance receipt'],
            'PART PAY RCVD' => ['payment_amount', 'No payment receipt'],
            'PROD STRTD'    => ['installation_date', 'expected installation date is not set'],
            'FEEDBACK REQ'  => ['google_review_link', 'Google review link is not configured'],
        ] as $value => [$field, $msg]) {
            $this->saveStatuses('PV-2610-001', ['comm' => 'NO'], ['receipts' => [], 'installationDate' => null])->assertOk();
            $d = $this->draftFor($value, ['receipts' => [], 'installationDate' => null]);
            $this->assertNull($d['text'], $value);
            $this->assertNull($d['url'], $value);
            $this->assertSame([$field], $d['missing'], $value);
            $this->assertStringContainsString($msg, $d['reason'], $value);
            // The status itself is still saved.
            $this->assertSame($value, Order::where('code', 'PV-2610-001')->first()->statuses['comm']);
        }
    }

    public function test_setting_the_installation_date_then_returns_the_prod_strtd_draft(): void
    {
        $this->create(['installationDate' => null]);
        $this->assertSame(['installation_date'], $this->draftFor('PROD STRTD', ['installationDate' => null])['missing']);

        $res = $this->withToken($this->token)->postJson('/api/admin/order-management/orders/PV-2610-001/installation-date', ['installationDate' => '2026-12-01'])
            ->assertOk()->assertJsonPath('data.installationDate', '2026-12-01');
        $this->assertStringContainsString('Expected installation date: 01 Dec 2026.', $res->json('whatsapp_drafts.0.text'));

        $this->withToken($this->token)->postJson('/api/admin/order-management/orders/PV-2610-001/installation-date', ['installationDate' => 'soon'])->assertStatus(422);
    }

    public function test_installation_date_is_kept_when_an_older_dashboard_does_not_send_it(): void
    {
        $this->create();
        $payload = $this->order(['statuses' => $this->statuses(['glass' => 'AVAILABLE'])]);
        unset($payload['installationDate']);
        $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-2610-001', $payload)->assertOk();
        $this->assertSame('2026-11-15', Order::first()->installation_date->format('Y-m-d'));
    }

    public function test_no_drafts_from_other_statuses_or_unchanged_values(): void
    {
        $this->create();
        // PRODUCTION STATUS no longer opens a draft (PROD STRTD is the single production message).
        foreach (['PRODUCTION STARTED', 'PRODUCTION COMPLETED'] as $v) {
            $this->saveStatuses('PV-2610-001', ['production' => $v])->assertOk()->assertJsonCount(0, 'whatsapp_drafts');
        }
        // None of the other 7 workbook statuses opens a draft.
        foreach (['profile', 'sizeqc', 'glass', 'prodHardware', 'installHardware', 'docs', 'exceptions'] as $k) {
            foreach (config("cns.statuses.$k.options") as $v) {
                $this->saveStatuses('PV-2610-001', [$k => $v])->assertOk()->assertJsonCount(0, 'whatsapp_drafts');
            }
        }
        // NO, PROJECT COMPLETED and legacy YES: no draft.
        foreach (['PROJECT COMPLETED', 'PROJECT ABORT', 'NO', 'YES'] as $v) {
            $this->saveStatuses('PV-2610-001', ['comm' => $v])->assertOk()->assertJsonCount(0, 'whatsapp_drafts');
        }
        // Same value again → no duplicate draft.
        $this->saveStatuses('PV-2610-001', ['comm' => 'QSHARE'])->assertJsonCount(1, 'whatsapp_drafts');
        $this->saveStatuses('PV-2610-001', ['comm' => 'QSHARE'], ['remarks' => 'edited'])->assertJsonCount(0, 'whatsapp_drafts');
        $this->assertSame(['comm'], array_keys(config('cns.whatsapp.templates')));
    }

    public function test_legacy_yes_is_accepted_and_kept_until_changed(): void
    {
        $this->create(['statuses' => $this->statuses(['comm' => 'YES'])]);
        $this->assertSame('YES', Order::first()->statuses['comm']);
        $this->withToken($this->token)->getJson('/api/admin/order-management/data')->assertJsonPath('orders.0.statuses.comm', 'YES');
        $this->saveStatuses('PV-2610-001', ['comm' => 'MAYBE'])->assertStatus(422);
    }

    public function test_invalid_phone_saves_but_explains_no_draft(): void
    {
        foreach (['', '12345', '1234567890'] as $i => $phone) {
            $code = 'PV-2610-00'.($i + 2);
            $this->create(['id' => $code, 'phone' => $phone]);
            $d = $this->draftFor('QSHARE', ['phone' => $phone], $code);
            $this->assertNull($d['url']);
            $this->assertStringContainsString('no valid 10-digit mobile number', $d['reason']);
        }
    }

    public function test_unknown_order_and_non_admin_are_rejected(): void
    {
        $this->saveStatuses('PV-9999-999', ['comm' => 'QSHARE'])->assertNotFound();

        $this->create();
        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $pt = auth('api')->login($partner);
        $this->withToken($pt)->putJson('/api/admin/order-management/orders/PV-2610-001', $this->order(['statuses' => $this->statuses(['comm' => 'QSHARE'])]))->assertStatus(403);
        $this->withToken($pt)->postJson('/api/admin/order-management/orders/PV-2610-001/installation-date', ['installationDate' => '2026-12-01'])->assertStatus(403);
        $this->assertSame('YET TO SHARE', Order::first()->statuses['comm']); // unchanged default
    }

    public function test_test_recipient_overrides_every_customer_number(): void
    {
        config(['cns.whatsapp.test_recipient' => '9000000999']);
        $this->create();
        $d = $this->draftFor('QSHARE');
        $this->assertSame('919000000999', $d['phone']);
        $this->assertTrue($d['test_recipient']);
    }

    public function test_switched_off_column_keeps_its_saved_value(): void
    {
        $this->create();
        $this->saveStatuses('PV-2610-001', ['dispatch' => 'DISPATCHED'])->assertOk();
        $this->saveStatuses('PV-2610-001', ['glass' => 'AVAILABLE'])->assertOk();
        $this->assertSame('DISPATCHED', Order::first()->statuses['dispatch']);
    }

    // ── Demo data ─────────────────────────────────────────────────────

    public function test_demo_command_is_idempotent_and_never_touches_real_orders(): void
    {
        $this->create();
        $this->artisan('orders:demo')->assertSuccessful();
        $this->artisan('orders:demo')->assertSuccessful();

        $this->assertSame(15, Order::where('code', 'like', 'PV-DEMO-%')->count());
        $this->assertSame(16, Order::count());
        $demo = Order::with('receipts')->where('code', 'like', 'PV-DEMO-%')->get()->keyBy('code');
        $this->assertCount(0, $demo['PV-DEMO-001']->receipts);                     // nothing received yet
        $this->assertCount(2, $demo['PV-DEMO-003']->receipts);                     // advance + part payment
        $this->assertSame(0.0, $demo['PV-DEMO-007']->outstanding());              // fully paid
        $this->assertNull($demo['PV-DEMO-006']->installation_date);                // PROD STRTD will ask for it
        $this->assertSame('YES', $demo['PV-DEMO-010']->statuses['comm']);          // legacy value example
        // Aborted examples: full / partial / zero refund, each closing at ₹0; refunds counted once.
        $this->assertSame(['FULL REFUND', 'PARTIAL REFUND', 'NO REFUND'], [$demo['PV-DEMO-011']->refundStatus(), $demo['PV-DEMO-012']->refundStatus(), $demo['PV-DEMO-013']->refundStatus()]);
        $this->assertEquals([0, 120000, 100000], [$demo['PV-DEMO-011']->receivedAmount(), $demo['PV-DEMO-012']->receivedAmount(), $demo['PV-DEMO-013']->receivedAmount()]);
        $this->assertEquals([0, 0, 0], [$demo['PV-DEMO-011']->outstanding(), $demo['PV-DEMO-012']->outstanding(), $demo['PV-DEMO-013']->outstanding()]);
        $this->assertCount(0, $demo['PV-DEMO-014']->receipts);                     // disposable: deletable

        // Demo profile codes: two shortages, one at quantity 0, two in stock; a real profile is left alone.
        \App\Models\OrderManagement\InventoryProfile::create(['code' => 'REAL-PROFILE-1', 'item' => 'REAL', 'base_stock' => 5, 'stock' => 5, 'unit_cost' => 10]);
        $profiles = \App\Models\OrderManagement\InventoryProfile::with('usages')->where('code', 'like', 'PV-DEMO-PROFILE-%')->get()->keyBy('code');
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $profiles->keys()->map(fn ($c) => substr($c, -1))->sort()->values()->all());
        $current = $profiles->map(fn ($p) => $p->base_stock - $p->usages->sum('qty'));
        $this->assertEquals(['PV-DEMO-PROFILE-A' => -4, 'PV-DEMO-PROFILE-B' => -3, 'PV-DEMO-PROFILE-C' => 0, 'PV-DEMO-PROFILE-D' => 10, 'PV-DEMO-PROFILE-E' => 25], $current->sortKeys()->all());
        $this->assertTrue($profiles->every(fn ($p) => str_starts_with($p->item, '[DEMO] ')));

        $this->artisan('orders:demo', ['--remove' => true])->assertSuccessful();
        $this->assertSame(1, Order::count());
        $this->assertSame(['REAL-PROFILE-1'], \App\Models\OrderManagement\InventoryProfile::pluck('code')->all());
    }

    public function test_demo_command_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->artisan('orders:demo')->assertFailed();
        $this->assertSame(0, Order::count());
        $this->app['env'] = 'testing';
    }

    public function test_demo_order_drafts_never_use_the_stored_number(): void
    {
        $this->artisan('orders:demo')->assertSuccessful();
        $o = Order::with('receipts')->where('code', 'PV-DEMO-006')->first();
        $res = $this->withToken($this->token)->putJson('/api/admin/order-management/orders/PV-DEMO-006', [
            'id' => 'PV-DEMO-006', 'customer' => $o->customer, 'project' => $o->project, 'phone' => $o->phone,
            'confirmed' => $o->confirmed->format('Y-m-d'), 'sft' => $o->sft, 'rate' => $o->rate, 'tax' => $o->tax,
            'receipts' => $o->receipts->map(fn ($r) => ['date' => $r->date->format('Y-m-d'), 'amount' => $r->amount, 'notes' => $r->notes])->all(),
            'writtenOff' => 'No', 'remarks' => '', 'statuses' => array_merge($o->statuses, ['comm' => 'MATRL ARIVD']),
        ])->assertOk();
        $d = $res->json('whatsapp_drafts.0');
        $this->assertTrue($d['demo']);
        $this->assertNull($d['phone']);
        $this->assertStringStartsWith('https://wa.me/?text=', $d['url']);
        $this->assertStringNotContainsString('9000000106', $d['url']);
    }

    public function test_signature_is_added_exactly_once_and_replaces_an_older_sign_off(): void
    {
        $sig = trim(self::SIGN_CS);
        $this->assertSame("Hello\n\n".$sig, OrderManagement::withSignature('Hello'));
        $this->assertSame("Hello\n\n".$sig, OrderManagement::withSignature("Hello\n\n".$sig."\n")); // already signed
        $this->assertSame("Hello\n\n".$sig, OrderManagement::withSignature("Hello\n\nRegards,\nPrestige Ventures - Admin"));
        $this->assertSame("Hello\n\nThank you for choosing us!\n\n".$sig, OrderManagement::withSignature("Hello\n\nThank you for choosing us!\nPrestige Ventures – Customer Support"));
        // A customised template that still carries an old sign-off gets one signature, not two.
        config(['cns.whatsapp.templates.comm.QSHARE' => "Dear {customer_name},\n\nQuote shared.\n\nRegards,\nPrestige Ventures – Customer Support"]);
        $this->create();
        $this->assertSame("Dear ANAND RAO,\n\nQuote shared.".self::SIGN_CS, $this->draftFor('QSHARE')['text']);
    }

    public function test_every_draft_type_ends_with_the_signature_once(): void
    {
        config(['cns.whatsapp.google_review_url' => 'https://g.page/r/CSU2H9tOGNG0EBE/review']);
        $this->create();
        $texts = [];
        foreach (array_keys(config('cns.whatsapp.templates.comm')) as $value) {
            $this->saveStatuses('PV-2610-001', ['comm' => 'NO'])->assertOk();
            $texts[$value] = $this->draftFor($value)['text'];
        }
        $order = Order::with('receipts')->first();
        $texts['statement'] = OrderManagement::statementDraft($order)['text'];
        $texts['reminder'] = OrderManagement::reminderDraft($order)['text'];
        foreach ($texts as $kind => $text) {
            $this->assertStringEndsWith(self::SIGN_CS, $text, $kind);
            $this->assertSame(1, substr_count($text, 'Prestige Ventures – Customer Support'), $kind);
            $this->assertSame(1, substr_count($text, '(A uPVC Windows & Doors Manufacturing Company)'), $kind);
            $this->assertStringNotContainsString('Regards', $text, $kind);
        }
        $this->assertStringContainsString('https://g.page/r/CSU2H9tOGNG0EBE/review', $texts['FEEDBACK REQ']);
        foreach ($texts as $kind => $text) {
            if ($kind !== 'FEEDBACK REQ') {
                $this->assertStringNotContainsString('g.page', $text, $kind); // review link only in the feedback request
            }
        }
    }
}
