<?php

namespace Tests\Feature;

use App\Models\ActionHub\Holiday;
use App\Models\Lead;
use App\Models\User;
use App\Support\ActionHub;
use App\Support\LeadWorkflow;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leads workflow: Lead IDs, the 14 statuses, Red/Amber/Green on working days, closing and archiving,
 * assignment / scheduling / notes, WhatsApp drafts (nothing is ever sent) and access rules.
 * All dates are Asia/Kolkata. 2026-11-03 is a Tuesday.
 */
class LeadWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        ActionHub::forgetHolidays();
        $this->admin = User::create(['name' => 'COO', 'email' => 'coo@example.test', 'phone' => '9000000001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        $this->partner = User::create(['name' => 'Ravi Partner', 'phone' => '9123400021', 'company' => 'Ravi Interiors', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->at('2026-11-03 10:00');
    }

    private function at(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Kolkata')->utc());
    }

    private function api(string $method, string $url, array $data = [], ?User $as = null)
    {
        return $this->withToken(auth('api')->login($as ?? $this->admin))->json($method, '/api/admin/leads'.$url, $data);
    }

    private function newLead(array $extra = []): array
    {
        return $this->api('POST', '', $extra + ['client_name' => 'Meera Nair', 'client_phone' => '9123400031', 'type' => 'B2C', 'referral_partner_id' => $this->partner->id])
            ->assertCreated()->json('data');
    }

    private function setStatus(array $lead, string $code, array $extra = [])
    {
        $fresh = $this->api('GET', '/'.$lead['id'])->json('data');

        return $this->api('PUT', '/'.$lead['id'], $extra + ['status_code' => $code, 'version' => $fresh['version']]);
    }

    private function rag(int $id): array
    {
        return LeadWorkflow::rag(Lead::findOrFail($id));
    }

    // ── Creation and Lead IDs ───────────────────────────────────────────

    public function test_a_new_lead_is_lead_received_red_with_the_first_monthly_id(): void
    {
        $lead = $this->newLead(['status_code' => 'MSRMT-FULL', 'lead_no' => 'PV-LD-9999-999']); // client values are ignored

        $this->assertSame('PV-LD-2611-001', $lead['lead_no']);
        $this->assertSame('LEAD-RCVD', $lead['status_code']);
        $this->assertSame('Lead Received', $lead['status_label']);
        $this->assertSame(['rag' => 'red', 'message' => null], $lead['rag']);
        $this->assertSame('active', $lead['lifecycle']);
        $this->assertSame('new', Lead::find($lead['id'])->status); // legacy column for older screens
        $this->assertSame('PV-LD-2611-002', $this->newLead()['lead_no']);
        $this->assertSame('PV-LD-2611-003', $this->newLead()['lead_no']);

        $this->at('2026-12-01 09:00');
        $this->assertSame('PV-LD-2612-001', $this->newLead()['lead_no']); // new month, new sequence
    }

    public function test_the_month_uses_the_business_time_zone(): void
    {
        $this->at('2026-12-01 00:30'); // still 30 November in UTC
        $this->assertSame('PV-LD-2612-001', $this->newLead()['lead_no']);
    }

    public function test_ids_never_change_and_are_never_reused(): void
    {
        $a = $this->newLead();
        $b = $this->newLead();
        $this->setStatus($a, 'MSRMT-ABRT-CL')->assertOk()->assertJsonPath('data.lead_no', 'PV-LD-2611-001');
        $this->setStatus($b, 'ATT-1-NP', ['client_name' => 'Meera N'])->assertOk()->assertJsonPath('data.lead_no', 'PV-LD-2611-002');

        Lead::find($b['id'])->delete();
        $this->assertSame('PV-LD-2611-003', $this->newLead()['lead_no']); // 002 is not handed out again
    }

    public function test_concurrent_creation_cannot_produce_duplicate_ids(): void
    {
        // Every ID comes from an atomic counter increment inside the creating transaction ...
        $ids = [];
        foreach (range(1, 25) as $i) {
            $ids[] = LeadWorkflow::create(['client_name' => "C{$i}", 'client_phone' => '9123400031', 'type' => 'B2B'], $this->admin)->lead_no;
        }
        $this->assertCount(25, array_unique($ids));
        $this->assertSame(25, (int) DB::table('lead_id_counters')->where('period', '2611')->value('last_serial'));

        // ... a counter that fell behind (e.g. restored from an old backup) skips IDs already used ...
        DB::table('lead_id_counters')->where('period', '2611')->update(['last_serial' => 3]);
        $this->assertSame('PV-LD-2611-026', LeadWorkflow::create(['client_name' => 'X', 'client_phone' => '9123400031', 'type' => 'B2B'], null)->lead_no);

        // ... and the database itself refuses a duplicate.
        $this->expectException(QueryException::class);
        DB::table('leads')->insert(['lead_no' => 'PV-LD-2611-001', 'client_name' => 'Dup', 'client_phone' => '9123400031', 'type' => 'B2B', 'status' => 'new', 'status_code' => 'LEAD-RCVD']);
    }

    public function test_new_lead_validation(): void
    {
        $this->api('POST', '', ['client_phone' => '12345', 'type' => 'XYZ', 'assigned_to' => 'Nobody', 'referral_partner_id' => $this->admin->id])
            ->assertStatus(422)->assertJsonValidationErrors(['client_name', 'client_phone', 'type', 'assigned_to', 'referral_partner_id']);
        $this->assertSame(0, Lead::count());
    }

    // ── Status dropdown ─────────────────────────────────────────────────

    public function test_exactly_the_fourteen_statuses_in_order(): void
    {
        $statuses = $this->api('GET', '/config')->assertOk()->json('statuses');

        $this->assertSame([
            ['LEAD-RCVD', 'Lead Received'],
            ['ATT-1-NP', 'Attempt 1 - Not Picking'],
            ['ATT-1-CALL-LATER', 'Attempt 1 - Request to Call Later'],
            ['ATT-2-NP', 'Attempt 2 - Not Picking'],
            ['ATT-2-CALL-LATER', 'Attempt 2 - Request to Call Later'],
            ['ATT-3-NP', 'Attempt 3 - Not Picking'],
            ['ATT-3-CALL-LATER', 'Attempt 3 - Request to Call Later'],
            ['MSRMT-SCH', 'Scheduled for Measurement'],
            ['MSRMT-FULL', 'Measurement Taken - Full'],
            ['MSRMT-INC', 'Measurement Taken - Incomplete'],
            ['MSRMT-DLY-RESCLD-ID', 'Measurement Delayed & Rescheduled - Internal Delay'],
            ['MSRMT-DLY-RESCLD-CD', 'Measurement Delayed & Rescheduled - Client Delay'],
            ['MSRMT-ABRT-CL', 'Abort - Client'],
            ['MSRMT-ABRT-PV', 'Abort - Prestige Ventures'],
        ], array_map(fn ($s) => [$s['code'], $s['label']], $statuses));
        $this->assertNotContains('MSRMT-READY', array_column($statuses, 'code'));
        $this->assertSame(['Ashu', 'Aamir', 'Arif', 'Faisal', 'FMW', 'Hadi', 'Akram'], $this->api('GET', '/config')->json('assignees')); // Action Hub list
    }

    public function test_every_status_persists_and_unknown_codes_are_refused(): void
    {
        $lead = $this->newLead();
        foreach (array_keys(LeadWorkflow::statuses()) as $code) {
            $extra = match (true) {
                in_array($code, LeadWorkflow::SCHEDULED, true) => ['scheduled_at' => '2026-11-20T11:00'],
                $code === 'MSRMT-FULL' => ['measured_at' => '2026-11-03T09:30'],
                default => [],
            };
            $this->setStatus($lead, $code, $extra)->assertOk()->assertJsonPath('data.status_code', $code);
            $this->assertSame($code, Lead::find($lead['id'])->status_code);
        }
        $this->setStatus($lead, 'MSRMT-READY')->assertStatus(422)->assertJsonValidationErrors('status_code');
    }

    // ── RAG ─────────────────────────────────────────────────────────────

    public function test_received_and_every_attempt_is_red_and_attempt_3_call_later_closes(): void
    {
        $lead = $this->newLead();
        foreach (['ATT-1-NP', 'ATT-1-CALL-LATER', 'ATT-2-NP', 'ATT-2-CALL-LATER', 'ATT-3-NP'] as $code) {
            $this->setStatus($lead, $code)->assertOk()->assertJsonPath('data.rag.rag', 'red')->assertJsonPath('data.lifecycle', 'active');
        }

        $this->setStatus($lead, 'ATT-3-CALL-LATER')->assertOk()
            ->assertJsonPath('data.rag.rag', 'red')
            ->assertJsonPath('data.lifecycle', 'closed')
            ->assertJsonPath('data.closure_reason', 'Closed Lead - No response')
            ->assertJsonPath('message', 'Lead PV-LD-2611-001 closed — Closed Lead - No response.');

        // Out of the active queue, still listed under Closed, with its full history.
        $this->assertNotContains($lead['id'], array_column($this->api('GET', '?tab=active')->json('data'), 'id'));
        $this->assertContains($lead['id'], array_column($this->api('GET', '?tab=closed')->json('data'), 'id'));
        $events = $this->api('GET', '/'.$lead['id'].'/history')->json('data');
        $this->assertSame('Attempt 3 - Request to Call Later', collect($events)->firstWhere('event', 'closed')['new_status']); // the attempt status is kept
        $this->assertSame(7, collect($events)->whereIn('event', ['created', 'status_changed'])->count());
    }

    public function test_scheduled_measurement_green_amber_and_red(): void
    {
        $lead = $this->newLead();
        // Today Tue 3 Nov. Working days after today: Wed 4, Thu 5, Fri 6, Mon 9 …
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-06T11:00'])->assertOk()
            ->assertJsonPath('data.rag', ['rag' => 'green', 'message' => null])                  // 3 working days away
            ->assertJsonPath('data.scheduled_at', '2026-11-06T11:00')
            ->assertJsonPath('message', 'Measurement scheduled for 06 Nov 2026, 11:00 AM.');
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-05T11:00'])->assertOk()
            ->assertJsonPath('data.rag', ['rag' => 'amber', 'message' => 'Get ready']);           // exactly 2 working days away
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-03T18:00'])->assertOk()
            ->assertJsonPath('data.rag', ['rag' => 'amber', 'message' => 'Get ready']);           // today, even after the time

        $this->at('2026-11-03 23:59');
        $this->assertSame('amber', $this->rag($lead['id'])['rag']);
        $this->at('2026-11-04 00:00');                                                              // the next calendar day
        $this->assertSame(['rag' => 'red', 'message' => 'Measurement overdue'], $this->rag($lead['id']));
    }

    public function test_scheduled_for_3_november_turns_red_on_4_november_in_india_time(): void
    {
        $lead = Lead::find($this->newLead()['id']);
        $lead->forceFill(['status_code' => 'MSRMT-SCH', 'scheduled_at' => CarbonImmutable::parse('2026-11-03 23:30', 'Asia/Kolkata')->utc()])->save();

        $this->at('2026-11-03 20:00');
        $this->assertSame('amber', LeadWorkflow::rag($lead->fresh())['rag']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 19:00', 'UTC')); // 4 Nov 00:30 in India, 3 Nov in UTC
        $this->assertSame('red', LeadWorkflow::rag($lead->fresh())['rag']);
    }

    public function test_weekends_and_holidays_are_not_working_days(): void
    {
        $lead = $this->newLead();
        $this->at('2026-11-06 10:00'); // Friday
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-10T10:00'])->assertJsonPath('data.rag.rag', 'amber'); // Mon, Tue = 2
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-11T10:00'])->assertJsonPath('data.rag.rag', 'green'); // Mon, Tue, Wed = 3
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-08T10:00'])->assertJsonPath('data.rag.rag', 'amber'); // Sunday: 0 working days

        Holiday::create(['date' => '2026-11-09', 'name' => 'Company holiday']);
        ActionHub::forgetHolidays();
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-11T10:00'])->assertJsonPath('data.rag.rag', 'amber'); // Tue, Wed = 2

        $fri = CarbonImmutable::parse('2026-11-06');
        $this->assertSame(1, WorkingDays::countBetween($fri, CarbonImmutable::parse('2026-11-10'), [0, 6])); // only Tue (Mon is the holiday)
        $this->assertSame('2026-11-11', WorkingDays::add($fri, 2, [0, 6])->format('Y-m-d'));
        $this->assertSame(0, WorkingDays::countBetween($fri, $fri, [0, 6]));
    }

    public function test_full_measurement_is_green_for_two_working_days_then_quotation_red(): void
    {
        $lead = $this->newLead();
        $this->at('2026-11-02 15:00'); // Monday
        $this->setStatus($lead, 'MSRMT-FULL', ['measured_at' => '2026-11-02T14:00'])->assertOk()
            ->assertJsonPath('data.rag', ['rag' => 'green', 'message' => null])
            ->assertJsonPath('data.measured_at', '2026-11-02T14:00');
        $this->at('2026-11-04 23:00'); // Wednesday: 2nd working day after
        $this->assertSame('green', $this->rag($lead['id'])['rag']);
        $this->at('2026-11-05 00:01'); // Thursday
        $this->assertSame(['rag' => 'red', 'message' => 'Discuss quotation with Customer'], $this->rag($lead['id']));

        // Across a weekend: measured Friday → green until Tuesday, red on Wednesday.
        $this->at('2026-11-06 12:00');
        $this->setStatus($lead, 'MSRMT-FULL', ['measured_at' => '2026-11-06T11:00'])->assertJsonPath('data.rag.rag', 'green');
        $this->at('2026-11-10 18:00');
        $this->assertSame('green', $this->rag($lead['id'])['rag']);
        $this->at('2026-11-11 09:00');
        $this->assertSame('Discuss quotation with Customer', $this->rag($lead['id'])['message']);

        $this->setStatus($lead, 'MSRMT-FULL', ['measured_at' => null])->assertStatus(422)->assertJsonValidationErrors('measured_at');
    }

    public function test_incomplete_and_rescheduled_statuses_follow_the_new_date_and_keep_the_old_one(): void
    {
        $lead = $this->newLead();
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-04T10:00'])->assertJsonPath('data.rag.rag', 'amber');

        foreach (['MSRMT-INC' => '2026-11-20T10:00', 'MSRMT-DLY-RESCLD-ID' => '2026-11-05T15:00', 'MSRMT-DLY-RESCLD-CD' => '2026-11-25T16:00'] as $code => $date) {
            $expected = $date === '2026-11-05T15:00' ? 'amber' : 'green';
            $this->setStatus($lead, $code, ['scheduled_at' => $date])->assertOk()
                ->assertJsonPath('data.scheduled_at', $date)->assertJsonPath('data.rag.rag', $expected)
                ->assertJsonPath('message', 'Measurement rescheduled for '.CarbonImmutable::parse($date)->format('d M Y, h:i A').'.');
        }
        $this->at('2026-11-26 09:00');
        $this->assertSame('red', $this->rag($lead['id'])['rag']);

        $moves = collect($this->api('GET', '/'.$lead['id'].'/history')->json('data'))->where('event', 'rescheduled')->values();
        // Newest first: each reschedule keeps the date it replaced.
        $this->assertSame(['2026-11-05T15:00', '2026-11-20T10:00', '2026-11-04T10:00'], $moves->pluck('old_scheduled_at')->all());
        $this->assertSame(['2026-11-25T16:00', '2026-11-05T15:00', '2026-11-20T10:00'], $moves->pluck('new_scheduled_at')->all());

        $this->setStatus($lead, 'MSRMT-DLY-RESCLD-CD', ['scheduled_at' => null])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
    }

    // ── Archive ─────────────────────────────────────────────────────────

    public function test_both_aborts_archive_and_keep_everything(): void
    {
        foreach (['MSRMT-ABRT-CL' => 'Abort - Client', 'MSRMT-ABRT-PV' => 'Abort - Prestige Ventures'] as $code => $label) {
            $lead = $this->newLead(['assigned_to' => 'Hadi', 'notes' => "Line 1\nLine 2"]);
            $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-12T10:00']);
            $this->setStatus($lead, $code)->assertOk()
                ->assertJsonPath('data.lifecycle', 'archived')->assertJsonPath('data.closure_reason', $label)
                ->assertJsonPath('data.rag.rag', 'neutral');
            $l = Lead::find($lead['id']);
            $this->assertSame(["Line 1\nLine 2", 'Hadi', '2026-11-12T10:00', $lead['lead_no']], [$l->notes, $l->assigned_to, LeadWorkflow::local($l->scheduled_at), $l->lead_no]);
        }

        $active = $this->api('GET', '?tab=active')->assertOk();
        $this->assertSame([], $active->json('data'));
        $this->assertSame(2, $active->json('counts.archived'));
        $this->assertSame(0, $active->json('counts.active'));
        $this->assertCount(2, $this->api('GET', '?tab=archived')->json('data'));
        $this->assertSame(2, Lead::count()); // nothing deleted
    }

    public function test_a_closed_or_archived_lead_can_be_reopened(): void
    {
        $lead = $this->newLead();
        $this->setStatus($lead, 'MSRMT-ABRT-CL');
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-20T10:00'])->assertOk()
            ->assertJsonPath('data.lifecycle', 'active')->assertJsonPath('data.closure_reason', null)
            ->assertJsonPath('message', 'Lead PV-LD-2611-001 reopened — Scheduled for Measurement.');
    }

    public function test_counts_come_from_the_stored_records(): void
    {
        $a = $this->newLead();
        $b = $this->newLead();
        $c = $this->newLead();
        $d = $this->newLead();
        $e = $this->newLead();
        $this->setStatus($b, 'ATT-1-NP');
        $this->setStatus($c, 'MSRMT-FULL', ['measured_at' => '2026-11-03T09:00']);
        $this->setStatus($d, 'ATT-3-CALL-LATER');

        $this->assertSame(['active' => 4, 'new' => 2, 'in_progress' => 1, 'measured' => 1, 'closed' => 1, 'archived' => 0], $this->api('GET', '')->json('counts'));
        $this->assertEqualsCanonicalizing([$a['id'], $e['id']], array_column($this->api('GET', '?tab=new')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$b['id']], array_column($this->api('GET', '?tab=in_progress')->json('data'), 'id'));
        $this->assertCount(1, $this->api('GET', '?q=PV-LD-2611-003')->json('data'));
    }

    // ── Assignment, scheduling, notes, versions ─────────────────────────

    public function test_assignment_notes_and_schedule_persist_with_history(): void
    {
        $lead = $this->newLead(['assigned_to' => 'Ashu', 'notes' => 'First call: wants sliding windows.']);
        $this->assertSame('Ashu', $lead['assigned_to']);

        $this->setStatus($lead, 'ATT-1-NP', ['assigned_to' => 'Faisal'])->assertOk()->assertJsonPath('data.assigned_to', 'Faisal')
            ->assertJsonPath('data.notes', 'First call: wants sliding windows.'); // notes untouched by a status change
        $this->setStatus($lead, 'ATT-1-NP', ['assigned_to' => 'Ghost'])->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->setStatus($lead, 'ATT-1-NP', ['notes' => "Second call:\n<b>call after 6 pm</b>"])->assertOk()
            ->assertJsonPath('data.notes', "Second call:\n<b>call after 6 pm</b>");                // stored as plain text; the page escapes it

        $history = collect($this->api('GET', '/'.$lead['id'].'/history')->json('data'));
        $this->assertSame(['Ashu', 'Faisal'], [$history->firstWhere('event', 'assigned')['old_assignee'], $history->firstWhere('event', 'assigned')['new_assignee']]);
        $this->assertSame('First call: wants sliding windows.', $history->firstWhere('event', 'note_edited')['old_notes']); // earlier note kept
        $this->assertSame('COO', $history->first()['user']);
    }

    public function test_a_stale_edit_is_refused(): void
    {
        $lead = $this->newLead();
        $this->api('PUT', '/'.$lead['id'], ['status_code' => 'ATT-1-NP', 'version' => $lead['version']])->assertOk();
        $this->api('PUT', '/'.$lead['id'], ['status_code' => 'ATT-2-NP', 'version' => $lead['version']])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'changed by someone else'));
        $this->assertSame('ATT-1-NP', Lead::find($lead['id'])->status_code);
    }

    // ── WhatsApp drafts ─────────────────────────────────────────────────

    public function test_whatsapp_recipients_follow_the_checkboxes_and_nothing_is_sent(): void
    {
        $lead = $this->newLead();
        $this->setStatus($lead, 'MSRMT-SCH', ['scheduled_at' => '2026-11-12T10:30']);
        $wa = fn (bool $p, bool $c) => $this->api('POST', '/'.$lead['id'].'/whatsapp', ['to_partner' => $p, 'to_customer' => $c]);

        $wa(false, false)->assertStatus(422)->assertJsonPath('message', 'Select at least one recipient');

        $partnerOnly = $wa(true, false)->assertOk()->json();
        $this->assertSame(['partner'], array_column($partnerOnly['recipients'], 'kind'));
        $this->assertSame('919123400021', $partnerOnly['recipients'][0]['wa_number']);   // the partner's number, not the lead's
        $this->assertStringContainsString('Status: Scheduled for Measurement', $partnerOnly['recipients'][0]['text']);
        $this->assertStringContainsString('12 Nov 2026 at 10:30 AM', $partnerOnly['recipients'][0]['text']);

        $customerOnly = $wa(false, true)->assertOk()->json();
        $this->assertSame([['customer', '919123400031']], array_map(fn ($r) => [$r['kind'], $r['wa_number']], $customerOnly['recipients']));

        $both = $wa(true, true)->assertOk()->assertJsonPath('mode', 'draft')->json();
        $this->assertSame(['customer', 'partner'], array_column($both['recipients'], 'kind'));
        $this->assertStringContainsString('nothing has been sent', $both['message']);
        $this->assertTrue(Lead::find($lead['id'])->to_partner && Lead::find($lead['id'])->to_customer); // selection saved

        Http::assertNothingSent();
        $logged = collect($this->api('GET', '/'.$lead['id'].'/history')->json('data'))->where('event', 'whatsapp_draft');
        $this->assertCount(3, $logged);
        $this->assertStringNotContainsString('9123400', json_encode($logged->all())); // no phone numbers in the history
    }

    public function test_missing_numbers_are_reported_instead_of_preparing_a_message(): void
    {
        $lead = $this->newLead(['referral_partner_id' => null]); // office lead, no referring partner
        $this->api('POST', '/'.$lead['id'].'/whatsapp', ['to_partner' => true, 'to_customer' => true])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no referring partner'));
        $this->api('POST', '/'.$lead['id'].'/whatsapp', ['to_partner' => false, 'to_customer' => true])->assertOk();

        Lead::whereKey($lead['id'])->update(['client_phone' => '12345']);
        $this->api('POST', '/'.$lead['id'].'/whatsapp', ['to_partner' => false, 'to_customer' => true])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'customer phone number'));
        $this->assertSame(1, Lead::find($lead['id'])->events()->where('event', 'whatsapp_draft')->count()); // only the successful one
    }

    public function test_demo_leads_never_open_a_chat_to_their_fictional_numbers(): void
    {
        $lead = $this->newLead();
        Lead::whereKey($lead['id'])->update(['is_demo' => true]);
        $r = $this->api('POST', '/'.$lead['id'].'/whatsapp', ['to_partner' => true, 'to_customer' => true])->assertOk()->json();
        $this->assertSame([null, null], array_column($r['recipients'], 'wa_number'));
    }

    public function test_the_leads_report_shows_each_status_shortcut_with_its_meaning(): void
    {
        $lead = $this->newLead();
        $this->setStatus($lead, 'MSRMT-DLY-RESCLD-CD', ['scheduled_at' => '2026-11-20T10:00']);
        $rows = collect($this->withToken(auth('api')->login($this->admin))->getJson('/api/admin/reports/leads?status=active')->assertOk()->json('sections'))->firstWhere('title', 'Leads')['rows'];
        $this->assertSame('MSRMT-DLY-RESCLD-CD — Measurement Delayed & Rescheduled - Client Delay', $rows[0]['status']);
        $this->assertSame('PV-LD-2611-001', $rows[0]['lead_no']);
    }

    // ── Access and partners ─────────────────────────────────────────────

    public function test_only_admins_use_the_office_endpoints(): void
    {
        $this->getJson('/api/admin/leads')->assertUnauthorized();
        $this->api('GET', '', [], $this->partner)->assertForbidden();
        $this->api('POST', '', ['client_name' => 'X', 'client_phone' => '9123400031', 'type' => 'B2B'], $this->partner)->assertForbidden();
    }

    public function test_partner_submissions_get_an_id_and_partners_see_a_simple_status(): void
    {
        $token = auth('api')->login($this->partner);
        $this->withToken($token)->postJson('/api/leads', ['client_name' => 'Sunil Rao', 'client_phone' => '9123400041', 'type' => 'B2B'])->assertCreated();
        $lead = Lead::firstOrFail();
        $this->assertSame(['PV-LD-2611-001', 'LEAD-RCVD', $this->partner->id], [$lead->lead_no, $lead->status_code, $lead->referral_partner_id]);

        $this->setStatus(['id' => $lead->id], 'MSRMT-SCH', ['scheduled_at' => '2026-11-20T10:00']);
        $this->withToken($token)->getJson('/api/leads')->assertOk()
            ->assertJsonPath('data.0.partner_status', 'Scheduled')->assertJsonPath('data.0.status', 'scheduled')->assertJsonPath('data.0.lead_no', 'PV-LD-2611-001');
        $this->setStatus(['id' => $lead->id], 'MSRMT-ABRT-PV');
        $this->withToken($token)->getJson('/api/leads')->assertJsonPath('data.0.partner_status', 'Closed');
    }
}
