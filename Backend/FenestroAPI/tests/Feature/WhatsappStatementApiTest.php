<?php

namespace Tests\Feature;

use App\Models\OrderManagement\Order;
use App\Models\OrderManagement\StatementSend;
use App\Support\OrderManagement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Receivables / Archive → send a statement PDF + message through the WhatsApp Cloud API. */
class WhatsappStatementApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cns.whatsapp.test_recipient' => null, 'cns.whatsapp.statement_drafts_enabled' => true]);
        $admin = User::create(['name' => 'Admin', 'email' => 'a@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->token = auth('api')->login($admin);
    }

    private function order(string $code, string $customer, string $phone, array $paid): void
    {
        $this->withToken($this->token)->postJson('/api/admin/order-management/orders', [
            'id' => $code, 'customer' => $customer, 'project' => $customer.' SITE', 'phone' => $phone, 'confirmed' => '2026-10-02',
            'sft' => 100, 'rate' => 1000, 'tax' => 18, 'writtenOff' => 'No', 'remarks' => '', 'completedAt' => null,
            'receipts' => array_map(fn ($a, $i) => ['date' => '2026-10-0'.($i + 2), 'amount' => $a, 'notes' => 'PAY'], $paid, array_keys($paid)),
            'statuses' => ['comm' => 'QSHARE'],
        ])->assertCreated();
    }

    /**
     * A minimal "statement PDF" for one project: contains its ID, customer name
     * and invoice / received / outstanding figures, like the real one.
     */
    private function pdf(string $code = 'PV-2610-001', string $customer = 'NEHA KULKARNI', ?array $figures = null): UploadedFile
    {
        $o = Order::with('receipts')->where('code', $code)->first();
        $figures ??= $o ? [$o->invoiceAmount(), $o->receivedAmount(), $o->outstanding()] : [0, 0, 0];
        $text = implode(' ', array_map(fn ($f) => '('.OrderManagement::pdfAmount((float) $f).') Tj', $figures));

        return UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.3\nBT ($customer) Tj ($code) Tj $text ET\n%%EOF");
    }

    private function send(string $code, ?UploadedFile $file = null)
    {
        $customer = Order::where('code', $code)->value('customer') ?? 'X';

        return $this->withToken($this->token)->post("/api/admin/order-management/receivables/statements/$code/whatsapp", ['pdf' => $file ?? $this->pdf($code, $customer)], ['Accept' => 'application/json']);
    }

    private function configure(?string $template = null): void
    {
        config(['services.whatsapp.enabled' => true, 'services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '123456', 'services.whatsapp.api_version' => 'v21.0',
            'cns.whatsapp.statement_api.template' => $template]);
    }

    public function test_without_credentials_nothing_is_sent_and_the_manual_fallback_is_explained(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);
        Http::fake();
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);

        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->assertOk();
        $st->assertJsonPath('whatsapp_api.configured', false);
        $this->assertStringContainsString('WHATSAPP_TOKEN', $st->json('whatsapp_api.reason'));

        $this->send('PV-2610-001')->assertStatus(503)->assertJsonPath('accepted', false)->assertJsonPath('configured', false);
        Http::assertNothingSent();
    }

    public function test_configured_upload_then_document_message_to_the_projects_own_number(): void
    {
        $this->configure();
        Http::fake([
            'graph.facebook.com/v21.0/123456/media' => Http::response(['id' => 'MEDIA-1']),
            'graph.facebook.com/v21.0/123456/messages' => Http::response(['messages' => [['id' => 'wamid.ABC']]]),
        ]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $this->order('PV-2610-002', 'KARTHIK REDDY', '98765 00002', [100000]);

        $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])
            ->assertJsonPath('whatsapp_api.configured', true)->assertJsonPath('whatsapp_api.mode', 'document');

        $this->send('PV-2610-002')->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('message_id', 'wamid.ABC')
            ->assertJsonPath('to', '919876500002')
            ->assertJsonPath('filename', 'Statement_PV-2610-002_KARTHIK_REDDY.pdf');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/media') && $r->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }
            $d = $r->data();

            return $d['to'] === '919876500002' && $d['type'] === 'document'
                && $d['document']['id'] === 'MEDIA-1'
                && $d['document']['filename'] === 'Statement_PV-2610-002_KARTHIK_REDDY.pdf'
                && str_starts_with($d['document']['caption'], "Dear KARTHIK REDDY,\n\nGreetings from Prestige Ventures.")
                && str_contains($d['document']['caption'], '(Project ID: PV-2610-002)')
                && str_contains($d['document']['caption'], 'Outstanding Balance: ₹18,000')
                && ! str_contains($d['document']['caption'], 'Neha');
        });
    }

    public function test_template_mode_sends_the_pdf_as_the_document_header_with_project_values(): void
    {
        $this->configure('payment_statement');
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-9']),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.T1']]]),
        ]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);

        $this->send('PV-2610-001')->assertOk()->assertJsonPath('accepted', true);
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }
            $t = $r->data()['template'];

            return $r->data()['to'] === '919876500001' && $t['name'] === 'payment_statement'
                && $t['components'][0]['parameters'][0]['document']['id'] === 'MEDIA-9'
                && array_column($t['components'][1]['parameters'], 'text') === ['NEHA KULKARNI', 'NEHA KULKARNI SITE', '₹1,18,000', '₹30,000', '₹88,000'];
        });
    }

    public function test_api_rejection_is_reported_and_never_claimed_as_sent(): void
    {
        $this->configure();
        Http::fake([
            '*/media' => Http::response(['id' => 'MEDIA-1']),
            '*/messages' => Http::response(['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 400),
        ]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);

        $res = $this->send('PV-2610-001')->assertStatus(502)->assertJsonPath('accepted', false)->assertJsonPath('message_id', null);
        $this->assertStringContainsString('Re-engagement message', $res->json('message'));
        $this->assertStringContainsString('131047', $res->json('message'));
    }

    public function test_invalid_phone_demo_record_and_non_pdf_are_refused_before_calling_whatsapp(): void
    {
        $this->configure();
        Http::fake();
        $this->order('PV-2610-001', 'BAD PHONE', '12345', [10000]);
        $this->order('PV-2610-002', 'DEMO CUSTOMER', '9000000101', [10000]);
        Order::where('code', 'PV-2610-002')->update(['code' => 'PV-DEMO-001']); // demo records are made by orders:demo, not the API
        $this->order('PV-2610-003', 'GOOD PHONE', '9876500003', [10000]);

        $this->assertStringContainsString('no valid 10-digit mobile number', $this->send('PV-2610-001')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('Demo record', $this->send('PV-DEMO-001')->assertStatus(422)->json('message'));
        $this->send('PV-2610-003', UploadedFile::fake()->createWithContent('x.pdf', 'not a pdf'))->assertStatus(422);
        $this->send('PV-9999-999')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_pdf_for_another_project_is_refused_before_sending(): void
    {
        $this->configure();
        Http::fake();
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $this->order('PV-2610-002', 'KARTHIK REDDY', '9876500002', [30000]);

        // Neha's statement uploaded for Karthik's project → refused, nothing sent.
        $res = $this->send('PV-2610-002', $this->pdf('PV-2610-001', 'NEHA KULKARNI'))->assertStatus(422)->assertJsonPath('accepted', false);
        $this->assertStringContainsString('does not match project PV-2610-002', $res->json('message'));
        // Right ID but wrong customer name → refused as well.
        $this->send('PV-2610-002', $this->pdf('PV-2610-002', 'NEHA KULKARNI'))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_second_send_while_one_is_in_progress_is_refused_and_nothing_is_sent(): void
    {
        $this->configure();
        Http::fake(['*/media' => Http::response(['id' => 'M1']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $id = Order::where('code', 'PV-2610-001')->value('id');

        // Another request is sending this project's statement right now (it holds the lock).
        $lock = \Illuminate\Support\Facades\Cache::lock('statement-send:'.$id, 120);
        $this->assertTrue($lock->get());
        try {
            $this->send('PV-2610-001')->assertStatus(409)->assertJsonPath('accepted', false);
            Http::assertNothingSent();
            $this->assertSame(0, StatementSend::count());
        } finally {
            $lock->release();
        }
        // Once it is free, the send goes through normally.
        $this->send('PV-2610-001')->assertOk()->assertJsonPath('accepted', true);
    }

    public function test_retry_after_success_is_not_sent_again_but_retry_after_failure_is(): void
    {
        $this->configure();
        Http::fakeSequence('*/media')->push(['id' => 'M1'])->push(['id' => 'M2'])->whenEmpty(Http::response(['id' => 'M9']));
        Http::fakeSequence('*/messages')
            ->push(['error' => ['message' => 'Temporary failure', 'code' => 131000]], 500) // 1st try fails
            ->push(['messages' => [['id' => 'wamid.OK']]])                                 // retry succeeds
            ->whenEmpty(Http::response(['messages' => [['id' => 'wamid.NEW']]]));
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);

        $this->send('PV-2610-001')->assertStatus(502)->assertJsonPath('accepted', false);
        $this->send('PV-2610-001')->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('duplicate', false)->assertJsonPath('message_id', 'wamid.OK');
        // Third click: same statement already accepted → not sent again.
        $this->send('PV-2610-001')->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('duplicate', true)->assertJsonPath('message_id', 'wamid.OK');

        Http::assertSentCount(4); // (upload + message) × 2 — nothing for the third click
        $this->assertSame(['failed', 'accepted'], \App\Models\OrderManagement\StatementSend::orderBy('id')->pluck('status')->all());

        // A new payment changes the statement → it can be sent again.
        $o = Order::where('code', 'PV-2610-001')->first();
        $o->receipts()->create(['date' => '2026-10-20', 'amount' => 1000, 'notes' => 'NEW', 'position' => 1]);
        $this->send('PV-2610-001')->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('message_id', 'wamid.NEW');
    }

    public function test_fully_paid_projects_get_the_thank_you_wording_without_a_payment_request(): void
    {
        $this->configure();
        Http::fake(['*/media' => Http::response(['id' => 'M1']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.P']]])]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [118000]);
        $this->send('PV-2610-001')->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages')
            && str_contains($r->data()['document']['caption'], 'Outstanding Balance: ₹0') && str_contains($r->data()['document']['caption'], 'fully paid') && ! preg_match('/kindly (pay|arrange)/i', $r->data()['document']['caption']));
    }

    public function test_aborted_projects_are_sent_with_closing_wording_that_asks_for_nothing(): void
    {
        $this->configure();
        Http::fake(['*/media' => Http::response(['id' => 'M1']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.A']]])]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [20000]);
        $o = Order::where('code', 'PV-2610-001')->first();
        $o->update(['statuses' => ['comm' => 'PROJECT ABORT'] + $o->statuses, 'completed_at' => now()]);

        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0');
        $this->assertTrue($st['aborted']);
        $this->assertSame('PROJECT ABORT', $st['project_status']);
        $this->assertSame('ADVANCE RECEIVED', $st['payment_status']); // never marked fully paid because it was aborted
        $this->assertEquals(0, $st['outstanding']);                    // abort closes the account at ₹0
        $this->assertEquals(20000, $st['received']);                   // retained, no refund invented

        $this->send('PV-2610-001')->assertOk(); // archived/aborted projects are not blocked
        Http::assertSent(function (HttpRequest $r) {
            if (! str_ends_with($r->url(), '/messages')) {
                return false;
            }
            $c = $r->data()['document']['caption'];

            return str_contains($c, 'This project has been closed.') && str_contains($c, 'Net Amount Retained: ₹20,000') && str_contains($c, 'Total Refunded: ₹0') && str_contains($c, 'Balance After Abort: ₹0')
                && ! str_contains($c, 'Outstanding Balance') && ! preg_match('/arrange|kindly pay|please pay/i', $c);
        });
    }

    public function test_a_pdf_with_stale_figures_is_refused_before_sending(): void
    {
        $this->configure();
        Http::fake();
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        // Drawn before a payment was added: right project, wrong (old) figures.
        $this->send('PV-2610-001', $this->pdf('PV-2610-001', 'NEHA KULKARNI', [118000, 20000, 98000]))
            ->assertStatus(409)->assertJsonPath('accepted', false);
        Http::assertNothingSent();
    }

    public function test_statement_ledger_has_running_balances_from_the_stored_payments(): void
    {
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000, 25000, 15000]);
        $st = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0');
        $this->assertSame(
            [['Invoice', 118000.0, null, 118000.0], ['Advance received', null, 30000.0, 88000.0], ['Payment received (1)', null, 25000.0, 63000.0], ['Payment received (2)', null, 15000.0, 48000.0]],
            array_map(fn ($r) => [$r['transaction'], $r['amount'] === null ? null : (float) $r['amount'], $r['payment'] === null ? null : (float) $r['payment'], (float) $r['balance']], $st['ledger'])
        );
        $this->assertSame('100 SFT x Rs. 1,000.00 + 18% GST', $st['ledger'][0]['details']);
        $this->assertEquals($st['outstanding'], end($st['ledger'])['balance']); // ledger always ends at the outstanding amount
        $this->assertSame('QSHARE', $st['project_status']);
        $this->assertSame('Rs. 1,18,000.00', OrderManagement::pdfAmount(118000));
        $this->assertSame('Rs. 0.00', OrderManagement::pdfAmount(0));
        $this->assertSame('Rs. 20,000.50', OrderManagement::pdfAmount(20000.5));
    }

    public function test_written_off_balance_appears_as_an_adjustment_ending_at_zero(): void
    {
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [100000]);
        Order::where('code', 'PV-2610-001')->update(['written_off' => 'Yes']);
        $ledger = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements', ['ids' => ['PV-2610-001']])->json('statements.0.ledger');
        $last = end($ledger);
        $this->assertSame(['Adjustment', -18000.0, 0.0], [$last['transaction'], (float) $last['amount'], (float) $last['balance']]);
    }

    // ── Delivery status (webhook) ──

    private function signed(array $body, string $secret = 'app-secret-test')
    {
        $json = json_encode($body);

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, $secret),
        ], $json);
    }

    private function statusEvent(string $id, string $status, array $extra = []): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => $id, 'status' => $status, 'timestamp' => '1792650000'] + $extra]]]]]]];
    }

    public function test_webhook_handshake_needs_the_configured_verify_token(): void
    {
        config(['services.whatsapp.verify_token' => null]);
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=&hub.challenge=123')->assertForbidden();
        config(['services.whatsapp.verify_token' => 'verify-me']);
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123')->assertForbidden();
        $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=123')->assertOk()->assertSee('123');
    }

    public function test_webhook_records_delivery_status_only_with_a_valid_signature(): void
    {
        $this->configure();
        config(['services.whatsapp.app_secret' => 'app-secret-test', 'services.whatsapp.verify_token' => 'verify-me']);
        Http::fake(['*/media' => Http::response(['id' => 'M1']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.D1']]])]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $this->send('PV-2610-001')->assertOk();

        $this->signed($this->statusEvent('wamid.D1', 'delivered'), 'wrong-secret')->assertForbidden();
        $this->assertNull(StatementSend::first()->delivery_status);

        $this->signed($this->statusEvent('wamid.D1', 'delivered'))->assertOk()->assertJsonPath('updated', 1);
        $this->signed($this->statusEvent('wamid.D1', 'read'))->assertOk();
        $this->signed($this->statusEvent('wamid.D1', 'sent'))->assertOk()->assertJsonPath('updated', 0); // late "sent" never moves it back
        $this->signed($this->statusEvent('wamid.OTHER', 'read'))->assertOk()->assertJsonPath('updated', 0);
        $this->assertSame('read', StatementSend::first()->delivery_status);

        $res = $this->withToken($this->token)->postJson('/api/admin/order-management/receivables/statements/deliveries', ['message_ids' => ['wamid.D1']])->assertOk();
        $res->assertJsonPath('tracking', true)->assertJsonPath('data.0.status', 'read');
        $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status')->assertJsonPath('delivery_tracking', true);
    }

    public function test_webhook_reports_a_delivery_failure_with_its_reason(): void
    {
        $this->configure();
        config(['services.whatsapp.app_secret' => 'app-secret-test', 'services.whatsapp.verify_token' => 'verify-me']);
        Http::fake(['*/media' => Http::response(['id' => 'M1']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.F1']]])]);
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $this->send('PV-2610-001')->assertOk();
        $this->signed($this->statusEvent('wamid.F1', 'failed', ['errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]))->assertOk();
        $s = StatementSend::first();
        $this->assertSame(['failed', 'Message undeliverable (code 131026)'], [$s->delivery_status, $s->delivery_error]);
    }

    public function test_without_webhook_settings_delivery_is_reported_as_not_tracked(): void
    {
        config(['services.whatsapp.app_secret' => null]);
        $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status')->assertJsonPath('delivery_tracking', false);
        $this->signed($this->statusEvent('wamid.X', 'read'), '')->assertForbidden();
    }

    public function test_status_endpoint_lists_missing_settings_without_calling_whatsapp(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);
        Http::fake();
        $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status?live=1')->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('missing', ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID'])
            ->assertJsonPath('live.ok', false);
        Http::assertNothingSent();
    }

    public function test_live_connection_check_reads_the_phone_number_and_template_status(): void
    {
        $this->configure('payment_statement');
        config(['services.whatsapp.business_account_id' => 'WABA1']);
        Http::fake([
            'graph.facebook.com/v21.0/123456*' => Http::response(['display_phone_number' => '+91 84314 74741', 'verified_name' => 'Prestige Ventures', 'quality_rating' => 'GREEN', 'id' => '123456']),
            'graph.facebook.com/v21.0/WABA1/message_templates*' => Http::response(['data' => [['name' => 'payment_statement', 'status' => 'APPROVED', 'language' => 'en', 'category' => 'UTILITY']]]),
        ]);
        $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status?live=1')->assertOk()
            ->assertJsonPath('configured', true)->assertJsonPath('missing', [])
            ->assertJsonPath('live.ok', true)
            ->assertJsonPath('live.number', '+91 84314 74741')
            ->assertJsonPath('live.name', 'Prestige Ventures')
            ->assertJsonPath('live.template.status', 'APPROVED');
        Http::assertNotSent(fn (HttpRequest $r) => $r->method() === 'POST'); // read-only
    }

    public function test_live_check_reports_a_rejected_token(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);
        $res = $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status?live=1')->assertOk()->assertJsonPath('live.ok', false);
        $this->assertStringContainsString('Invalid OAuth access token', $res->json('live.error'));
    }

    public function test_artisan_check_command(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);
        $this->artisan('whatsapp:check')->expectsOutputToContain('Not configured: set WHATSAPP_TOKEN and WHATSAPP_PHONE_NUMBER_ID')->assertFailed();

        $this->configure();
        Http::fake([
            '*/123456?*' => Http::response(['display_phone_number' => '+91 84314 74741', 'verified_name' => 'Prestige Ventures', 'quality_rating' => 'GREEN']),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.HELLO']]]),
        ]);
        $this->artisan('whatsapp:check', ['--to' => '9876500009'])->expectsOutputToContain('Connected to WhatsApp number +91 84314 74741')->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages') && $r->data()['to'] === '919876500009'
            && $r->data()['template']['name'] === 'hello_world' && $r->data()['template']['language']['code'] === 'en_US');
    }

    public function test_nothing_is_sent_while_sending_is_switched_off_even_with_credentials(): void
    {
        $this->configure();
        config(['services.whatsapp.enabled' => false]);
        Http::fake();
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $this->send('PV-2610-001')->assertStatus(503)->assertJsonPath('accepted', false);
        $this->assertFalse(app(\App\Services\WhatsAppService::class)->sendTemplate('9876500001', 'fenestro_new_lead'));
        (new \App\Jobs\SendLeadAlertJob())->handle(app(\App\Services\WhatsAppService::class)); // lead alert: no request
        Http::assertNothingSent();
        $this->withToken($this->token)->getJson('/api/admin/order-management/whatsapp/status')
            ->assertJsonPath('configured', false)
            ->assertJsonPath('reason', 'Automatic WhatsApp sending is switched off on this server (WHATSAPP_SENDING_ENABLED=false).');
    }

    public function test_status_endpoint_is_admin_only(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->getJson('/api/admin/order-management/whatsapp/status')->assertStatus(401);
    }

    public function test_sending_is_admin_only(): void
    {
        $this->configure();
        Http::fake();
        $this->order('PV-2610-001', 'NEHA KULKARNI', '9876500001', [30000]);
        $partner = User::create(['name' => 'P', 'phone' => '9000000002', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->withToken(auth('api')->login($partner))->post('/api/admin/order-management/receivables/statements/PV-2610-001/whatsapp', ['pdf' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(403);
        Http::assertNothingSent();
        $this->assertSame(1, Order::count());
    }
}
