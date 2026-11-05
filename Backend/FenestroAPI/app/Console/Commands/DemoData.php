<?php

namespace App\Console\Commands;

use App\Models\ActionHub\Action;
use App\Models\ActionHub\ActionEvent;
use App\Models\Lead;
use App\Models\Measurement;
use App\Models\User;
use App\Support\ActionHub;
use App\Support\LeadWorkflow;
use App\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development/test-only demo data for the whole admin site.
 *
 *   php artisan demo:data           add (or reset) the demo data
 *   php artisan demo:data --remove  delete the demo data only
 *
 * - Refuses to run when APP_ENV=production.
 * - Leads: is_demo = 1, names tagged "[DEMO]", fictional numbers 90000-3xxxx. Every one of the 14
 *   statuses and every Red/Amber/Green state, with dates relative to TODAY (working days), so the
 *   colours stay correct whenever it is run. History is written through the real workflow.
 * - Referral partners: "[DEMO]" names with fictional numbers 90000-2000x (3 approved, 1 pending).
 * - Measurements for the measured leads; Action Hub appointments tagged "[DEMO]" (numbers 90000-4xxxx,
 *   no ticket numbers, so the real ticket serial is untouched).
 * - Orders / CNS / Receivables / Archive / Inventory: the existing `orders:demo` (PV-DEMO-*).
 * - Idempotent: re-running resets the same records (Lead IDs are kept) instead of adding duplicates.
 * - Real records are never read for changes, updated or deleted; nothing is sent (no WhatsApp,
 *   e-mail or jobs). Demo leads open WhatsApp drafts without a recipient.
 */
class DemoData extends Command
{
    protected $signature = 'demo:data {--remove : Delete the demo data instead of adding it}';

    protected $description = 'Add or remove tagged demo data across the admin site (local/test only, never in production)';

    public const TAG = '[DEMO] ';

    /** phone => [name, company, approved] */
    private const PARTNERS = [
        '9000020001' => ['Ravi Shetty', 'Shetty Interiors', true],
        '9000020002' => ['Kavya Builders', 'Kavya Builders LLP', true],
        '9000020003' => ['Imran Architects', 'Imran & Co. Architects', true],
        '9000020004' => ['Deepa Homes', 'Deepa Homes', false],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to touch demo data: APP_ENV is production.');

            return self::FAILURE;
        }

