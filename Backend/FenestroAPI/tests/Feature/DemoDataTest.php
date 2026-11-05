<?php

namespace Tests\Feature;

use App\Models\ActionHub\Action;
use App\Models\Lead;
use App\Models\Measurement;
use App\Models\OrderManagement\Order;
use App\Models\User;
use App\Support\LeadWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** php artisan demo:data — repeatable, complete, valid, and never touches real records. */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00', 'Asia/Kolkata')->utc()); // Tuesday
    }

    private function realRecords(): array
    {
        $partner = User::create(['name' => 'Real Partner', 'phone' => '9876500001', 'role' => 'referral_partner', 'is_active' => 1]);
        $lead = LeadWorkflow::create(['client_name' => 'Real Customer', 'client_phone' => '9876500002', 'type' => 'B2C', 'referral_partner_id' => $partner->id, 'notes' => 'keep me'], null);

        return [$partner, $lead];
    }

    public function test_seeding_is_complete_valid_and_repeatable_and_removal_leaves_real_data(): void
    {
        [$partner, $realLead] = $this->realRecords();
        $realBefore = $realLead->fresh()->toArray();

        $this->artisan('demo:data')->assertSuccessful();
        $demo = Lead::where('is_demo', true)->orderBy('id')->get();
        $ids = $demo->pluck('lead_no', 'client_phone')->all();

        // Coverage: all 14 statuses and every RAG state, closed and archived records.
        $this->assertGreaterThanOrEqual(20, $demo->count());
        $this->assertEqualsCanonicalizing(array_keys(LeadWorkflow::statuses()), $demo->pluck('status_code')->unique()->values()->all());
        $rags = $demo->map(fn (Lead $l) => LeadWorkflow::rag($l));
        foreach (['red', 'amber', 'green', 'neutral'] as $c) {
            $this->assertContains($c, $rags->pluck('rag')->all(), "no $c demo lead");
        }
        foreach (['Get ready', 'Discuss quotation with Customer', 'Measurement overdue', 'Closed Lead - No response'] as $m) {
            $this->assertContains($m, $rags->pluck('message')->all(), "no demo lead showing \"$m\"");
        }
        $this->assertSame(1, $demo->where('lifecycle', 'closed')->count());
        $this->assertSame(3, $demo->where('lifecycle', 'archived')->count());
        $this->assertContains('green', $demo->where('status_code', 'MSRMT-FULL')->map(fn ($l) => LeadWorkflow::rag($l)['rag'])->all());

        // Validity: IDs, assignees, partners, schedules, completion dates, history, measurements.
        foreach ($demo as $l) {
            $this->assertMatchesRegularExpression('/^PV-LD-\d{4}-\d{3}$/', $l->lead_no);
            $this->assertStringStartsWith('[DEMO] ', $l->client_name);
            $this->assertTrue($l->assigned_to === null || in_array($l->assigned_to, LeadWorkflow::assignees(), true));
            $this->assertTrue($l->referral_partner_id === null || User::whereKey($l->referral_partner_id)->where('role', 'referral_partner')->exists());
            if (in_array($l->status_code, LeadWorkflow::SCHEDULED, true)) {
                $this->assertNotNull($l->scheduled_at, $l->lead_no);
            }
            if ($l->status_code === 'MSRMT-FULL') {
                $this->assertNotNull($l->measured_at);
                $this->assertSame('submitted', $l->measurement?->status);
            }
            $this->assertGreaterThan(0, $l->events()->count());
        }
        $this->assertGreaterThan(0, $demo->filter(fn ($l) => $l->events()->where('event', 'rescheduled')->exists())->count());
        $this->assertSame(6, Action::where('customer', 'like', '[DEMO]%')->count());
        $this->assertGreaterThan(0, Order::where('code', 'like', 'PV-DEMO-%')->count());
        $this->assertSame(4, User::where('name', 'like', '[DEMO]%')->count());

        // Repeatable: same records, same IDs, no duplicates.
        $this->artisan('demo:data')->assertSuccessful();
        $this->assertSame($demo->count(), Lead::where('is_demo', true)->count());
        $this->assertSame($ids, Lead::where('is_demo', true)->orderBy('id')->pluck('lead_no', 'client_phone')->all());
        $this->assertSame(6, Action::where('customer', 'like', '[DEMO]%')->count());
        $this->assertSame(4, User::where('name', 'like', '[DEMO]%')->count());
        $this->assertSame(Measurement::count(), Lead::where('is_demo', true)->whereIn('status_code', ['MSRMT-FULL', 'MSRMT-INC'])->count());

        // Real records untouched by seeding and by removal.
        $this->assertEquals($realBefore, $realLead->fresh()->toArray());
        $this->artisan('demo:data --remove')->assertSuccessful();
        $this->assertSame(0, Lead::where('is_demo', true)->count());
        $this->assertSame(0, Action::where('customer', 'like', '[DEMO]%')->count());
        $this->assertSame(0, User::where('name', 'like', '[DEMO]%')->count());
        $this->assertSame(0, Order::where('code', 'like', 'PV-DEMO-%')->count());
        $this->assertEquals($realBefore, $realLead->fresh()->toArray());
        $this->assertTrue($partner->fresh()->exists);

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_a_real_account_on_a_demo_number_is_never_taken_over(): void
    {
        User::create(['name' => 'Someone Real', 'phone' => '9000020001', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->artisan('demo:data')->assertSuccessful();
        $this->assertSame('Someone Real', User::where('phone', '9000020001')->value('name'));
        $this->artisan('demo:data --remove')->assertSuccessful();
        $this->assertTrue(User::where('phone', '9000020001')->exists());
    }

    public function test_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->artisan('demo:data')->assertFailed();
            $this->assertSame(0, Lead::count());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
