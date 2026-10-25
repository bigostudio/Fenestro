<?php

namespace App\Console\Commands;

use App\Models\OrderManagement\InventoryProfile;
use App\Models\OrderManagement\InventoryUsage;
use App\Models\OrderManagement\Order;
use App\Models\OrderManagement\StatementSend;
use App\Support\OrderManagement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development/test-only demo data for reviewing the CNS Dashboard.
 *
 *   php artisan orders:demo           add (or reset) the demo orders + shortage profiles
 *   php artisan orders:demo --remove  delete the demo data only
 *
 * - Refuses to run when APP_ENV=production.
 * - Only touches records whose ID/code starts with PV-DEMO- (config cns.demo_prefix);
 *   real orders and inventory are never read, changed or deleted.
 * - Idempotent: re-running updates the same records (and resets them to their
 *   starting state) instead of adding duplicates.
 * - Names/projects carry a "[DEMO]" tag; phone numbers are fictional and are
 *   never used for WhatsApp drafts (demo drafts open without a recipient).
 * - Covers new orders, advance/partial/full payments, completed projects,
 *   aborted projects with a full, partial and zero refund (refunds recorded the
 *   same way Project Abort records them), and two disposable records for a live
 *   demo: 014 (no history — Delete Project) and 015 (active, paid — Project Abort).
 *   Re-running restores both. A figures table is printed at the end.
 */
class OrdersDemo extends Command
{
    protected $signature = 'orders:demo {--remove : Delete the demo data instead of adding it}';