        return $this->option('remove') ? $this->remove() : $this->seed();
    }

    private function demoActions()
    {
        return Action::where('customer', 'like', '[DEMO]%')->where('phone', 'like', '900004%');
    }

    private function demoPartners()
    {
        return User::where('role', 'referral_partner')->whereIn('phone', array_keys(self::PARTNERS))->where('name', 'like', '[DEMO]%');
    }

    private function remove(): int
    {
        $counts = DB::transaction(function () {
            $leads = Lead::where('is_demo', true)->get();
            $leads->each->delete(); // events, measurements and apertures cascade
            $ids = $this->demoActions()->pluck('id');
            DB::table('action_hub_messages')->whereIn('action_id', $ids)->delete();
            ActionEvent::whereIn('action_id', $ids)->delete();
            $actions = Action::whereIn('id', $ids)->delete();
            // A demo partner is removed only when no real lead refers to it.
            $partners = $this->demoPartners()->get()->reject(fn (User $u) => Lead::where('referral_partner_id', $u->id)->exists());
            $partners->each->delete();

            return [$leads->count(), $actions, $partners->count()];
        });
        $this->call('orders:demo', ['--remove' => true]);
        $this->info("Removed {$counts[0]} demo lead(s), {$counts[1]} Action Hub demo appointment(s) and {$counts[2]} demo partner(s). Real data was not touched.");

        return self::SUCCESS;
    }

    private function seed(): int
    {
        $this->call('orders:demo');

        $result = DB::transaction(function () {
            $partners = $this->seedPartners();
            $leads = $this->seedLeads($partners);
            $actions = $this->seedActionHub();

            return [$partners, $leads, $actions];
        });

        [$partners, $leads, $actions] = $result;
        $this->info(sprintf('Demo data ready: %d partners, %d leads, %d Action Hub appointments (plus the PV-DEMO orders above).', count($partners), count($leads), $actions));
        $this->table(['Lead ID', 'Customer', 'Status', 'RAG', 'Note'], array_map(function (Lead $l) {
            $rag = LeadWorkflow::rag($l);

            return [$l->lead_no, $l->client_name, LeadWorkflow::label($l->status_code), strtoupper($rag['rag']), $rag['message'] ?? ''];
        }, $leads));

        return self::SUCCESS;
    }

    /** @return array<string, User> phone => partner */
    private function seedPartners(): array
    {
        $out = [];
        foreach (self::PARTNERS as $phone => [$name, $company, $approved]) {
            $user = User::where('phone', $phone)->first();
            if ($user && ! ($user->role === 'referral_partner' && str_starts_with($user->name, '[DEMO]'))) {
                $this->warn("Skipping demo partner {$phone}: the number belongs to a real account.");

                continue;
            }
            $user ??= new User;
            $user->forceFill(['name' => self::TAG.$name, 'phone' => $phone, 'company' => $company, 'role' => 'referral_partner', 'is_active' => $approved ? 1 : null])->save();
            $out[$phone] = $user;
        }

        return $out;
    }

    /**
     * Each demo lead: [phone, name, type, partner phone|null, assignee, created days ago, steps].
     * A step is [status, extra] where extra may hold 'at' (when the step happened: working days
     * relative to today, negative = past), 'sched' (scheduled date: working days relative to today,
     * plus a time) and 'measured' (working days relative to today).
     */
    private function leadPlan(): array
    {
        $note = fn (string $t) => $t;

        return [
            ['9000030001', 'Ananya Rao', 'B2C', '9000020001', 'Ashu', 0, [], $note("New enquiry for 3BHK sliding windows.\nCall in the evening.")],
            ['9000030002', 'Vikram Builders', 'B2B', '9000020002', 'Aamir', 0, [], 'Apartment block, 40 windows. Wants a site visit.'],
            ['9000030003', 'Suresh Kumar', 'B2C', null, null, 1, [], 'Walk-in enquiry at the factory. Not yet assigned.'],
            ['9000030004', 'Lakshmi Iyer', 'B2C', '9000020001', 'Arif', 2, [['ATT-1-NP', ['at' => -1]]], 'Phone switched off on first call.'],
            ['9000030005', 'Rahul Menon', 'B2C', '9000020003', 'Faisal', 3, [['ATT-1-CALL-LATER', ['at' => -1]]], 'Asked to call back after the weekend.'],
            ['9000030006', 'Priya Constructions', 'B2B', '9000020002', 'FMW', 4, [['ATT-1-NP', ['at' => -3]], ['ATT-2-NP', ['at' => -1]]], 'Two calls unanswered.'],
            ['9000030007', 'Arjun Patel', 'B2C', '9000020003', 'Hadi', 5, [['ATT-1-NP', ['at' => -4]], ['ATT-2-CALL-LATER', ['at' => -1]]], 'Busy with travel; call next week.'],
            ['9000030008', 'Nisha Reddy', 'B2C', '9000020001', 'Akram', 6, [['ATT-1-NP', ['at' => -5]], ['ATT-2-NP', ['at' => -3]], ['ATT-3-NP', ['at' => -1]]], 'Third attempt, no answer.'],
            ['9000030009', 'Mohan Das', 'B2C', '9000020002', 'Ashu', 9, [['ATT-1-CALL-LATER', ['at' => -7]], ['ATT-2-CALL-LATER', ['at' => -4]], ['ATT-3-CALL-LATER', ['at' => -1]]], 'Kept postponing; closed after the third attempt.'],
            ['9000030010', 'Green Valley Villas', 'B2B', '9000020003', 'Aamir', 4, [['MSRMT-SCH', ['at' => -2, 'sched' => [6, '10:30']]]], 'Villa project, 12 doors.'],
            ['9000030011', 'Farah Khan', 'B2C', '9000020001', 'Arif', 3, [['MSRMT-SCH', ['at' => -2, 'sched' => [4, '15:00']]]], 'Bedroom and balcony windows.'],
            ['9000030012', 'Sanjay Gupta', 'B2C', null, 'Faisal', 3, [['MSRMT-SCH', ['at' => -2, 'sched' => [1, '11:00']]]], 'Get ready: measurement tomorrow.'],
            ['9000030013', 'Meera Pillai', 'B2C', '9000020002', 'FMW', 4, [['MSRMT-SCH', ['at' => -3, 'sched' => [0, '17:00']]]], 'Measurement today at 5 PM.'],
            ['9000030014', 'Coastal Residency', 'B2B', '9000020003', 'Hadi', 8, [['MSRMT-SCH', ['at' => -6, 'sched' => [-2, '10:00']]]], 'Measurement date passed; not updated.'],
            ['9000030015', 'Karthik Nair', 'B2C', '9000020001', 'Akram', 6, [['MSRMT-SCH', ['at' => -4, 'sched' => [-1, '10:00']]], ['MSRMT-FULL', ['at' => 0, 'measured' => 0]]], 'All openings measured today.'],
            ['9000030016', 'Divya Shah', 'B2C', '9000020002', 'Ashu', 10, [['MSRMT-SCH', ['at' => -8, 'sched' => [-5, '12:00']]], ['MSRMT-FULL', ['at' => -5, 'measured' => -5]]], 'Measured last week; quotation pending.'],
            ['9000030017', 'Lotus Apartments', 'B2B', '9000020003', 'Aamir', 7, [['MSRMT-SCH', ['at' => -5, 'sched' => [-1, '10:00']]], ['MSRMT-INC', ['at' => -1, 'sched' => [5, '10:00']]]], "Tower A done; Tower B next visit.\nNeed lift access."],
            ['9000030018', 'Rohan Joshi', 'B2C', '9000020001', 'Arif', 7, [['MSRMT-SCH', ['at' => -5, 'sched' => [-1, '14:00']]], ['MSRMT-INC', ['at' => -1, 'sched' => [2, '14:00']]]], 'Kitchen window pending.'],
            ['9000030019', 'Sneha Kulkarni', 'B2C', '9000020002', 'Faisal', 6, [['MSRMT-SCH', ['at' => -4, 'sched' => [1, '10:00']]], ['MSRMT-DLY-RESCLD-ID', ['at' => -1, 'sched' => [7, '10:00']]]], 'Team unavailable; moved by us.'],
            ['9000030020', 'Aditya Rao', 'B2C', '9000020003', 'FMW', 6, [['MSRMT-SCH', ['at' => -4, 'sched' => [0, '16:00']]], ['MSRMT-DLY-RESCLD-CD', ['at' => -1, 'sched' => [2, '16:00']]]], 'Customer asked to move the visit.'],
            ['9000030021', 'Harbour View Society', 'B2B', '9000020001', 'Hadi', 9, [['MSRMT-SCH', ['at' => -6, 'sched' => [-3, '11:00']]], ['MSRMT-DLY-RESCLD-CD', ['at' => -4, 'sched' => [-1, '11:00']]]], 'Rescheduled visit also missed.'],
            ['9000030022', 'Pooja Verma', 'B2C', '9000020002', 'Akram', 8, [['ATT-1-NP', ['at' => -6]], ['MSRMT-ABRT-CL', ['at' => -2]]], 'Customer went with another vendor.'],
            ['9000030023', 'Old Town Traders', 'B2B', '9000020003', 'Ashu', 9, [['MSRMT-SCH', ['at' => -7, 'sched' => [-4, '10:00']]], ['MSRMT-ABRT-PV', ['at' => -3]]], 'Site outside our service area.'],
            ['9000030024', 'Gita Bhat', 'B2C', null, 'Aamir', 12, [['ATT-1-CALL-LATER', ['at' => -10]], ['MSRMT-ABRT-CL', ['at' => -6]]], 'Project postponed indefinitely.'],
        ];
    }

    /** @return Lead[] */
    private function seedLeads(array $partners): array
    {
        $tz = LeadWorkflow::timezone();
        $today = CarbonImmutable::now($tz)->startOfDay();
        $off = array_map('intval', (array) config('leads.weekly_off', [0, 6]));
        $wd = fn (int $n) => $n >= 0 ? WorkingDays::add($today, $n, $off) : $this->subWorkingDays($today, -$n, $off);
        $by = (new User)->forceFill(['name' => 'Demo data']); // history shows "Demo data"; never saved
        $out = [];

        foreach ($this->leadPlan() as [$phone, $name, $type, $partnerPhone, $assignee, $daysAgo, $steps, $notes]) {
            $created = $today->subDays($daysAgo)->setTime(9, 15)->utc();
            $partnerId = $partnerPhone ? (($partners[$partnerPhone] ?? null)?->id) : null;
            $fields = ['client_name' => self::TAG.$name, 'client_phone' => $phone, 'type' => $type, 'notes' => $notes,
                'referral_partner_id' => $partnerId, 'assigned_to' => $assignee, 'to_partner' => (bool) $partnerId, 'to_customer' => true];

            $lead = Lead::where('is_demo', true)->where('client_phone', $phone)->first();
            if ($lead) { // reset in place: same row, same Lead ID
                $lead->events()->delete();
                $lead->measurement()?->delete();
                $lead->forceFill($fields + ['status' => 'new', 'status_code' => LeadWorkflow::RECEIVED, 'lifecycle' => LeadWorkflow::ACTIVE,
                    'scheduled_at' => null, 'measured_at' => null, 'closure_reason' => null, 'closed_at' => null, 'version' => 1,
                    'created_at' => $created, 'updated_at' => $created])->save();
                LeadWorkflow::log($lead, 'created', $by, ['new_status' => LeadWorkflow::RECEIVED, 'new_assignee' => $assignee], $created);
            } else {
                $lead = LeadWorkflow::create($fields, $by, true, $created);
            }

            foreach ($steps as [$code, $extra]) {
                $at = $wd($extra['at'] ?? 0)->setTimezone($tz)->setTime(10, 0);
                $at = $at->gt(CarbonImmutable::now($tz)) ? CarbonImmutable::now($tz) : $at;
                $in = ['status_code' => $code];
                if (isset($extra['sched'])) {
                    [$n, $time] = $extra['sched'];
                    $in['scheduled_at'] = $wd($n)->format('Y-m-d').'T'.$time;
                }
                if (isset($extra['measured'])) {
                    $in['measured_at'] = $wd($extra['measured'])->format('Y-m-d').'T'.min(CarbonImmutable::now($tz)->format('H:i'), '11:30');
                }
                $lead = LeadWorkflow::update($lead, $in, $by, $at->utc());
            }

            if (in_array($lead->status_code, [LeadWorkflow::FULL, 'MSRMT-INC'], true)) {
                Measurement::create([
                    'lead_id' => $lead->id, 'customer_name' => $lead->client_name, 'customer_phone' => $lead->client_phone, 'type' => $lead->type,
                    'measured_by' => $lead->assigned_to ?? 'Demo', 'status' => $lead->status_code === LeadWorkflow::FULL ? 'submitted' : 'draft',
                    'date' => ($lead->measured_at ?? $lead->updated_at)->setTimezone($tz)->format('Y-m-d'),
                ]);
            }
            $out[] = $lead->fresh();
        }

        return $out;
    }

    private function subWorkingDays(CarbonImmutable $day, int $n, array $off): CarbonImmutable
    {
        for ($found = 0, $guard = 0; $found < $n && $guard < 400; $guard++) {
            $day = $day->subDay();
            if (WorkingDays::isWorkingDay($day, $off)) {
                $found++;
            }
        }

        return $day;
    }

    private function seedActionHub(): int
    {
        $tz = ActionHub::timezone();
        $now = CarbonImmutable::now($tz);
        $plan = [ // [phone, customer, project, type, assignee, days from today, time, status override]
            ['9000040001', 'Ananya Rao', 'Whitefield 3BHK', 'Contact Lead', 'Ashu', 1, '11:00', null],
            ['9000040002', 'Green Valley Villas', 'Villa 7', 'Measurements Scheduled', 'Aamir', 6, '10:30', null],
            ['9000040003', 'Lotus Apartments', 'Tower B', 'Measurements Scheduled', 'Arif', 2, '10:00', null],
            ['9000040004', 'Coastal Residency', 'Block C', 'Snag', 'Hadi', -2, '15:00', null],
            ['9000040005', 'Karthik Nair', 'Indiranagar flat', 'Installation', 'FMW', 12, '09:30', null],
            ['9000040006', 'Divya Shah', 'Koramangala villa', 'Service', 'Faisal', -5, '12:00', 'Completed'],
        ];
        $count = 0;
        foreach ($plan as [$phone, $customer, $project, $type, $assignee, $days, $time, $override]) {
            $at = CarbonImmutable::parse($now->addDays($days)->format('Y-m-d').' '.$time, $tz)->utc();
            $status = $override ?? ActionHub::calculated($at);
            $fields = [
                'customer' => strtoupper(self::TAG.$customer), 'project' => strtoupper($project), 'phone' => $phone,
                'description' => strtoupper('Demo appointment for the Action Hub walkthrough.'), 'action_type' => $type,
                'scheduled_at' => $at, 'status' => $status, 'assigned_to' => $assignee, 'version' => 1, 'status_changed_at' => now(),
            ];
            $action = $this->demoActions()->where('phone', $phone)->first();
            if ($action) {
                DB::table('action_hub_messages')->where('action_id', $action->id)->delete();
                $action->events()->delete();
                $action->forceFill($fields)->save();
            } else {
                $action = Action::create($fields);
            }
            ActionEvent::create(['action_id' => $action->id, 'event' => 'created', 'source' => 'manual', 'user_name' => 'Demo data',
                'customer' => $action->customer, 'project' => $action->project, 'new_status' => $status, 'new_scheduled_at' => $at, 'new_assignee' => $assignee]);
            $count++;
        }

        return $count;
    }
}
