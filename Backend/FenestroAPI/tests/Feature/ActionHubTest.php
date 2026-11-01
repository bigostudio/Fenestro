<?php

namespace Tests\Feature;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\ActionHub\Holiday;
use App\Models\User;
use App\Support\ActionHub;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Workspace → Action Hub: rules, audit history, concurrency and report. Times are Asia/Kolkata. */
class ActionHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['action_hub.timezone' => 'Asia/Kolkata', 'action_hub.weekly_off' => [0], 'action_hub.get_ready_working_days' => 2]);
        Http::preventStrayRequests(); // never a real WhatsApp call
        config(['services.whatsapp.token' => '', 'services.whatsapp.phone_number_id' => '']);
        $this->admin = User::create(['name' => 'COO', 'email' => 'coo@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->at('2026-11-02 10:00'); // Monday
    }

    /** Move the clock (IST). */
    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Kolkata')->utc());
    }

    /** A request with a token issued at the current (travelled) time. */
    private function api(string $method, string $url, array $data = [], ?User $as = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');

        return $this->withToken(auth('api')->login($as ?? $this->admin))->json($method, '/api/admin'.$url, $data);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'customer' => 'Malika Enterprises', 'project' => 'Prestige Villa', 'phone' => '+91 98765 43210',
            // Task: these tests are about status rules. Snag / Service / Measurements / Installation also get a ticket
            // and a WhatsApp message (ActionHubWhatsappTest).
            'description' => 'Fix the sliding window lock', 'action_type' => 'Task', 'scheduled_at' => '2026-11-05T11:00',
            'assigned_to' => 'Arif', 'request_id' => (string) Str::uuid(),
        ], $over);
    }

    private function create(array $over = []): array
    {
        return $this->api('POST', '/action-hub/actions', $this->payload($over))->assertCreated()->json('data');
    }

    private function submit(array $action, array $over = [])
    {
        $base = ['customer' => $action['customer'], 'project' => $action['project'], 'phone' => $action['phone'], 'description' => $action['description'],
            'action_type' => $action['action_type'], 'action_other' => $action['action_other'], 'scheduled_at' => $action['scheduled_local'],
            'status' => $action['status'], 'assigned_to' => $action['assigned_to'], 'version' => $action['version'], 'request_id' => (string) Str::uuid()];

        return $this->api('PUT', '/action-hub/actions/'.$action['id'], array_merge($base, $over));
    }

    private function events(int $id): array
    {
        return ActionEvent::where('action_id', $id)->orderBy('id')->pluck('event')->all();
    }

    // ── Access ─────────────────────────────────────────────────────────

    public function test_only_admins_can_use_action_hub(): void
    {
        $this->getJson('/api/admin/action-hub/actions')->assertUnauthorized();
        $partner = User::create(['name' => 'P', 'email' => 'p@example.test', 'phone' => '9000000002', 'password' => 'Correct-Horse-9!', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->api('GET', '/action-hub/actions', [], $partner)->assertForbidden();
        $this->api('POST', '/action-hub/actions', $this->payload(), $partner)->assertForbidden();
        $this->api('GET', '/reports/action-hub', [], $partner)->assertForbidden();
        $this->assertSame(0, Action::count());

        $res = $this->api('GET', '/action-hub/actions')->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame(['Snag', 'Service', 'Task', 'Contact Lead', 'Measurements Scheduled', 'Installation', 'Other'], $res->json('config.actions')); // stored values
        $this->assertSame(['Snag', 'Service', 'Task', 'Contact Lead', 'Scheduled Measurements', 'Scheduled Installation', 'Other'], array_values($res->json('config.action_labels'))); // shown
        $this->assertSame(['Ashu', 'Aamir', 'Arif', 'Faisal', 'FMW', 'Hadi', 'Akram'], $res->json('config.assignees'));
        $this->assertSame(['On Track', 'Get Ready', 'Delayed', 'Completed', 'Abort', 'Reschedule'], $res->json('config.statuses'));
    }

    // ── Create / validation ────────────────────────────────────────────

    public function test_create_defaults_to_on_track_upper_case_and_is_audited(): void
    {
        $a = $this->create();
        $this->assertSame('On Track', $a['status']);
        $this->assertSame('MALIKA ENTERPRISES', $a['customer']);
        $this->assertSame('PRESTIGE VILLA', $a['project']);
        $this->assertSame('9876543210', $a['phone']);
        $this->assertSame('2026-11-05T11:00', $a['scheduled_local']);
        $this->assertSame('2026-11-05 05:30:00', Action::first()->getRawOriginal('scheduled_at')); // stored in UTC
        $this->assertSame(['created', 'submitted'], $this->events($a['id']));
        $submit = ActionEvent::where('event', 'submitted')->first();
        $this->assertSame('2026-11-02 04:30:00', $submit->created_at->utc()->format('Y-m-d H:i:s')); // server time of the save
        $this->assertSame('COO', $submit->user_name);
        $this->assertSame('manual', $submit->source);
    }

    public function test_invalid_input_is_refused_and_logged_without_values(): void
    {
        $this->api('POST', '/action-hub/actions', $this->payload(['phone' => '12345']))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-02-30T10:00']))->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->api('POST', '/action-hub/actions', $this->payload(['action_type' => 'Other']))->assertStatus(422)->assertJsonValidationErrors('action_other');
        $this->api('POST', '/action-hub/actions', $this->payload(['action_type' => 'Visit']))->assertStatus(422)->assertJsonValidationErrors('action_type');
        $this->api('POST', '/action-hub/actions', $this->payload(['assigned_to' => 'fmm']))->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->api('POST', '/action-hub/actions', $this->payload(['status' => 'Reschedule']))->assertStatus(422);
        $this->assertSame(0, Action::count());
        $failed = ActionEvent::where('event', 'validation_failed')->get();
        $this->assertCount(5, $failed);
        $this->assertSame(['phone'], $failed->first()->details['fields']);
        $this->assertStringNotContainsString('12345', json_encode(ActionEvent::all()->toArray()));
        $this->assertSame(1, ActionEvent::where('event', 'transition_rejected')->count());

        $other = $this->create(['action_type' => 'Other', 'action_other' => 'Site visit with architect']);
        $this->assertSame('Site visit with architect', $other['action_other']);
    }

    // ── Working days and automatic statuses ────────────────────────────

    public function test_get_ready_starts_two_working_days_before_skipping_sundays_and_holidays(): void
    {
        // Thu 5 Nov: the two working days before are Tue 3 and Wed 4.
        $thu = $this->create();
        $this->assertSame('2026-11-03', $thu['get_ready_from']);
        // Mon 9 Nov: Sunday 8 is skipped → Fri 6 and Sat 7 (Saturday is a working day).
        $mon = $this->create(['scheduled_at' => '2026-11-09T10:00']);
        $this->assertSame('2026-11-06', $mon['get_ready_from']);
        // A holiday on Sat 7 → Thu 5 and Fri 6.
        $this->api('POST', '/action-hub/holidays', ['date' => '2026-11-07', 'name' => 'Test holiday'])->assertCreated();
        $this->assertSame('2026-11-05', collect($this->api('GET', '/action-hub/actions')->json('data'))->firstWhere('id', $mon['id'])['get_ready_from']);

        $this->at('2026-11-02 23:59');
        $this->assertSame(0, ActionHub::refresh());
        $this->at('2026-11-03 00:00'); // first Get Ready day, local midnight
        $this->artisan('action-hub:refresh-statuses')->assertSuccessful();
        $this->artisan('action-hub:refresh-statuses')->assertSuccessful(); // repeated run
        ActionHub::refresh();
        $this->assertSame('Get Ready', Action::find($thu['id'])->status);
        $this->assertSame('On Track', Action::find($mon['id'])->status);
        $this->assertSame(1, ActionEvent::where('action_id', $thu['id'])->where('event', 'auto_get_ready')->count());
        $e = ActionEvent::where('event', 'auto_get_ready')->first();
        $this->assertSame(['automatic', 'On Track', 'Get Ready', null], [$e->source, $e->old_status, $e->new_status, $e->user_id]);
    }

    public function test_overdue_uses_date_and_time_and_is_logged_once(): void
    {
        $a = $this->create();
        $this->at('2026-11-05 10:59');
        ActionHub::refresh();
        $this->assertSame('Get Ready', Action::find($a['id'])->status);
        $this->at('2026-11-05 11:00');
        $res = $this->api('GET', '/action-hub/actions')->assertOk(); // reading applies due changes
        $this->assertSame('Delayed', $res->json('data.0.status'));
        $this->assertTrue($res->json('data.0.overdue'));
        $this->assertSame($a['id'], $res->json('overdue.0.id'));
        $this->assertSame('Appointment overdue — reschedule this project.', $res->json('config.overdue_message'));
        // "All statuses" → Delayed lists overdue appointments; the banner list ignores the filters.
        $this->assertSame([$a['id']], array_column($this->api('GET', '/action-hub/actions', ['status' => 'Delayed'])->json('data'), 'id'));
        $onTrack = $this->api('GET', '/action-hub/actions', ['status' => 'On Track'])->assertOk();
        $this->assertSame([], $onTrack->json('data'));
        $this->assertSame($a['id'], $onTrack->json('overdue.0.id'));
        $this->api('GET', '/action-hub/actions');
        $this->artisan('action-hub:refresh-statuses');
        $this->assertSame(['created', 'submitted', 'auto_get_ready', 'auto_delayed'], $this->events($a['id']));
    }

    public function test_an_action_created_for_a_close_date_starts_as_get_ready(): void
    {
        $soon = $this->create(['scheduled_at' => '2026-11-04T10:00']); // Get Ready from Mon 2 Nov (today)
        $this->assertSame('Get Ready', $soon['status']);
        $this->assertSame(['created', 'auto_get_ready', 'submitted'], $this->events($soon['id']));
        $e = ActionEvent::where('action_id', $soon['id'])->where('event', 'auto_get_ready')->first();
        $this->assertSame(['automatic', 'On Track', 'Get Ready', null], [$e->source, $e->old_status, $e->new_status, $e->user_id]);
        $later = $this->create(['scheduled_at' => '2026-11-02T10:01']); // later today, one minute ahead
        $this->assertSame('Get Ready', $later['status']);
    }

    // ── New actions cannot be scheduled in the past (requested 2026-11-01) ──

    public function test_a_new_action_cannot_be_scheduled_in_the_past(): void
    {
        // Now: Mon 2 Nov 2026, 10:00 Asia/Kolkata (04:30 UTC).
        $res = $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-11-01T10:00']))->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->assertSame('The scheduled date and time (01 Nov 2026, 10:00) have already passed. Choose a date and time in the future.', $res->json('message'));
        $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-11-02T09:59']))->assertStatus(422)->assertJsonValidationErrors('scheduled_at'); // earlier today
        $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-11-02T10:00']))->assertStatus(422); // this very minute
        $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-10-15T10:00', 'status' => 'Completed']))->assertStatus(422); // whatever the status
        // 05:00 is in the future in UTC terms (05:00 UTC > 04:30 UTC) but is 05:00 IST — already passed.
        $this->api('POST', '/action-hub/actions', $this->payload(['scheduled_at' => '2026-11-02T05:00']))->assertStatus(422);
        $this->assertSame(0, Action::count());
        $this->assertSame(5, ActionEvent::where('event', 'transition_rejected')->whereNull('action_id')->count());
        $this->assertStringNotContainsString('98765', json_encode(ActionEvent::all()->toArray())); // no phone numbers in the log

        $ok = $this->create(['scheduled_at' => '2026-11-02T10:01']);
        $this->assertSame('2026-11-02T10:01', $ok['scheduled_local']);
        // The automatic rules still apply to it: Delayed once that minute has passed.
        $this->at('2026-11-02 10:01');
        $this->assertSame('Delayed', $this->current()['status']);
    }

    // ── Description in capital letters (requested 2026-11-01) ─────────

    public function test_description_is_saved_and_shown_in_capital_letters(): void
    {
        $a = $this->create(['description' => '  Fix lock #2 (rear) — 50% done; mail ravi.k@Example.com or see https://Example.com/Site?Id=7a  ']);
        $this->assertSame('FIX LOCK #2 (REAR) — 50% DONE; MAIL ravi.k@Example.com OR SEE https://Example.com/Site?Id=7a', $a['description']);
        $this->assertSame($a['description'], Action::find($a['id'])->description); // stored in capitals
        $this->assertSame('9876543210', $a['phone']); // other fields untouched
        $this->assertSame('Fix the sliding window lock', $this->payload()['description']);

        // A record saved before this rule (lower case in the database) is shown in capitals without changing it.
        Action::whereKey($a['id'])->update(['description' => '[DEMO] Check the hinge — floor 1']);
        $this->assertSame('[DEMO] CHECK THE HINGE — FLOOR 1', $this->current()['description']);
        $this->assertSame('[DEMO] Check the hinge — floor 1', Action::find($a['id'])->description);
        $this->assertSame(1, count($this->api('GET', '/action-hub/actions', ['q' => 'hinge'])->json('data'))); // search ignores case
        $report = collect($this->api('GET', '/reports/action-hub')->json('sections'))->firstWhere('title', 'Current actions');
        $this->assertSame('[DEMO] CHECK THE HINGE — FLOOR 1', $report['rows'][0]['description']);
        $this->assertArrayNotHasKey('proper', collect($report['columns'])->firstWhere('key', 'description')); // not turned into proper case in the PDF

        $edited = $this->submit($this->current(), ['description' => 'replace handle'])->assertOk()->json('data');
        $this->assertSame('REPLACE HANDLE', $edited['description']);
        $this->assertSame('', $this->submit($edited, ['description' => ''])->assertOk()->json('data.description'));
    }

    // ── Action labels (requested 2026-11-01) ───────────────────────────

    public function test_renamed_action_labels_keep_old_values_working(): void
    {
        $labels = $this->api('GET', '/action-hub/actions')->json('config.action_labels');
        $this->assertSame('Scheduled Measurements', $labels['Measurements Scheduled']);
        $this->assertSame('Scheduled Installation', $labels['Installation']);
        $this->assertSame('Snag', $labels['Snag']);

        // The page sends the stored value; an API client may send the new label. Both are stored as the value.
        $m = $this->create(['action_type' => 'Measurements Scheduled']);
        $i = $this->create(['action_type' => 'Scheduled Installation', 'scheduled_at' => '2026-11-20T10:00']);
        $this->assertSame(['Measurements Scheduled', 'Scheduled Measurements'], [$m['action_type'], $m['action_label']]);
        $this->assertSame(['Installation', 'Scheduled Installation'], [$i['action_type'], $i['action_label']]);
        $this->assertSame('Installation', Action::find($i['id'])->action_type);
        $this->assertSame('Snag', $this->create(['action_type' => 'Snag', 'scheduled_at' => '2026-11-21T10:00'])['action_label']);
        $this->api('POST', '/action-hub/actions', $this->payload(['action_type' => 'Scheduled Snag']))->assertStatus(422)->assertJsonValidationErrors('action_type');

        // Records stored with the old value (as the demo data is) still filter, edit and report.
        $this->assertSame([$i['id']], array_column($this->api('GET', '/action-hub/actions', ['action' => 'Installation'])->json('data'), 'id'));
        $this->submit($i, ['assigned_to' => 'Akram'])->assertOk()->assertJsonPath('data.action_label', 'Scheduled Installation');
        $r = $this->api('GET', '/reports/action-hub', ['action' => 'Installation'])->assertOk()->json();
        $this->assertSame(['Scheduled Installation'], array_column($r['sections'][0]['rows'], 'action'));
        $this->assertSame(['label' => 'Action', 'value' => 'Scheduled Installation'], $r['filters'][0]);
    }

    public function test_completed_and_aborted_actions_are_never_changed_automatically(): void
    {
        $done = $this->create();
        $this->submit($done, ['status' => 'Completed'])->assertOk()->assertJsonPath('data.status', 'Completed');
        $gone = $this->create(['scheduled_at' => '2026-11-04T09:00']);
        $this->submit($gone, ['status' => 'Abort', 'reason' => 'Customer cancelled'])->assertOk();
        $autoBefore = ActionEvent::whereIn('event', ['auto_delayed', 'auto_get_ready'])->count(); // $gone started as Get Ready
        $this->at('2026-12-01 10:00');
        ActionHub::refresh();
        $this->assertSame('Completed', Action::find($done['id'])->status);
        $this->assertSame('Abort', Action::find($gone['id'])->status);
        $this->assertSame($autoBefore, ActionEvent::whereIn('event', ['auto_delayed', 'auto_get_ready'])->count()); // nothing automatic after closing
        $this->assertContains('completed', $this->events($done['id']));
        $this->assertContains('aborted', $this->events($gone['id']));
        // A closed action cannot go back to a pending status except by Reschedule.
        $closed = $this->api('GET', '/action-hub/actions')->json('data');
        $this->submit(collect($closed)->firstWhere('id', $done['id']), ['status' => 'On Track'])->assertStatus(422);
    }

    // ── Reschedule ─────────────────────────────────────────────────────

    public function test_reschedule_clears_delayed_recalculates_and_keeps_the_history(): void
    {
        $a = $this->create();
        $this->at('2026-11-05 12:00');
        $delayed = $this->api('GET', '/action-hub/actions')->json('data.0');
        $this->assertSame('Delayed', $delayed['status']);

        // Reschedule needs a new, future date and time.
        $this->submit($delayed, ['status' => 'Reschedule'])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->submit($delayed, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-05T11:30'])->assertStatus(422);
        // Overdue: On Track / Get Ready are refused.
        $this->submit($delayed, ['status' => 'On Track'])->assertStatus(422);
        $this->assertSame(3, ActionEvent::where('action_id', $a['id'])->where('event', 'transition_rejected')->count());

        $res = $this->submit($delayed, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-09T10:00', 'reason' => 'Customer travelling'])->assertOk();
        $this->assertSame('On Track', $res->json('data.status')); // Get Ready only from Fri 6 Nov
        $this->assertSame('2026-11-09T10:00', $res->json('data.scheduled_local'));
        // Nobody opened the page between Monday and Thursday noon: straight to Delayed, logged once.
        $this->assertSame(['created', 'submitted', 'auto_delayed', 'transition_rejected', 'transition_rejected', 'transition_rejected', 'reschedule_requested', 'rescheduled', 'status_changed', 'submitted'], $this->events($a['id']));
        $r = ActionEvent::where('event', 'rescheduled')->first();
        $this->assertSame('2026-11-05 05:30:00', $r->old_scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-09 04:30:00', $r->new_scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['Customer travelling', 'COO', 'Delayed'], [$r->reason, $r->user_name, $r->old_status]);
        $req = ActionEvent::where('event', 'reschedule_requested')->first();
        $this->assertSame(['Delayed', 'Reschedule', null], [$req->old_status, $req->new_status, $req->new_scheduled_at]);
        $this->assertSame('2026-11-05 05:30:00', $req->old_scheduled_at->utc()->format('Y-m-d H:i:s'));

        // Rescheduled inside the Get Ready window → Get Ready straight away.
        $this->at('2026-11-06 09:00');
        $now = $this->api('GET', '/action-hub/actions')->json('data.0');
        $this->assertSame('Get Ready', $now['status']);
        $this->submit($now, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-07T15:00'])->assertOk()->assertJsonPath('data.status', 'Get Ready');
        $this->assertSame(2, ActionEvent::where('action_id', $a['id'])->where('event', 'rescheduled')->count());
    }

    // ── Edits, concurrency and retries ─────────────────────────────────

    public function test_edit_assignee_change_and_stale_version(): void
    {
        $a = $this->create();
        $res = $this->submit($a, ['assigned_to' => 'FMW', 'description' => 'Replace the lock'])->assertOk();
        $this->assertSame(2, $res->json('data.version'));
        $this->assertSame(['created', 'submitted', 'assignee_changed', 'edited', 'submitted'], $this->events($a['id']));
        $ev = ActionEvent::where('event', 'assignee_changed')->first();
        $this->assertSame(['Arif', 'FMW'], [$ev->old_assignee, $ev->new_assignee]);
        $this->assertSame(['description'], ActionEvent::where('event', 'edited')->first()->details['fields']);

        // The old copy (version 1) can no longer overwrite the newer save.
        $this->submit($a, ['assigned_to' => 'Aamir'])->assertStatus(409)->assertJsonPath('current.assigned_to', 'FMW');
        $this->assertSame('FMW', Action::first()->assigned_to);
        $this->assertSame(1, ActionEvent::where('event', 'stale_rejected')->count());
    }

    public function test_a_retried_submit_is_saved_once(): void
    {
        $a = $this->create();
        $rid = (string) Str::uuid();
        $this->submit($a, ['assigned_to' => 'Ashu', 'request_id' => $rid])->assertOk()->assertJsonPath('replayed', false);
        $this->submit($a, ['assigned_to' => 'Ashu', 'request_id' => $rid])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.version', 2);
        $this->assertSame(2, ActionEvent::where('action_id', $a['id'])->where('event', 'submitted')->count()); // create + one submit
        $this->assertSame(1, ActionEvent::where('event', 'assignee_changed')->count());
    }

    public function test_history_endpoint_lists_every_event_in_order(): void
    {
        $a = $this->create();
        $this->submit($a, ['status' => 'Completed'])->assertOk();
        $h = $this->api('GET', '/action-hub/actions/'.$a['id'].'/history')->assertOk()->json('data');
        $this->assertSame(['created', 'submitted', 'completed', 'submitted'], array_column($h, 'event'));
        $this->assertSame('COO', $h[2]['user']);
        $this->assertStringStartsWith('2026-11-02T10:00:00', $h[0]['at']);
    }

    // ── Report ─────────────────────────────────────────────────────────

    public function test_report_respects_filters_and_includes_history(): void
    {
        $a = $this->create();
        $this->create(['customer' => 'Ravi Kumar', 'project' => 'Lake House', 'assigned_to' => 'Aamir', 'action_type' => 'Installation', 'scheduled_at' => '2026-11-20T10:00']);
        $this->at('2026-11-05 12:00');
        $delayed = $this->api('GET', '/action-hub/actions', ['assignee' => 'Arif'])->json('data.0');
        $this->submit($delayed, ['status' => 'Reschedule', 'scheduled_at' => '2026-11-10T10:00', 'reason' => 'Rain'])->assertOk();

        $r = $this->api('GET', '/reports/action-hub')->assertOk()->json();
        $sections = collect($r['sections'])->keyBy('title');
        $this->assertCount(2, $sections['Current actions']['rows']);
        $this->assertTrue(collect($sections['Current actions']['columns'])->firstWhere('key', 'customer')['proper']);
        $this->assertCount(1, $sections['Reschedule history']['rows']);
        $this->assertSame('Rain', $sections['Reschedule history']['rows'][0]['reason']);
        // Automatic Delayed, then the reschedule to Tue 10 Nov recalculated it to On Track (Get Ready from Sat 7).
        $history = $sections['Status history (manual and automatic)']['rows'];
        $this->assertSame([['On Track', 'Delayed', 'Automatic', 'System'], ['Delayed', 'On Track', 'Manual', 'COO']], array_map(fn ($r) => [$r['from'], $r['to'], $r['source'], $r['by']], $history));
        $this->assertArrayNotHasKey('Audit history', $sections->all());

        $amir = $this->api('GET', '/reports/action-hub', ['assignee' => 'Aamir', 'history' => 1])->json();
        $s = collect($amir['sections'])->keyBy('title');
        $this->assertSame(['RAVI KUMAR'], array_column($s['Current actions']['rows'], 'customer'));
        // Installation gets a ticket when saved; Submit never sends WhatsApp, so nothing about it is in the history.
        $this->assertSame(['Created', 'Submit saved'], array_column($s['Audit history']['rows'], 'event'));
        $this->assertSame('PV-INSTL-2611-001', $s['Current actions']['rows'][0]['ticket']);
        $this->assertSame(1, count($this->api('GET', '/reports/action-hub', ['from' => '2026-11-15', 'to' => '2026-11-30'])->json('sections.0.rows')));
        $this->assertSame(0, count($this->api('GET', '/reports/action-hub', ['status' => 'Delayed'])->json('sections.0.rows')));
        $this->assertSame(1, count($this->api('GET', '/reports/action-hub', ['q' => 'lake'])->json('sections.0.rows')));
        $this->api('GET', '/reports/action-hub', ['status' => 'Lost'])->assertStatus(422);
        $this->api('GET', '/reports/action-hub', ['from' => '2026-11-30', 'to' => '2026-11-01'])->assertStatus(422);
    }

    // ── Rescheduling earlier / later / same day / past (requested 2026-10-31) ──

    /** The one action on the page, as the page has it now (statuses refreshed). */
    private function current(): array
    {
        return $this->api('GET', '/action-hub/actions')->assertOk()->json('data.0');
    }

    private function reschedule(array $action, string $to, ?string $reason = null)
    {
        return $this->submit($action, ['status' => 'Reschedule', 'scheduled_at' => $to, 'reason' => $reason]);
    }

    /** [old, new] local times of every 'rescheduled' audit row, oldest first. */
    private function rescheduleTrail(): array
    {
        return ActionEvent::where('event', 'rescheduled')->orderBy('id')->get()
            ->map(fn ($e) => [ActionHub::local($e->old_scheduled_at), ActionHub::local($e->new_scheduled_at)])->all();
    }

    public function test_reschedule_earlier_and_later_before_the_appointment(): void
    {
        $this->at('2026-11-01 10:00'); // today 1 Nov, appointment 5 Nov
        $a = $this->create(['scheduled_at' => '2026-11-05T11:00']);
        $this->assertSame(['On Track', '2026-11-03'], [$a['status'], $a['get_ready_from']]);

        // Earlier, 4 Nov: allowed; Get Ready from Mon 2 Nov → still On Track today.
        $r = $this->reschedule($a, '2026-11-04T11:00', 'Customer asked for an earlier visit')->assertOk()->json('data');
        $this->assertSame(['On Track', '2026-11-02'], [$r['status'], $r['get_ready_from']]);
        // Earlier again, 3 Nov: Get Ready from Sat 31 Oct (Sunday 1 Nov skipped) → Get Ready now.
        $r = $this->reschedule($r, '2026-11-03T11:00')->assertOk()->json('data');
        $this->assertSame(['Get Ready', '2026-10-31'], [$r['status'], $r['get_ready_from']]);
        // Later, 20 Nov: recalculated from the new date — no longer Get Ready.
        $r = $this->reschedule($r, '2026-11-20T11:00', 'Material delayed')->assertOk()->json('data');
        $this->assertSame('On Track', $r['status']);

        $this->assertSame([
            ['2026-11-05T11:00', '2026-11-04T11:00'], ['2026-11-04T11:00', '2026-11-03T11:00'], ['2026-11-03T11:00', '2026-11-20T11:00'],
        ], $this->rescheduleTrail());
        $first = ActionEvent::where('event', 'rescheduled')->orderBy('id')->first();
        $this->assertSame(['Customer asked for an earlier visit', 'COO', 'manual'], [$first->reason, $first->user_name, $first->source]);
        $this->assertSame('2026-11-01 04:30:00', $first->created_at->utc()->format('Y-m-d H:i:s')); // server time of the reschedule
        $this->assertSame(1, ActionEvent::where('event', 'status_changed')->where('new_status', 'Get Ready')->count());
        $this->assertSame(1, ActionEvent::where('event', 'status_changed')->where('old_status', 'Get Ready')->where('new_status', 'On Track')->count());
    }

    public function test_same_day_reschedule_only_to_a_later_time_that_day(): void
    {
        $this->create(['scheduled_at' => '2026-11-05T15:00']);
        $this->at('2026-11-05 09:00'); // the appointment is later today
        $a = $this->current();
        $this->assertSame('Get Ready', $a['status']);

        $res = $this->reschedule($a, '2026-11-04T15:00')->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->assertSame('The new appointment date and time (04 Nov 2026, 15:00) have already passed. Choose a date and time in the future.', $res->json('message'));
        $this->reschedule($a, '2026-11-05T08:59')->assertStatus(422)->assertJsonValidationErrors('scheduled_at'); // earlier today, already passed
        $this->reschedule($a, '2026-11-05T09:00')->assertStatus(422); // this very minute
        $this->assertSame('2026-11-05T15:00', $this->current()['scheduled_local']); // nothing saved

        $r = $this->reschedule($a, '2026-11-05T17:00')->assertOk()->json('data');
        $this->assertSame(['Get Ready', '2026-11-05T17:00'], [$r['status'], $r['scheduled_local']]);
        $this->at('2026-11-05 17:00');
        $this->assertSame('Delayed', $this->current()['status']); // overdue at the new time
    }

    public function test_after_the_appointment_has_passed_only_a_future_date_is_accepted(): void
    {
        $this->create(['scheduled_at' => '2026-11-05T11:00']);
        $this->at('2026-11-06 10:00');
        $a = $this->current();
        $this->assertSame('Delayed', $a['status']);

        $this->reschedule($a, '2026-11-03T11:00')->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->reschedule($a, '2026-11-04T11:00')->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->reschedule($a, '2026-11-06T09:00')->assertStatus(422); // earlier today
        $this->assertSame(3, ActionEvent::where('event', 'transition_rejected')->count());

        $r = $this->reschedule($a, '2026-11-07T11:00', 'Customer back from travel')->assertOk()->json('data');
        $this->assertSame('Get Ready', $r['status']); // Sat 7 Nov: Get Ready from Thu 5 Nov
        $this->assertSame([['2026-11-05T11:00', '2026-11-07T11:00']], $this->rescheduleTrail());
        $this->assertSame(1, ActionEvent::where('event', 'auto_delayed')->count()); // the delay stays in the history
    }

    public function test_changing_the_date_without_choosing_reschedule_follows_the_same_rule(): void
    {
        $a = $this->create(['scheduled_at' => '2026-11-05T11:00']);
        $this->submit($a, ['scheduled_at' => '2026-11-01T10:00'])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->submit($a, ['scheduled_at' => '2026-11-02T09:59'])->assertStatus(422);
        $this->submit($a, ['status' => 'Completed', 'scheduled_at' => '2026-11-01T10:00'])->assertStatus(422);
        $this->assertSame(0, ActionEvent::where('event', 'rescheduled')->count());
        // A future date edited in place is a reschedule: recalculated and recorded.
        $r = $this->submit($a, ['scheduled_at' => '2026-11-04T09:00'])->assertOk()->json('data');
        $this->assertSame(['Get Ready', 1], [$r['status'], ActionEvent::where('event', 'rescheduled')->count()]);

        // An overdue appointment can still be closed without touching its date.
        $this->at('2026-11-06 10:00');
        $this->submit($this->current(), ['status' => 'Completed'])->assertOk()->assertJsonPath('data.status', 'Completed');
    }

    public function test_get_ready_threshold_across_sunday_and_a_holiday(): void
    {
        $this->at('2026-10-31 10:00'); // Saturday
        $wed = $this->create(['scheduled_at' => '2026-11-04T11:00']);
        $mon = $this->create(['scheduled_at' => '2026-11-09T10:00']);
        $this->assertSame(['On Track', '2026-11-02'], [$wed['status'], $wed['get_ready_from']]); // Mon 2, Tue 3 (Sun 1 skipped)
        $this->assertSame(['On Track', '2026-11-06'], [$mon['status'], $mon['get_ready_from']]); // Fri 6, Sat 7 (Sun 8 skipped)

        $status = fn (array $x) => Action::find($x['id'])->status;
        $this->at('2026-11-01 23:59');
        ActionHub::refresh();
        $this->assertSame('On Track', $status($wed)); // 3 working days away
        $this->at('2026-11-02 00:00');
        ActionHub::refresh();
        $this->assertSame('Get Ready', $status($wed)); // exactly 2 working days away
        $this->at('2026-11-04 10:59');
        ActionHub::refresh();
        $this->assertSame('Get Ready', $status($wed));
        $this->at('2026-11-04 11:00');
        ActionHub::refresh();
        $this->assertSame('Delayed', $status($wed)); // overdue at the scheduled time
        $this->at('2026-11-05 23:59');
        ActionHub::refresh();
        $this->assertSame('On Track', $status($mon));
        $this->at('2026-11-06 00:00');
        ActionHub::refresh();
        $this->assertSame('Get Ready', $status($mon));

        // Holiday on Sat 14 Nov: an appointment on Mon 16 Nov gets Get Ready from Thu 12 (Fri 13, Thu 12).
        $this->api('POST', '/action-hub/holidays', ['date' => '2026-11-14', 'name' => 'Test holiday'])->assertCreated();
        $hol = $this->create(['scheduled_at' => '2026-11-16T10:00']);
        $this->assertSame('2026-11-12', $hol['get_ready_from']);
        $this->at('2026-11-11 23:59');
        ActionHub::refresh();
        $this->assertSame('On Track', $status($hol));
        $this->at('2026-11-12 00:00');
        ActionHub::refresh();
        $this->assertSame('Get Ready', $status($hol));
    }

    public function test_get_ready_uses_the_latest_rescheduled_date(): void
    {
        $a = $this->create(['scheduled_at' => '2026-11-09T10:00']); // today Mon 2 Nov
        $this->assertSame(['On Track', '2026-11-06'], [$a['status'], $a['get_ready_from']]);
        $r = $this->reschedule($a, '2026-11-05T10:00')->assertOk()->json('data'); // 9 → 5 Nov
        $this->assertSame(['On Track', '2026-11-03'], [$r['status'], $r['get_ready_from']]);
        $this->at('2026-11-03 00:00');
        $this->assertSame('Get Ready', $this->current()['status']);
        $this->at('2026-11-05 10:00');
        $this->assertSame('Delayed', $this->current()['status']);
        $this->assertSame(['auto_get_ready', 'auto_delayed'], ActionEvent::where('source', 'automatic')->orderBy('id')->pluck('event')->all());
    }

    public function test_using_action_hub_does_not_use_up_the_report_limit(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->api('GET', '/action-hub/actions')->assertOk();
        }
        $this->api('GET', '/reports/action-hub')->assertOk(); // reports keep their own 30-per-minute counter
    }

    public function test_holidays_are_managed_by_admins(): void
    {
        $this->api('POST', '/action-hub/holidays', ['date' => '2026-11-01', 'name' => 'Kannada Rajyotsava'])->assertCreated()->assertJsonPath('holidays.0.date', '2026-11-01');
        $this->api('POST', '/action-hub/holidays', ['date' => '2026-11-01', 'name' => 'Again'])->assertStatus(422);
        $id = Holiday::first()->id;
        $this->api('DELETE', '/action-hub/holidays/'.$id)->assertOk()->assertJsonPath('holidays', []);
    }
}