    protected $description = 'Add or remove fictional PV-DEMO-* orders for local/test review of the CNS Dashboard (never in production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to touch demo data: APP_ENV is production.');

            return self::FAILURE;
        }

        $prefix = (string) config('cns.demo_prefix', 'PV-DEMO-');

        return $this->option('remove') ? $this->remove($prefix) : $this->seed($prefix);
    }

    private function remove(string $prefix): int
    {
        $orders = $profiles = $usages = 0;
        DB::transaction(function () use ($prefix, &$orders, &$profiles, &$usages) {
            $usages = InventoryUsage::where('usage_key', 'like', $prefix.'%')->delete();
            $orders = Order::where('code', 'like', $prefix.'%')->get()->each->delete()->count();       // receipts cascade
            $profiles = InventoryProfile::where('code', 'like', $prefix.'%')->get()->each->delete()->count(); // usages cascade
        });

        $this->info("Removed {$orders} demo order(s), {$profiles} demo profile(s) and {$usages} demo usage row(s). Real data was not touched.");

        return self::SUCCESS;
    }

    private function seed(string $prefix): int
    {
        $today = now(config('cns.comm_reset.timezone', 'Asia/Kolkata'))->startOfDay();
        $defaults = [];
        foreach (OrderManagement::statuses() as $key => $s) {
            $defaults[$key] = $s['default'];
        }
        $done = [
            'profile' => 'AVAILABLE', 'sizeqc' => 'YES', 'glass' => 'AVAILABLE',
            'prodHardware' => 'AVAILABLE', 'installHardware' => 'PACKED', 'docs' => 'SHARED', 'exceptions' => 'INFORMED',
        ];
        // PROJECT COMPLETED completes the project and archives it.
        $completed = ['comm' => 'PROJECT COMPLETED', 'production' => 'PRODUCTION COMPLETED'] + $done;

        // Hours since each demo PROJ STATUS COMM was selected: under 96 → green,
        // 96 or more → red (4-day colour reset; the status itself is kept).
        $commSelectedHoursAgo = ['002' => 20, '003' => 130, '004' => 50, '005' => 95, '006' => 100, '007' => 72, '008' => 48, '009' => 288, '010' => 192];

        // Payment patterns → receipts (each [days after confirmed, share of invoice OR fixed amount, note]):
        //   none: nothing received · advance: 30% · partial: 30% + 20% · full: 30% + 70%
        $payments = [
            'none'    => [],
            'advance' => [[0, 0.3, 'DEMO ADVANCE']],
            'partial' => [[0, 0.3, 'DEMO ADVANCE'], [7, 0.2, 'DEMO PART PAYMENT']],
            'full'    => [[0, 0.3, 'DEMO ADVANCE'], [20, 0.7, 'DEMO BALANCE']],
        ];

        // [code, customer, project, phone, confirmed days ago, sft, rate, payments, statuses, completed days ago|null, installation in N days|null]
        $rows = [
            ['001', 'KAVYA RAO', 'LAKEVIEW VILLA, MYSURU', '9000000101', 1, 1180, 1450, 'none', [], null, null],                                         // new: defaults, nothing received yet
            ['002', 'ARJUN SHETTY', 'SKYLINE APARTMENT 4B', '9000000102', 6, 640, 1325, 'advance',
                ['comm' => 'ADV RCVD', 'profile' => 'AVAILABLE', 'sizeqc' => 'YES', 'docs' => 'SHARED'], null, 25],                                    // advance received
            ['003', 'MEERA NAIR', 'GREENFIELD RESIDENCY', '9000000103', 10, 920, 1550, 'partial',
                ['comm' => 'MATRL ARIVD', 'profile' => 'AVAILABLE', 'sizeqc' => 'YES', 'glass' => 'AVAILABLE'], null, 18],                            // profiles + glass available
            ['004', 'RAHUL VERMA', 'SUNRISE OFFICE COMPLEX', '9000000104', 14, 2150, 1400, 'partial',
                ['comm' => 'PROD STRTD', 'production' => 'PRODUCTION STARTED', 'profile' => 'AVAILABLE', 'sizeqc' => 'YES', 'glass' => 'AVAILABLE', 'prodHardware' => 'AVAILABLE', 'installHardware' => 'PACKED'], null, 12], // hardware packed
            ['005', 'FATIMA SHAIKH', 'PALM GROVE BUNGALOW', '9000000105', 17, 760, 1500, 'partial',
                ['comm' => 'INST STRTD', 'production' => 'PRODUCTION COMPLETED', 'sizeqc' => 'YES', 'docs' => 'SHARED', 'exceptions' => 'INFORMED'], null, 2], // production docs shared
            ['006', 'SURESH PATIL', 'RIVERSIDE TOWERS B-12', '9000000106', 5, 540, 1450, 'advance',
                ['comm' => 'QSHARE', 'sizeqc' => 'YES'], null, null],                                                                                    // shortage profiles; no installation date yet
            ['007', 'VIKRAM IYER', 'HILLTOP FARMHOUSE', '9000000107', 41, 430, 1450, 'full', $completed, 3, -6],                                        // completed (archived), fully paid
            ['008', 'ANITA DSOUZA', 'COASTAL VILLA, MANGALURU', '9000000108', 35, 690, 1500, [[0, 250000, 'DEMO ADVANCE'], [14, 100000, 'DEMO PART PAYMENT']], $completed, 2, -4], // completed (archived), money due
            ['009', 'KARTHIK REDDY', 'OLD TOWN DUPLEX', '9000000109', 60, 300, 1450, [[0, 250000, 'DEMO ADVANCE']], $completed, 12, -15],             // completed (archived), money due
            ['010', 'NEHA KULKARNI', 'MAPLE HEIGHTS 7C', '9000000110', 8, 410, 1400, 'advance', ['comm' => 'YES', 'sizeqc' => 'YES'], null, 30],      // legacy YES; 8 days → 4-day reset due today
            // Aborted projects (Archive): full, partial and zero refund.
            ['011', 'ROHAN MEHTA', 'LAKESIDE VILLA (ABORTED, FULL REFUND)', '9000000111', 30, 500, 1400, [[0, 150000, 'DEMO ADVANCE'], [10, 100000, 'DEMO PART PAYMENT']], ['comm' => 'PROJECT ABORT', 'sizeqc' => 'YES'], 3, null],
            ['012', 'PRIYA SINGH', 'METRO HEIGHTS 9A (ABORTED, PARTIAL REFUND)', '9000000112', 25, 450, 1500, [[0, 200000, 'DEMO ADVANCE']], ['comm' => 'PROJECT ABORT', 'sizeqc' => 'YES'], 4, null],
            ['013', 'DEEPAK JOSHI', 'GARDEN COURT (ABORTED, NO REFUND)', '9000000113', 20, 380, 1450, [[0, 100000, 'DEMO ADVANCE']], ['comm' => 'PROJECT ABORT'], 5, null],
            // Disposable records for a live demo (re-run this command to restore them).
            ['014', 'DUMMY DELETE TEST', 'DISPOSABLE NEW ORDER (SAFE TO DELETE)', '9000000114', 0, 200, 1400, 'none', [], null, null],
            ['015', 'DUMMY ABORT TEST', 'DISPOSABLE ACTIVE PROJECT (SAFE TO ABORT)', '9000000115', 12, 300, 1500, [[0, 100000, 'DEMO ADVANCE'], [6, 50000, 'DEMO PART PAYMENT']],
                ['comm' => 'INST STRTD', 'production' => 'PRODUCTION STARTED', 'sizeqc' => 'YES'], null, 5],
        ];
        // Refunds on the aborted projects ([days after confirmed, amount, note]), stored as Project Abort stores them.
        $refunds = [
            '011' => [[25, 250000, 'DEMO FULL REFUND NEFT']],
            '012' => [[22, 80000, 'DEMO PARTIAL REFUND NEFT']],
        ];

        $added = 0;
        DB::transaction(function () use ($rows, $refunds, $prefix, $today, $defaults, $payments, $commSelectedHoursAgo, &$added) {
            foreach ($rows as [$n, $customer, $project, $phone, $daysAgo, $sft, $rate, $paid, $statuses, $completedDaysAgo, $installIn]) {
                $code = $prefix.$n;
                $confirmed = $today->copy()->subDays($daysAgo)->toDateString();
                $invoice = round(round($sft * $rate, 2) * 1.18, 2);
                $receipts = is_array($paid) ? $paid : $payments[$paid];

                $order = Order::updateOrCreate(['code' => $code], [
                    'customer'         => '[DEMO] '.$customer,
                    'project'          => '[DEMO] '.$project,
                    'phone'            => $phone,
                    'confirmed'        => $confirmed,
                    'installation_date' => $installIn === null ? null : $today->copy()->addDays($installIn)->toDateString(),
                    'sft'              => $sft,
                    'rate'             => $rate,
                    'tax'              => 18,
                    'written_off'      => 'No',
                    'remarks'          => 'DEMO RECORD FOR CNS REVIEW - NOT A REAL CUSTOMER',
                    'statuses'         => OrderManagement::normaliseStatuses(array_merge($defaults, $statuses)),
                    'completed_at'     => $completedDaysAgo !== null ? now()->subDays($completedDaysAgo) : null,
                    'last_comm_change' => isset($commSelectedHoursAgo[$n]) ? now()->subHours($commSelectedHoursAgo[$n]) : null,
                    'last_comm_reset'  => null,
                ]);
                $added += $order->wasRecentlyCreated ? 1 : 0;

                $order->receipts()->delete();
                StatementSend::where('order_id', $order->id)->delete(); // demo statement log only (PV-DEMO-*)
                $paidSoFar = 0.0;
                foreach (array_values($receipts) as $pos => [$afterDays, $share, $note]) {
                    // Shares are fractions of the invoice; the last share of a 'full' pattern settles it exactly.
                    $amount = $share <= 1 ? round($invoice * $share, $share >= 0.7 ? 2 : -3) : (float) $share;
                    if ($note === 'DEMO BALANCE') {
                        $amount = round($invoice - $paidSoFar, 2);
                    }
                    $paidSoFar += $amount;
                    $date = \Illuminate\Support\Carbon::parse($confirmed)->addDays($afterDays);
                    $order->receipts()->create([
                        'date'     => ($date->gt($today) ? $today : $date)->toDateString(),
                        'amount'   => $amount,
                        'notes'    => $note,
                        'position' => $pos,
                    ]);
                }
                foreach ($refunds[$n] ?? [] as $i => [$afterDays, $amount, $note]) {
                    $date = \Illuminate\Support\Carbon::parse($confirmed)->addDays($afterDays);
                    $order->receipts()->create([
                        'date'     => ($date->gt($today) ? $today : $date)->toDateString(),
                        'amount'   => $amount,
                        'kind'     => 'refund',
                        'notes'    => $note,
                        'ref'      => 'demo-'.$n.'-refund-'.($i + 1),
                        'position' => count($receipts) + $i,
                    ]);
                }
            }

            // Shortage example for PV-DEMO-006: current stock = base stock − usage ≤ 0.
            foreach ([['A', 'SLIDING WINDOW SASH (SHORTAGE EXAMPLE)', 2, 6], ['B', 'CASEMENT OUTER FRAME (SHORTAGE EXAMPLE)', 0, 3]] as [$s, $item, $base, $use]) {
                $profile = InventoryProfile::updateOrCreate(['code' => $prefix.'PROFILE-'.$s], [
                    'item' => '[DEMO] '.$item, 'base_stock' => $base, 'stock' => $base, 'unit_cost' => 350,
                ]);
                $profile->usages()->delete();
                $profile->usages()->create(['usage_key' => $prefix.'006', 'qty' => $use]);
            }
        });

        $this->info("Demo data ready: {$added} order(s) added, ".(count($rows) - $added).' reset. IDs '.$prefix.'001 … '.$prefix.sprintf('%03d', count($rows)).', plus 2 demo shortage profiles.');
        // Figures as the app calculates them (the same numbers Receivables, Archive and the PDFs show).
        $this->table(['ID', 'Project', 'Status', 'Invoice', 'Payments', 'Refunded', 'Net received', 'Balance', 'Payment status', 'Refund status'],
            Order::with('receipts')->where('code', 'like', $prefix.'%')->orderBy('code')->get()->map(fn (Order $o) => [
                $o->code, mb_strimwidth(str_replace('[DEMO] ', '', (string) $o->project), 0, 34, '…'), $o->projectStatusLabel(),
                ...array_map(fn ($v) => number_format($v, 2), [$o->invoiceAmount(), $o->paymentsAmount(), $o->refundedAmount(), $o->receivedAmount(), $o->outstanding()]),
                $o->paymentStatus(), $o->refundStatus(),
            ])->all());
        $this->line('Remove with: php artisan orders:demo --remove');

        return self::SUCCESS;
    }
}
