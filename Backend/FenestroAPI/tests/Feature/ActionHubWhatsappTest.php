<?php

namespace Tests\Feature;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\ActionHub\Message;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\ActionHubWhatsapp;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Action Hub service tickets + WhatsApp "request received" message.
 * Submit saves only (ticket, no message); Send WhatsApp is a separate request
 * (POST /actions/{id}/whatsapp with the confirmed ticket number) — requested 2026-11-01.
 * The WhatsApp Cloud API is faked; no request leaves the test (preventStrayRequests).
 */
class ActionHubWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const API = 'https://graph.test/v21.0/PHONE-ID/messages';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'action_hub.timezone' => 'Asia/Kolkata', 'action_hub.weekly_off' => [0], 'action_hub.get_ready_working_days' => 2,
            'action_hub.whatsapp.templates.request_received.meta' => null, 'action_hub.whatsapp.allow_session_text' => true, // plain text (24-hour window); the template rule has its own test
            'services.whatsapp.enabled' => true, 'services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => 'PHONE-ID',
            'services.whatsapp.api_version' => 'v21.0', 'services.whatsapp.api_base' => 'https://graph.test',
        ]);
        $this->admin = User::create(['name' => 'COO', 'email' => 'coo@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->at('2026-11-02 10:00');
    }

    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Kolkata')->utc());
    }

    private function api(string $method, string $url, array $data = [], ?User $as = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        return $this->withToken(auth('api')->login($as ?? $this->admin))->json($method, '/api/admin'.$url, $data);
    }

    /** Fake WhatsApp API that accepts every message. */
    private function accept(): void
    {
        $n = 0;
        Http::fake([self::API => function () use (&$n) {
            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.TEST'.(++$n)]]]);
        }]);
    }

    private function create(array $over = [], int $expect = 201)
    {
        return $this->api('POST', '/action-hub/actions', array_merge([
            'customer' => 'Ananya Krishnan', 'project' => 'Lake View', 'phone' => '+91 98765 43210', 'description' => 'Hinge loose',
            'action_type' => 'Snag', 'scheduled_at' => '2026-11-10T11:00', 'assigned_to' => 'Arif', 'request_id' => (string) Str::uuid(),
        ], $over))->assertStatus($expect);
    }

    private function submit(array $a, array $over = [])
    {
        return $this->api('PUT', '/action-hub/actions/'.$a['id'], array_merge([
            'customer' => $a['customer'], 'project' => $a['project'], 'phone' => $a['phone'], 'description' => $a['description'],
            'action_type' => $a['action_type'], 'action_other' => $a['action_other'], 'scheduled_at' => $a['scheduled_local'],
            'status' => $a['status'], 'assigned_to' => $a['assigned_to'], 'version' => $a['version'], 'request_id' => (string) Str::uuid(),
        ], $over));
    }

    /** Send WhatsApp / Retry for a saved action, with the ticket the user confirmed. */
    private function send(array $a, ?string $ticket = null, array $extra = [])
    {
        return $this->api('POST', '/action-hub/actions/'.$a['id'].'/whatsapp', ['ticket_no' => $ticket ?? $a['ticket_no']] + $extra);
    }

    private function body(string $service, string $ticket, string $name = 'ANANYA KRISHNAN'): string
    {
        return "Dear {$name},\n\nWe have received your {$service} service request. Our team will keep you updated on the next steps.\n\nTicket #: {$ticket}\n\nRegards,\nPrestige Ventures - Admin";
    }

    // ── 1. Submit saves only ───────────────────────────────────────────

    public function test_submitting_an_eligible_action_saves_it_with_a_ticket_and_sends_nothing(): void
    {
        $this->accept();
        $res = $this->create();
        $this->assertSame('PV-SG-2611-001', $res->json('data.ticket_no'));
        $this->assertSame('Action added.', $res->json('message'));
        $this->assertArrayNotHasKey('whatsapp', $res->json());
        $this->assertSame(['status' => 'pending', 'attempts' => 0, 'can_send' => true, 'can_retry' => false],
            array_intersect_key($res->json('data.whatsapp'), array_flip(['status', 'attempts', 'can_send', 'can_retry'])));
        $a = Action::first();
        $this->assertSame(['ANANYA KRISHNAN', 'LAKE VIEW', '9876543210', 'HINGE LOOSE', 'Arif', 'On Track'], [$a->customer, $a->project, $a->phone, $a->description, $a->assigned_to, $a->status]);
        $this->assertSame(['created', 'submitted'], ActionEvent::where('action_id', $a->id)->orderBy('id')->pluck('event')->all());
        Http::assertNothingSent();

        // Reloading the page never sends either.
        $this->api('GET', '/action-hub/actions')->assertOk()->assertJsonPath('data.0.whatsapp.status', 'pending');
        Http::assertNothingSent();
    }

    // ── 2/3. Send WhatsApp: the four eligible actions ──────────────────

    public function test_send_whatsapp_sends_the_right_message_for_each_eligible_action_to_the_saved_number(): void
    {
        $this->accept();
        $cases = [
            ['Snag', 'PV-SG-2611-001', 'Snag'],
            ['Service', 'PV-SV-2611-002', 'Service'], // one serial shared by all four prefixes
            ['Measurements Scheduled', 'PV-MSRMT-2611-003', 'Measurement'],
            ['Scheduled Installation', 'PV-INSTL-2611-004', 'Installation'], // label accepted, stored as "Installation"
        ];
        foreach ($cases as $k => [$type, $ticket, $service]) {
            $a = $this->create(['action_type' => $type])->json('data');
            $this->assertSame($ticket, $a['ticket_no']);
            Http::assertSentCount($k); // saving sent nothing

            $res = $this->send($a)->assertOk();
            $this->assertTrue($res->json('whatsapp.sent'));
            $this->assertSame(['accepted', '9198******10', 1], [$res->json('data.whatsapp.status'), $res->json('data.whatsapp.recipient'), $res->json('data.whatsapp.attempts')]);
            $this->assertStringContainsString("ticket {$ticket}", $res->json('message'));
            $this->assertSame($ticket, $res->json('data.ticket_no')); // no new number on send
            Http::assertSent(fn (HttpRequest $r) => $r->url() === self::API && $r['to'] === '919876543210' && $r['type'] === 'text'
                && $r['text']['body'] === $this->body($service, $ticket) && $r->hasHeader('Authorization', 'Bearer test-token'));
        }
        Http::assertSentCount(4);
        $this->assertSame(4, ActionEvent::where('event', 'whatsapp_accepted')->count());
        $log = json_encode(ActionEvent::all()->toArray()).json_encode(Message::all()->toArray());
        foreach (['9876543210', 'We have received', 'test-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_an_approved_template_is_used_when_configured(): void
    {
        config(['action_hub.whatsapp.templates.request_received.meta' => 'service_request_received', 'action_hub.whatsapp.template_language' => 'en_US']);
        $this->accept();
        $this->send($this->create(['action_type' => 'Installation'])->json('data'))->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'template'
            && $r['template']['name'] === 'service_request_received'
            && $r['template']['language']['code'] === 'en_US'
            && array_column($r['template']['components'][0]['parameters'], 'text') === ['ANANYA KRISHNAN', 'Installation', 'PV-INSTL-2611-001']
            && ! isset($r['template']['components'][1])); // no header, no PDF
    }

    public function test_the_destination_always_comes_from_the_saved_action(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        $this->send($a, null, ['phone' => '9123456789', 'to' => '919123456789'])->assertOk(); // ignored
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '919876543210');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains(json_encode($r->data()), '9123456789'));
    }

    // ── 4. Not for Task / Contact Lead / Other ─────────────────────────

    public function test_other_actions_get_no_ticket_and_cannot_send(): void
    {
        $this->accept();
        foreach ([['action_type' => 'Task'], ['action_type' => 'Contact Lead'], ['action_type' => 'Other', 'action_other' => 'Site visit']] as $over) {
            $a = $this->create($over)->json('data');
            $this->assertNull($a['ticket_no']);
            $this->assertNull($a['whatsapp']);
            $res = $this->send($a, 'PV-SG-2611-001')->assertStatus(422);
            $this->assertStringContainsString('Only Snag, Service, Scheduled Measurements and Scheduled Installation', $res->json('message'));
        }
        Http::assertNothingSent();
        $this->assertSame(0, Message::count());
        $this->assertSame(0, DB::table('action_hub_ticket_counters')->count());
    }

    public function test_the_send_request_is_checked_on_the_server(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        $this->api('POST', '/action-hub/actions/'.$a['id'].'/whatsapp')->assertStatus(422)->assertJsonValidationErrors('ticket_no');
        $this->send($a, 'PV-SG-2611-999')->assertStatus(422)->assertJsonPath('message', 'The ticket number does not match this action (its ticket is PV-SG-2611-001). Reload the page and try again. Nothing was sent.');
        $this->api('POST', '/action-hub/actions/999999/whatsapp', ['ticket_no' => 'PV-SG-2611-001'])->assertNotFound();

        // Changed from Snag to Service after its ticket was created: the PV-SG ticket is kept, but a Service message is not sent under it.
        $svc = $this->submit($a, ['action_type' => 'Service'])->assertOk()->json('data');
        $this->assertSame('PV-SG-2611-001', $svc['ticket_no']);
        $this->assertStringContainsString('was created for a different action type', $this->send($svc)->assertStatus(422)->json('message'));

        // Only administrators.
        $partner = User::create(['name' => 'P', 'email' => 'p@example.test', 'phone' => '9000000002', 'password' => 'Correct-Horse-9!', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->api('POST', '/action-hub/actions/'.$a['id'].'/whatsapp', ['ticket_no' => 'PV-SG-2611-001'], $partner)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        $this->withoutToken()->postJson('/api/admin/action-hub/actions/'.$a['id'].'/whatsapp', ['ticket_no' => 'PV-SG-2611-001'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    // ── Ticket numbers ─────────────────────────────────────────────────

    public function test_all_four_prefixes_share_one_monthly_serial_and_numbers_are_never_reused(): void
    {
        $t = fn (array $over = []) => $this->create($over)->json('data.ticket_no');
        $this->assertSame('PV-SG-2611-001', $t());
        $this->assertSame('PV-SV-2611-002', $t(['action_type' => 'Service']));
        $this->assertSame('PV-MSRMT-2611-003', $t(['action_type' => 'Measurements Scheduled']));
        $this->assertSame('PV-INSTL-2611-004', $t(['action_type' => 'Installation']));
        $this->assertNull($t(['action_type' => 'Task'])); // no ticket, no number used
        $this->assertSame('PV-SG-2611-005', $t());

        $last = Action::where('ticket_no', 'PV-SG-2611-005')->first();
        Message::where('action_id', $last->id)->delete();
        ActionEvent::where('action_id', $last->id)->delete();
        $last->delete();
        $this->assertSame('PV-SG-2611-006', $t()); // a removed action's number is not handed out again

        $this->at('2026-11-30 23:59');
        $this->assertSame('PV-SV-2611-007', $t(['action_type' => 'Service', 'scheduled_at' => '2026-12-10T11:00']));
        $this->at('2026-12-01 00:00'); // already December in India: the serial starts again at 001
        $this->assertSame('PV-INSTL-2612-001', $t(['action_type' => 'Installation', 'scheduled_at' => '2026-12-10T11:00']));
        $this->assertSame('PV-SG-2612-002', $t(['scheduled_at' => '2026-12-10T11:00']));
        DB::table('action_hub_ticket_counters')->where(['prefix' => '*', 'period' => '2612'])->update(['last_serial' => 999]);
        $this->assertSame('PV-SG-2612-1000', $t(['scheduled_at' => '2026-12-10T11:00']));

        // A month that began under the old one-serial-per-prefix numbering continues after its highest number.
        DB::table('action_hub_ticket_counters')->insert([
            ['prefix' => 'PV-SG', 'period' => '2701', 'last_serial' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['prefix' => 'PV-SV', 'period' => '2701', 'last_serial' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->at('2027-01-04 10:00');
        $this->assertSame('PV-SV-2701-008', $t(['action_type' => 'Service', 'scheduled_at' => '2027-01-10T11:00']));
        // And an existing number is never handed out again, whatever the counter says.
        Action::create(['customer' => 'X', 'project' => 'Y', 'phone' => '9876543210', 'action_type' => 'Snag', 'scheduled_at' => now()->addDay(),
            'status' => 'On Track', 'assigned_to' => 'Arif', 'ticket_no' => 'PV-SG-2701-009']);
        $this->assertSame('PV-SG-2701-010', $t(['scheduled_at' => '2027-01-10T11:00']));
        $this->assertSame(Action::whereNotNull('ticket_no')->count(), Action::whereNotNull('ticket_no')->distinct()->count('ticket_no'));
        Http::assertNothingSent();
    }

    public function test_the_shared_serial_holds_under_parallel_saves_of_different_types(): void
    {
        // Ticket numbers taken in interleaved transactions for all four prefixes: one serial, no gaps, no repeats.
        $numbers = [];
        foreach (range(1, 40) as $i) {
            $numbers[] = DB::transaction(fn () => ActionHubWhatsapp::nextTicket(['PV-SG', 'PV-SV', 'PV-MSRMT', 'PV-INSTL'][$i % 4], now()));
        }
        $serials = array_map(fn ($n) => (int) substr($n, -3), $numbers);
        $this->assertSame(range(1, 40), $serials);
        $this->assertSame('PV-SG-2611-040', end($numbers));
    }

    public function test_ticket_numbers_stay_unique_when_generated_back_to_back(): void
    {
        $numbers = [];
        for ($i = 0; $i < 60; $i++) {
            $numbers[] = DB::transaction(fn () => ActionHubWhatsapp::nextTicket('PV-SG', now()));
        }
        $this->assertCount(60, array_unique($numbers));
        $this->assertSame('PV-SG-2611-060', end($numbers));
        $a = $this->create()->json('data');
        $this->expectException(UniqueConstraintViolationException::class);
        Action::create(['customer' => 'X', 'project' => 'Y', 'phone' => '9876543210', 'action_type' => 'Snag', 'scheduled_at' => now()->addDay(),
            'status' => 'On Track', 'assigned_to' => 'Arif', 'ticket_no' => $a['ticket_no']]);
    }

    public function test_an_older_eligible_action_without_a_ticket_gets_one_on_its_next_submit(): void
    {
        $task = $this->create(['action_type' => 'Task'])->json('data');
        // Like the records saved before tickets existed: an eligible action without a ticket.
        Action::whereKey($task['id'])->update(['action_type' => 'Installation']);
        $old = $this->api('GET', '/action-hub/actions')->json('data.0');
        $this->assertNull($old['ticket_no']);
        $this->assertNull($old['whatsapp']);
        $saved = $this->submit($old, ['assigned_to' => 'Aamir'])->assertOk()->json('data');
        $this->assertSame(['PV-INSTL-2611-001', 'pending'], [$saved['ticket_no'], $saved['whatsapp']['status']]);
        $this->assertSame(1, ActionEvent::where('event', 'ticket_assigned')->count());
        $this->assertSame(0, ActionEvent::where('event', 'edited')->count()); // the ticket is not reported as an edited field
        // Submitting again keeps it.
        $this->assertSame('PV-INSTL-2611-001', $this->submit($saved, ['assigned_to' => 'Arif'])->assertOk()->json('data.ticket_no'));
        Http::assertNothingSent();
    }

    // ── 7/9/10. Repeats, accepted messages, edits ──────────────────────

    public function test_an_accepted_message_is_not_sent_again_and_edits_never_send(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        $sent = $this->send($a)->assertOk()->json('data');
        $this->assertSame(['accepted', false, false], [$sent['whatsapp']['status'], $sent['whatsapp']['can_send'], $sent['whatsapp']['can_retry']]);

        $again = $this->send($a)->assertOk();
        $this->assertTrue($again->json('whatsapp.sent')); // it was sent before…
        $this->assertStringStartsWith('Already sent', $again->json('message')); // …and is not sent again
        Http::assertSentCount(1);

        $a = $this->submit($sent, ['description' => 'Replace the hinge', 'assigned_to' => 'Aamir'])->assertOk()->json('data');
        $a = $this->submit($a, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-20T15:00', 'reason' => 'Customer away'])->assertOk()->json('data');
        $a = $this->submit($a, ['customer' => 'Ananya K', 'phone' => '9123456780'])->assertOk()->json('data');
        $done = $this->submit($a, ['status' => 'Completed'])->assertOk();
        $this->assertSame('PV-SG-2611-001', $done->json('data.ticket_no'));
        $this->assertArrayNotHasKey('whatsapp', $done->json());
        $this->api('GET', '/action-hub/actions');
        Http::assertSentCount(1);
        $this->assertSame(1, Message::first()->attempts);
    }

    public function test_a_repeated_submit_of_the_same_create_saves_once_and_sends_nothing(): void
    {
        $this->accept();
        $rid = (string) Str::uuid();
        $first = $this->create(['request_id' => $rid]);
        $again = $this->create(['request_id' => $rid], 200);
        $this->assertTrue($again->json('replayed'));
        $this->assertSame($first->json('data.ticket_no'), $again->json('data.ticket_no'));
        $this->assertSame(1, Action::count());
        Http::assertNothingSent();
    }

    public function test_a_send_in_progress_cannot_be_started_again(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        Message::query()->update(['status' => 'sending', 'updated_at' => now()]); // another click is sending right now
        $busy = $this->send($a)->assertStatus(409);
        $this->assertStringContainsString('already being sent', $busy->json('message'));
        $this->assertSame('whatsapp_busy', $busy->json('error'));
        $this->assertStringNotContainsString('9876543210', $busy->getContent());
        $this->assertFalse($busy->json('whatsapp.sent'));
        Http::assertNothingSent();

        $this->travel(3)->minutes(); // that request died without finishing
        $this->send($a)->assertOk()->assertJsonPath('whatsapp.sent', true);
        $this->send($a)->assertOk(); // a late repeated click: already sent
        Http::assertSentCount(1);
    }

    // ── 5/8. Missing configuration, failures, retry ────────────────────

    public function test_sending_is_refused_with_a_structured_error_while_whatsapp_is_unavailable(): void
    {
        $this->accept(); // would accept, but must not be called while unavailable
        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '', 'services.whatsapp.enabled' => false]); // like this machine
        $a = $this->create()->json('data');

        // The page is told up front (names of the missing settings only).
        $cfg = $this->api('GET', '/action-hub/actions')->json('config');
        $this->assertSame([false, ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID'], false], [$cfg['whatsapp_ready'], $cfg['whatsapp_missing'], $cfg['whatsapp_sending_enabled']]);
        $this->assertSame('WhatsApp sending is not configured on this server. Configure the WhatsApp Business API before sending messages. Missing settings: WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID. Sending is switched off (WHATSAPP_SENDING_ENABLED=false).', $cfg['whatsapp_problem']);

        // A request that bypasses the page is refused: 503, structured, nothing changed.
        $res = $this->send($a)->assertStatus(503);
        $this->assertSame(['whatsapp_unavailable', ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID'], false], [$res->json('error'), $res->json('missing_settings'), $res->json('sending_enabled')]);
        $this->assertStringEndsWith('Nothing was sent.', $res->json('message'));
        $this->assertFalse($res->json('whatsapp.sent'));
        $this->assertSame(['pending', 0, null], [$res->json('whatsapp_state.status'), $res->json('whatsapp_state.attempts'), $res->json('whatsapp_state.error')]);
        $this->assertArrayNotHasKey('data', $res->json()); // no action record (full phone number) in an error
        $this->assertStringNotContainsString('9876543210', $res->getContent());
        $this->assertSame(['pending', 0, null], [Message::first()->status, Message::first()->attempts, Message::first()->last_attempt_at]);
        $this->assertSame(0, Message::where('status', 'accepted')->count());

        // Credentials present but sending switched off: refused the same way.
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => 'PHONE-ID']);
        $off = $this->send($a)->assertStatus(503);
        $this->assertSame([[], false], [$off->json('missing_settings'), $off->json('sending_enabled')]);
        $this->assertStringContainsString('WHATSAPP_SENDING_ENABLED=false', $off->json('message'));
        $this->assertStringNotContainsString('test-token', json_encode($off->json()));
        Http::assertNothingSent();

        // Each refusal is in the audit history (who / when / why), without the token or the number.
        $events = ActionEvent::where('event', 'whatsapp_not_configured')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['PV-SG-2611-001', ['WHATSAPP_TOKEN', 'WHATSAPP_PHONE_NUMBER_ID'], false], [$events[0]->details['ticket'], $events[0]->details['missing_settings'], $events[0]->details['sending_enabled']]);
        $this->assertStringNotContainsString('9876543210', json_encode($events->toArray()));
        $this->assertStringNotContainsString('test-token', json_encode($events->toArray()));

        // Once configured and switched on, the same button sends it — ticket unchanged, first real attempt.
        config(['services.whatsapp.enabled' => true]);
        $this->assertTrue($this->api('GET', '/action-hub/actions')->json('config.whatsapp_ready'));
        $ok = $this->send($a)->assertOk();
        $this->assertSame([true, 'accepted', 'PV-SG-2611-001', 1], [$ok->json('whatsapp.sent'), $ok->json('data.whatsapp.status'), $ok->json('data.ticket_no'), $ok->json('data.whatsapp.attempts')]);
        Http::assertSentCount(1);
    }

    public function test_the_send_service_itself_still_refuses_when_unavailable(): void
    {
        // Safety net below the endpoint (e.g. settings changed while a request was running).
        $this->accept();
        $a = Action::find($this->create()->json('data.id'));
        config(['services.whatsapp.enabled' => false]);
        $r = ActionHubWhatsapp::deliver($a, $this->admin, app(WhatsAppService::class));
        $this->assertSame([false, 'not_configured'], [$r['sent'], $r['status']]);
        $this->assertStringContainsString('The message was NOT sent.', $r['message']);
        $this->assertNotSame('accepted', Message::first()->status);
        Http::assertNothingSent();
    }

    public function test_a_rejected_message_is_reported_and_retried_with_the_same_ticket(): void
    {
        Http::fakeSequence(self::API)
            ->push(['error' => ['message' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.', 'code' => 131047]], 400)
            ->push(['messages' => [['id' => 'wamid.OK']]]);
        $a = $this->create(['action_type' => 'Service'])->json('data');
        $res = $this->send($a)->assertOk();
        $this->assertFalse($res->json('whatsapp.sent'));
        $this->assertSame(['failed', true], [$res->json('data.whatsapp.status'), $res->json('data.whatsapp.can_retry')]);
        $this->assertStringContainsString('code 131047', $res->json('data.whatsapp.error'));

        $ok = $this->send($a)->assertOk();
        $this->assertSame(['accepted', 'PV-SV-2611-001', 2, null], [$ok->json('data.whatsapp.status'), $ok->json('data.ticket_no'), $ok->json('data.whatsapp.attempts'), $ok->json('data.whatsapp.error')]);
        Http::assertSentCount(2);
        $bodies = collect(Http::recorded())->map(fn ($pair) => $pair[0]['text']['body'])->all();
        $this->assertSame([$this->body('Service', 'PV-SV-2611-001'), $this->body('Service', 'PV-SV-2611-001')], $bodies); // same message both times
    }

    public function test_no_send_to_an_invalid_number(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        Action::whereKey($a['id'])->update(['phone' => '12345']); // e.g. a damaged record
        $res = $this->send($a)->assertOk();
        $this->assertSame('failed', $res->json('data.whatsapp.status'));
        $this->assertStringContainsString('not a valid 10-digit Indian mobile number', $res->json('message'));
        Http::assertNothingSent();
        $this->assertSame('919876543210', ActionHubWhatsapp::recipient('+91 98765-43210'));
        $this->assertSame('919876543210', ActionHubWhatsapp::recipient('09876543210'));
        $this->assertNull(ActionHubWhatsapp::recipient('5876543210'));
        $this->assertNull(ActionHubWhatsapp::recipient(''));
    }

    // ── Delivery status, report ────────────────────────────────────────

    public function test_delivery_status_from_the_webhook_and_report_columns(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret-test']);
        $this->accept();
        $a = $this->create()->json('data');
        $pending = $this->create(['action_type' => 'Service'])->json('data');
        $this->send($a)->assertOk();
        $json = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => 'wamid.TEST1', 'status' => 'delivered', 'timestamp' => '1793600000']]]]]]]]);
        $this->call('POST', '/api/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'app-secret-test'),
        ], $json)->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame('delivered', Message::where('action_id', $a['id'])->first()->delivery_status);

        $this->create(['action_type' => 'Task']);
        $rows = collect($this->api('GET', '/reports/action-hub')->json('sections.0.rows'));
        $this->assertSame('Request Received: Sent (delivered)', $rows->firstWhere('ticket', 'PV-SG-2611-001')['whatsapp']);
        $this->assertSame('Request Received: Not sent', $rows->firstWhere('ticket', $pending['ticket_no'])['whatsapp']);
        $this->assertSame(['', ''], [$rows->firstWhere('action', 'Task')['ticket'], $rows->firstWhere('action', 'Task')['whatsapp']]);
        $this->assertSame([$a['id']], array_column($this->api('GET', '/action-hub/actions', ['q' => 'pv-sg-2611'])->json('data'), 'id'));
    }

    // ── The nine messages (requested 2026-11-01) ───────────────────────

    private const FEEDBACK = 'https://g.page/r/prestige-ventures-test/review';

    private function preview(array $a)
    {
        return $this->api('GET', '/action-hub/actions/'.$a['id'].'/whatsapp');
    }

    /** Send message $template for the action as the admin reviewed it (current version and appointment). */
    private function sendTemplate(array $a, string $template, bool $confirmed = true)
    {
        $p = $this->preview($a)->assertOk()->json('data');

        return $this->send($a, null, ['template' => $template, 'version' => $p['version'], 'scheduled_at' => $p['scheduled_local'], 'confirmed' => $confirmed]);
    }

    private function bodies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]['text']['body'] ?? null)->all();
    }

    private function closing(string $ticket): string
    {
        return "\n\nTicket #: {$ticket}\n\nRegards,\nPrestige Ventures - Admin";
    }

    public function test_the_preview_shows_all_nine_messages_with_their_text_and_sends_nothing(): void
    {
        config(['action_hub.whatsapp.feedback_link' => self::FEEDBACK]);
        $this->accept();
        $a = $this->create()->json('data'); // Snag, 10 Nov 2026 11:00, On Track
        $p = $this->preview($a)->assertOk()->json('data');

        $this->assertSame(['request_received', 'scheduled', 'approaching', 'completed', 'delayed_internal', 'rescheduled_customer', 'postponed_customer', 'cancelled_customer', 'cancelled_internal'],
            array_column($p['templates'], 'key'));
        $t = collect($p['templates'])->keyBy('key');
        $this->assertSame(['request_received' => true, 'scheduled' => true, 'approaching' => false, 'completed' => false, 'delayed_internal' => false,
            'rescheduled_customer' => false, 'postponed_customer' => false, 'cancelled_customer' => false, 'cancelled_internal' => false],
            $t->map(fn ($x) => $x['available'])->all());
        $this->assertSame($this->body('Snag', 'PV-SG-2611-001'), $t['request_received']['text']);
        $this->assertSame("Dear ANANYA KRISHNAN,\n\nYour Snag service has been scheduled for 10 Nov 2026 at 11:00 AM.\n\nWe look forward to assisting you.".$this->closing('PV-SG-2611-001'), $t['scheduled']['text']);
        $this->assertStringContainsString('Get Ready', $t['approaching']['reason']);
        $this->assertStringContainsString('Completed', $t['completed']['reason']);
        $this->assertStringContainsString('aborted', $t['cancelled_customer']['reason']);
        $this->assertSame([null, 'The appointment shown is confirmed with the customer.'], [$t['request_received']['confirm'], $t['scheduled']['confirm']]);
        $this->assertSame(['PV-SG-2611-001', 'ANANYA KRISHNAN', 'Snag', '9198******10', '10 Nov 2026 at 11:00 AM', null, null],
            [$p['ticket_no'], $p['customer'], $p['service_type'], $p['recipient'], $p['scheduled_display'], $p['cannot_send'], $p['unavailable']]);
        $this->assertSame(['919876543210', '+91 98765 43210'], [$p['wa_number'], $p['phone']]); // the saved number, for the chat link

        // Send WhatsApp opens a WhatsApp chat with this text (click-to-chat, like Send Statement): no API
        // credentials needed, nothing sent by the server, no message or audit row written.
        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '', 'services.whatsapp.enabled' => false]);
        $events = ActionEvent::count();
        $draft = $this->preview($a)->assertOk()->json('data');
        $this->assertSame(['919876543210', true], [$draft['wa_number'], collect($draft['templates'])->firstWhere('key', 'scheduled')['available']]);
        $this->assertSame([$events, 1], [ActionEvent::count(), Message::count()]);
        Http::assertNothingSent(); // opening (and cancelling) the dialog sends nothing
        $this->assertSame(1, Message::count());

        // Not for other actions; administrators only.
        $task = $this->create(['action_type' => 'Task'])->json('data');
        $this->assertSame(['general'], array_column($this->preview($task)->assertOk()->json('data.templates'), 'key')); // Task: General Message only
        $partner = User::create(['name' => 'P', 'email' => 'p@example.test', 'phone' => '9000000002', 'password' => 'Correct-Horse-9!', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->api('GET', '/action-hub/actions/'.$a['id'].'/whatsapp', [], $partner)->assertForbidden();
    }

    public function test_each_message_is_sent_with_its_exact_text_only_in_the_state_it_describes(): void
    {
        config(['action_hub.whatsapp.feedback_link' => self::FEEDBACK]);
        $this->accept();
        $a = $this->create()->json('data');
        $T = 'PV-SG-2611-001';
        $expected = [];

        // B. Scheduled (needs the admin's confirmation).
        $this->sendTemplate($a, 'scheduled', false)->assertStatus(422)->assertJsonValidationErrors('confirmed');
        Http::assertNothingSent();
        $res = $this->sendTemplate($a, 'scheduled')->assertOk();
        $this->assertTrue($res->json('whatsapp.sent'));
        $this->assertSame(['scheduled', 'Service Scheduled', 'accepted', '2026-11-10T11:00', 'COO'], [
            $res->json('data.whatsapp.template'), $res->json('data.whatsapp.template_label'), $res->json('data.whatsapp.status'),
            $res->json('data.whatsapp.scheduled_for'), $res->json('data.whatsapp.sent_by')]);
        $expected[] = "Dear ANANYA KRISHNAN,\n\nYour Snag service has been scheduled for 10 Nov 2026 at 11:00 AM.\n\nWe look forward to assisting you.".$this->closing($T);

        // C. Approaching: only once Get Ready (Sat 7 Nov for Tue 10 Nov: Sunday is not a working day).
        $this->sendTemplate($a, 'approaching')->assertStatus(422)->assertJsonPath('error', 'whatsapp_not_available');
        $this->at('2026-11-07 09:00');
        $this->sendTemplate($a, 'approaching')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear ANANYA KRISHNAN,\n\nYour scheduled Snag service is approaching. Our team is ready to attend to it on 10 Nov 2026 at 11:00 AM.\n\nTicket #: {$T}\n\nIn case of any changes, please inform us immediately through WhatsApp at 6360474741.\n\nRegards,\nPrestige Ventures - Admin";

        // F. Rescheduled at the customer's request: the new appointment (latest reschedule).
        $a = $this->api('GET', '/action-hub/actions')->json('data.0');
        $this->sendTemplate($a, 'rescheduled_customer')->assertStatus(422); // no reschedule yet
        $a = $this->submit($a, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-20T15:00', 'reason' => 'Customer travelling'])->assertOk()->json('data');
        $this->assertSame($T, $a['ticket_no']);
        Http::assertSentCount(2); // the reschedule itself sent nothing
        $this->sendTemplate($a, 'rescheduled_customer')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear ANANYA KRISHNAN,\n\nAs requested, your Snag service has been rescheduled to 20 Nov 2026 at 03:00 PM.".$this->closing($T);
        // Service Scheduled for the same appointment is the same occasion: not sent again.
        $dup = $this->sendTemplate($a, 'scheduled')->assertOk();
        $this->assertStringStartsWith('Already sent', $dup->json('message'));
        $this->assertStringContainsString('Rescheduled at Customer', $dup->json('message'));
        Http::assertSentCount(3);

        // G. Postponed at the customer's request: while Delayed (the appointment has passed).
        $this->at('2026-11-21 10:00');
        $a = $this->api('GET', '/action-hub/actions')->json('data.0');
        $this->assertSame('Delayed', $a['status']);
        $this->sendTemplate($a, 'postponed_customer')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear ANANYA KRISHNAN,\n\nAs requested, your Snag service has been postponed.\n\nThe revised date and time will be communicated shortly.".$this->closing($T);
        $this->assertStringStartsWith('Already sent', $this->sendTemplate($a, 'delayed_internal')->assertOk()->json('message')); // one delay message per delay

        // D. Completed: with the configured Google review link.
        $this->sendTemplate($a, 'completed')->assertStatus(422);
        $a = $this->submit($a, ['status' => 'Completed'])->assertOk()->json('data');
        $this->sendTemplate($a, 'completed')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear ANANYA KRISHNAN,\n\nThe Snag service has been completed and handed over.\n\nTicket #: {$T}\n\nThank you for the opportunity. We request you to share your valuable feedback here: ".self::FEEDBACK."\n\nRegards,\nPrestige Ventures - Admin";

        // E. Delayed for internal reasons (admin flags a known delay) — another action, its own ticket.
        $b = $this->create(['action_type' => 'Service', 'customer' => 'Ravi Kumar', 'scheduled_at' => '2026-11-28T10:30'])->json('data');
        $this->assertSame('PV-SV-2611-002', $b['ticket_no']);
        $b = $this->submit($b, ['status' => 'Delayed', 'reason' => 'Material not received'])->assertOk()->json('data');
        $this->sendTemplate($b, 'delayed_internal')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear RAVI KUMAR,\n\nDue to unforeseen internal constraints, your Service service has been delayed.\n\nThe revised date and time will be communicated shortly. We regret the inconvenience.".$this->closing('PV-SV-2611-002');

        // H / I. Cancelled: only after Abort; one cancellation message per cancellation.
        $c = $this->create(['action_type' => 'Measurements Scheduled', 'customer' => 'Meera Nair', 'scheduled_at' => '2026-11-29T10:00'])->json('data');
        $this->sendTemplate($c, 'cancelled_customer')->assertStatus(422);
        $c = $this->submit($c, ['status' => 'Abort', 'reason' => 'Customer cancelled'])->assertOk()->json('data');
        $this->sendTemplate($c, 'request_received')->assertStatus(422); // not for a cancelled action
        $this->sendTemplate($c, 'cancelled_customer')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear MEERA NAIR,\n\nAs requested, your Measurement service has been permanently cancelled.\n\nShould you require our assistance in the future, please call or WhatsApp us at 6360474741.".$this->closing('PV-MSRMT-2611-003');
        $this->assertStringStartsWith('Already sent', $this->sendTemplate($c, 'cancelled_internal')->assertOk()->json('message'));

        $d = $this->create(['action_type' => 'Installation', 'customer' => 'Arjun Shetty', 'scheduled_at' => '2026-11-30T10:00'])->json('data');
        $d = $this->submit($d, ['status' => 'Abort', 'reason' => 'Site not ready'])->assertOk()->json('data');
        $this->sendTemplate($d, 'cancelled_internal')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $expected[] = "Dear ARJUN SHETTY,\n\nAs communicated separately, your Installation service has been permanently cancelled.\n\nShould you require our assistance in the future, please call or WhatsApp us at 6360474741.".$this->closing('PV-INSTL-2611-004');

        // Exactly these messages, in this order, all to the saved number, each action with its one ticket.
        $this->assertSame($expected, $this->bodies());
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '919876543210');
        $this->assertSame(8, Message::where('status', 'accepted')->count());
        $this->assertSame(['PV-SG-2611-001'], Message::where('action_id', $a['id'])->distinct()->pluck('ticket_no')->all());
        // History: who, which message, which ticket — never the text or the full number.
        $events = ActionEvent::where('event', 'whatsapp_accepted')->orderBy('id')->get();
        $this->assertSame(['Service Scheduled', 'Service Date Approaching – Confirmation', 'Rescheduled at Customer’s Request – Revised Date Confirmed', 'Postponed at Customer’s Request – Date to Follow',
            'Service Completed & Handover Done', 'Service Delayed – Revised Date to Be Communicated', 'Cancelled at Customer’s Request', 'Cancelled – Internal Decision'],
            $events->map(fn ($e) => $e->details['message_label'])->all());
        $this->assertSame(['COO', '20 Nov 2026, 15:00'], [$events[2]->user_name, $events[2]->details['scheduled_for']]);
        $log = json_encode(ActionEvent::all()->toArray()).json_encode(Message::all()->toArray());
        foreach (['9876543210', 'Dear ', 'test-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        // The report lists every message.
        $report = $this->api('GET', '/reports/action-hub')->json('sections');
        $wa = collect($report)->firstWhere('title', 'WhatsApp messages');
        $this->assertCount(Message::count(), $wa['rows']);
    }

    public function test_a_message_reviewed_before_the_action_changed_is_not_sent(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        $seen = $this->preview($a)->json('data'); // the admin opens Send WhatsApp…
        $this->submit($a, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-12T09:00', 'reason' => 'Moved'])->assertOk(); // …meanwhile the date changes

        $res = $this->send($a, null, ['template' => 'scheduled', 'version' => $seen['version'], 'scheduled_at' => $seen['scheduled_local'], 'confirmed' => true])->assertStatus(409);
        $this->assertSame('whatsapp_stale', $res->json('error'));
        $this->assertStringContainsString('review the message again', $res->json('message'));
        Http::assertNothingSent();

        // Reviewed again: sent with the new date.
        $this->sendTemplate($a, 'scheduled')->assertOk()->assertJsonPath('whatsapp.sent', true);
        $this->assertStringContainsString('scheduled for 12 Nov 2026 at 09:00 AM.', $this->bodies()[0]);
    }

    public function test_a_failed_message_is_retried_with_the_same_ticket_and_sent_once(): void
    {
        Http::fakeSequence(self::API)
            ->push(['error' => ['message' => 'Re-engagement message', 'code' => 131047]], 400)
            ->push(['messages' => [['id' => 'wamid.SCHED']]]);
        $a = $this->create(['action_type' => 'Service'])->json('data');
        $fail = $this->sendTemplate($a, 'scheduled')->assertOk();
        $this->assertSame(['failed', true], [$fail->json('data.whatsapp.status'), $fail->json('data.whatsapp.can_retry')]);
        $this->assertStringContainsString('NOT sent', $fail->json('message'));
        $ok = $this->sendTemplate($a, 'scheduled')->assertOk();
        $this->assertSame(['accepted', 2, 'PV-SV-2611-001'], [$ok->json('data.whatsapp.status'), $ok->json('data.whatsapp.attempts'), $ok->json('data.ticket_no')]);
        $this->sendTemplate($a, 'scheduled')->assertOk(); // a late repeated click
        Http::assertSentCount(2);
        $this->assertSame(1, Message::where('template', 'scheduled')->count());

        // Two simultaneous sends of the same message: the second is turned away.
        $b = $this->create()->json('data');
        $p = $this->preview($b)->json('data');
        Message::create(['action_id' => $b['id'], 'ticket_no' => $b['ticket_no'], 'service_type' => 'Snag', 'template' => 'scheduled',
            'dedupe_key' => 'schedule:'.CarbonImmutable::parse('2026-11-10 11:00', 'Asia/Kolkata')->getTimestamp(), 'status' => 'sending']);
        $busy = $this->send($b, null, ['template' => 'scheduled', 'version' => $p['version'], 'scheduled_at' => $p['scheduled_local'], 'confirmed' => true])->assertStatus(409);
        $this->assertSame('whatsapp_busy', $busy->json('error'));
        Http::assertSentCount(2);
    }

    public function test_approved_meta_templates_get_their_variables_in_order(): void
    {
        config(['action_hub.whatsapp.templates.scheduled.meta' => 'ah_service_scheduled', 'action_hub.whatsapp.templates.cancelled_customer.meta' => 'ah_cancelled_customer']);
        $this->accept();
        $a = $this->create(['action_type' => 'Installation'])->json('data');
        $this->sendTemplate($a, 'scheduled')->assertOk();
        $a = $this->submit($this->api('GET', '/action-hub/actions')->json('data.0'), ['status' => 'Abort'])->assertOk()->json('data');
        $this->sendTemplate($a, 'cancelled_customer')->assertOk();
        $sent = collect(Http::recorded())->map(fn ($p) => [$p[0]['template']['name'], array_column($p[0]['template']['components'][0]['parameters'], 'text')])->all();
        $this->assertSame([
            ['ah_service_scheduled', ['ANANYA KRISHNAN', 'Installation', '10 Nov 2026', '11:00 AM', 'PV-INSTL-2611-001']],
            ['ah_cancelled_customer', ['ANANYA KRISHNAN', 'Installation', 'PV-INSTL-2611-001']],
        ], $sent);
    }

    public function test_completion_needs_the_configured_feedback_link_and_unavailable_whatsapp_sends_nothing(): void
    {
        config(['action_hub.whatsapp.feedback_link' => '']);
        $this->accept();
        $a = $this->create()->json('data');
        $a = $this->submit($a, ['status' => 'Completed'])->assertOk()->json('data');
        $t = collect($this->preview($a)->json('data.templates'))->keyBy('key');
        $this->assertFalse($t['completed']['available']);
        $this->assertStringContainsString('ACTION_HUB_FEEDBACK_URL', $t['completed']['reason']);
        $this->assertStringContainsString('ACTION_HUB_FEEDBACK_URL', $this->sendTemplate($a, 'completed')->assertStatus(422)->json('message'));

        // WhatsApp switched off, like this machine: the dialog says so up front and a send is refused.
        config(['action_hub.whatsapp.feedback_link' => self::FEEDBACK, 'services.whatsapp.enabled' => false, 'services.whatsapp.token' => '']);
        $p = $this->preview($a)->assertOk()->json('data');
        $this->assertSame(['whatsapp_unavailable', ['WHATSAPP_TOKEN']], [$p['unavailable']['code'], $p['unavailable']['missing_settings']]);
        $res = $this->sendTemplate($a, 'completed')->assertStatus(503);
        $this->assertStringEndsWith('Nothing was sent.', $res->json('message'));
        $this->assertSame('Service Completed & Handover Done', ActionEvent::where('event', 'whatsapp_not_configured')->latest('id')->first()->details['message_label']);
        Http::assertNothingSent();
        $this->assertSame(0, Message::where('template', 'completed')->count());
    }

    // ── Every action, older actions, approved templates (fix of 2026-11-01) ──

    /** Send the General Message of a Task / Contact Lead / Other action. */
    private function sendGeneral(array $a, string $text, array $extra = [])
    {
        $p = $this->preview($a)->assertOk()->json('data');

        return $this->api('POST', '/action-hub/actions/'.$a['id'].'/whatsapp', ['template' => 'general', 'message' => $text, 'version' => $p['version'], 'scheduled_at' => $p['scheduled_local']] + $extra);
    }

    public function test_task_contact_lead_and_other_send_a_general_message_in_every_status_without_a_ticket(): void
    {
        $this->accept();
        $types = [['action_type' => 'Task'], ['action_type' => 'Contact Lead'], ['action_type' => 'Other', 'action_other' => 'Site visit']];
        foreach ($types as $i => $over) {
            $a = $this->create($over + ['customer' => 'Kiran Rao '.$i, 'phone' => '98765 4321'.$i])->json('data');
            $this->assertNull($a['ticket_no']);
            $statuses = [['On Track', []], ['Get Ready', ['status' => 'Get Ready']], ['Delayed', ['status' => 'Delayed']], ['Completed', ['status' => 'Completed']], ['Abort', ['status' => 'Abort']]];
            foreach ($statuses as [$status, $change]) {
                if ($change) {
                    $a = $this->submit($a, $change)->assertOk()->json('data');
                }
                $p = $this->preview($a)->assertOk()->json('data');
                $this->assertSame([$status, ['general'], true, null, false, false], [$a['status'], array_column($p['templates'], 'key'), $p['templates'][0]['available'], $p['ticket_no'], $p['needs_ticket'], $p['ticket_action']]);
            }
            $this->assertSame('Dear KIRAN RAO '.$i.",\n\n{message}\n\nRegards,\nPrestige Ventures - Admin", $p['templates'][0]['text']);
            $this->assertSame('+91 98765 4321'.$i, $p['phone']);
        }
        $other = Action::where('action_type', 'Other')->first();
        $this->assertSame('Other: Site visit', $this->preview(['id' => $other->id])->json('data.service_type'));

        $task = $this->api('GET', '/action-hub/actions', ['action' => 'Task'])->json('data.0');
        $res = $this->sendGeneral($task, '  Our team will visit   tomorrow at 10 AM.  ')->assertOk();
        $this->assertTrue($res->json('whatsapp.sent'));
        $this->assertStringNotContainsString('ticket', $res->json('message'));
        $this->assertSame("Dear KIRAN RAO 0,\n\nOur team will visit tomorrow at 10 AM.\n\nRegards,\nPrestige Ventures - Admin", $this->bodies()[0]);
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '919876543210');
        $this->assertNull(Message::first()->ticket_no);
        $this->assertSame('Task', Message::first()->service_type);
        $this->assertStringNotContainsString('Our team will visit', json_encode(Message::all()->toArray()).json_encode(ActionEvent::all()->toArray())); // text not stored

        // The same text again is not sent twice; a different text is a new message.
        $this->assertStringStartsWith('Already sent', $this->sendGeneral($task, 'our team will visit tomorrow at 10 AM.')->assertOk()->json('message'));
        $this->sendGeneral($task, 'Please share a convenient time.')->assertOk()->assertJsonPath('whatsapp.sent', true);
        Http::assertSentCount(2);

        // Validation: text required, one line, no ticket, no ticket messages.
        $this->sendGeneral($task, '')->assertStatus(422)->assertJsonValidationErrors('message');
        $this->sendGeneral($task, "Line one\nLine two")->assertStatus(422)->assertJsonValidationErrors('message');
        $this->sendGeneral($task, 'Hi', ['ticket_no' => 'PV-SG-2611-001'])->assertStatus(422);
        $p = $this->preview($task)->json('data');
        $this->send($task, '', ['template' => 'scheduled', 'version' => $p['version'], 'scheduled_at' => $p['scheduled_local'], 'confirmed' => true])->assertStatus(422);
        // Service actions do not get the General Message.
        $snag = $this->create()->json('data');
        $this->sendGeneral($snag, 'Hello', ['ticket_no' => $snag['ticket_no']])->assertStatus(422)->assertJsonPath('error', 'whatsapp_not_available');
        Http::assertSentCount(2);
        $this->assertSame(1, DB::table('action_hub_ticket_counters')->where('prefix', '*')->value('last_serial')); // no ticket used by Task / Contact Lead / Other
    }

    public function test_an_older_service_action_without_a_ticket_can_create_it_from_the_dialog(): void
    {
        $this->accept();
        $old = $this->create(['action_type' => 'Task'])->json('data');
        Action::whereKey($old['id'])->update(['action_type' => 'Service']); // like the records saved before tickets existed
        $p = $this->preview($old)->assertOk()->json('data');
        $this->assertSame([true, true, null], [$p['needs_ticket'], $p['ticket_action'], $p['ticket_no']]);
        $this->assertCount(9, $p['templates']);
        $this->assertFalse(collect($p['templates'])->contains('available', true));
        $this->assertStringContainsString('no ticket yet', $p['templates'][0]['reason']);

        $res = $this->api('POST', '/action-hub/actions/'.$old['id'].'/ticket')->assertOk();
        $this->assertSame(['PV-SV-2611-001', 'Ticket PV-SV-2611-001 created. Nothing was sent.', false], [$res->json('data.ticket_no'), $res->json('message'), $res->json('preview.needs_ticket')]);
        $this->assertTrue(collect($res->json('preview.templates'))->firstWhere('key', 'request_received')['available']);
        $this->assertSame('PV-SV-2611-001', $this->api('POST', '/action-hub/actions/'.$old['id'].'/ticket')->assertOk()->json('data.ticket_no')); // never replaced
        $this->assertSame(1, ActionEvent::where('action_id', $old['id'])->where('event', 'ticket_assigned')->count());

        $task = $this->create(['action_type' => 'Task'])->json('data');
        $this->api('POST', '/action-hub/actions/'.$task['id'].'/ticket')->assertStatus(422);
        $this->assertNull(Action::find($task['id'])->ticket_no);
        Http::assertNothingSent();
    }

    public function test_a_message_without_an_approved_meta_template_is_preview_only(): void
    {
        config(['action_hub.whatsapp.allow_session_text' => false, 'action_hub.whatsapp.templates.scheduled.meta' => 'ah_service_scheduled']);
        $this->accept();
        $a = $this->create()->json('data');

        $t = collect($this->preview($a)->json('data.templates'))->keyBy('key');
        $this->assertStringContainsString('WHATSAPP_AH_TEMPLATE_REQUEST_RECEIVED', $t['request_received']['template_problem']);
        $this->assertNull($t['scheduled']['template_problem']);
        $cfg = $this->api('GET', '/action-hub/actions')->json('config');
        $this->assertNotContains('WHATSAPP_AH_TEMPLATE_SCHEDULED', $cfg['whatsapp_templates_missing']);
        $this->assertContains('WHATSAPP_AH_TEMPLATE_GENERAL', $cfg['whatsapp_templates_missing']);

        $res = $this->send($a)->assertStatus(503); // Request Received: no approved template
        $this->assertSame(['template_not_configured', ['WHATSAPP_AH_TEMPLATE_REQUEST_RECEIVED']], [$res->json('error'), $res->json('missing_settings')]);
        $this->assertSame(['pending', 0], [Message::first()->status, Message::first()->attempts]);
        Http::assertNothingSent();

        $this->sendTemplate($a, 'scheduled')->assertOk()->assertJsonPath('whatsapp.sent', true); // approved template set: sent as that template
        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'template' && $r['template']['name'] === 'ah_service_scheduled');
        Http::assertSentCount(1);
    }

    public function test_missing_and_invalid_numbers_are_explained_and_never_replaced(): void
    {
        $this->accept();
        $a = $this->create()->json('data');
        Action::whereKey($a['id'])->update(['phone' => '']);
        $p = $this->preview($a)->json('data');
        $this->assertSame([null, null], [$p['phone'], $p['recipient']]);
        $this->assertStringContainsString('No WhatsApp number is saved', $p['recipient_problem']);
        Action::whereKey($a['id'])->update(['phone' => '12345']);
        $this->assertStringContainsString('not a valid 10-digit Indian mobile', $this->preview($a)->json('data.recipient_problem'));
        $this->assertSame('failed', $this->send($a)->assertOk()->json('data.whatsapp.status'));
        Http::assertNothingSent();
        config(['cns.whatsapp.test_recipient' => '9999900001']); // a test number set for statements is never used here
        Action::whereKey($a['id'])->update(['phone' => '9876543210']);
        $this->send($a)->assertOk()->assertJsonPath('whatsapp.sent', true);
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '919876543210');
        Http::assertNotSent(fn (HttpRequest $r) => $r['to'] === '919999900001');
    }

    // ── Assignees and the Send WhatsApp rows (requested 2026-11-01) ──

    public function test_only_the_seven_assignees_are_offered_and_accepted(): void
    {
        $cfg = $this->api('GET', '/action-hub/actions')->json('config');
        $this->assertSame(['Ashu', 'Aamir', 'Arif', 'Faisal', 'FMW', 'Hadi', 'Akram'], $cfg['assignees']);
        $this->assertArrayNotHasKey('former_assignees', $cfg);
        foreach (['Ashu', 'Aamir', 'Arif', 'Faisal', 'FMW', 'Hadi', 'Akram'] as $name) {
            $this->assertSame($name, $this->create(['assigned_to' => $name, 'action_type' => 'Task'])->json('data.assigned_to'));
        }
        foreach (['Adi', 'Amir', 'FMm', 'aamir', 'Amir (former name)'] as $old) { // old names / other spellings: never accepted
            $this->create(['assigned_to' => $old], 422)->assertJsonValidationErrors('assigned_to');
        }
        $this->api('GET', '/action-hub/actions', ['assignee' => 'Amir'])->assertStatus(422); // not a filter option either

        // An action still saved with an old name must be given one of the seven on its next Submit.
        $a = $this->create(['assigned_to' => 'Hadi'])->json('data');
        Action::whereKey($a['id'])->update(['assigned_to' => 'Amir']);
        $old = collect($this->api('GET', '/action-hub/actions')->json('data'))->firstWhere('id', $a['id']);
        $this->assertSame('Amir', $old['assigned_to']); // shown as saved; never renamed by a read
        $this->submit($old, ['description' => 'Edited'])->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->assertSame('Amir', Action::find($a['id'])->assigned_to);
        $moved = $this->submit($old, ['assigned_to' => 'Aamir'])->assertOk()->json('data');
        $this->assertSame('Aamir', $moved['assigned_to']);
        $e = ActionEvent::where('action_id', $a['id'])->where('event', 'assignee_changed')->latest('id')->first();
        $this->assertSame(['Amir', 'Aamir'], [$e->old_assignee, $e->new_assignee]); // history records the change
    }

    public function test_every_service_action_row_is_marked_for_send_whatsapp_whatever_its_status_ticket_or_stored_name(): void
    {
        $rows = [];
        foreach (['Snag', 'Service', 'Measurements Scheduled', 'Installation'] as $type) {
            foreach (['On Track', 'Get Ready', 'Delayed', 'Completed', 'Abort'] as $status) {
                $a = $this->create(['action_type' => $type])->json('data');
                $rows[] = $status === 'On Track' ? $a : $this->submit($a, ['status' => $status])->assertOk()->json('data');
            }
        }
        foreach ([['action_type' => 'Task'], ['action_type' => 'Contact Lead'], ['action_type' => 'Other', 'action_other' => 'Visit']] as $over) {
            $this->create($over);
        }
        $list = collect($this->api('GET', '/action-hub/actions')->json('data'));
        $this->assertCount(23, $list);
        $this->assertSame(20, $list->where('service_action', true)->count());
        $this->assertSame(['Contact Lead', 'Other', 'Task'], $list->where('service_action', false)->pluck('action_type')->sort()->values()->all());

        // Without a ticket (older record), overdue, or saved under a label / variant name: still a service action.
        Action::whereKey($rows[0]['id'])->update(['ticket_no' => null]);
        Action::whereKey($rows[1]['id'])->update(['action_type' => 'Scheduled Installation']);
        Action::whereKey($rows[2]['id'])->update(['action_type' => ' measurement ']);
        $this->at('2026-11-12 10:00'); // every appointment has passed: overdue
        $list = collect($this->api('GET', '/action-hub/actions')->json('data'))->keyBy('id');
        foreach ([0, 1, 2] as $i) {
            $this->assertTrue($list[$rows[$i]['id']]['service_action'], "row $i");
        }
        $this->assertSame(['Installation', 'Measurement'], [$this->preview($rows[1])->json('data.service_type'), $this->preview($rows[2])->json('data.service_type')]);
        $this->assertCount(9, $this->preview($rows[1])->json('data.templates'));
        foreach (['Scheduled Measurements' => 'Measurements Scheduled', 'SCHEDULED INSTALLATION' => 'Installation', ' snag ' => 'Snag', 'Measurement' => 'Measurements Scheduled', 'Task' => null, 'Contact Lead' => null, 'Other' => null] as $given => $value) {
            $this->assertSame($value, ActionHubWhatsapp::serviceAction($given), $given);
        }
    }
}
