<?php

namespace App\Console\Commands;

use App\Models\OrderManagement\Order;
use App\Support\OrderManagement;
use Illuminate\Console\Command;

/**
 * PROJ STATUS COMM four-day colour reset (requested 2026-10-21).
 *
 * A selected status is green for exactly 4 days from the moment it was
 * selected (om_orders.last_comm_change, set by the server), then red. This
 * command runs on the server (hourly, routes/console.php) and records each
 * expiry in last_comm_reset. It never changes the saved status value, never
 * archives anything and never creates WhatsApp drafts.
 *
 * The dashboard colour itself is always computed from the selection time
 * (Order::isCommFresh), so it is correct even between scheduler runs.
 */
class CnsResetCommunication extends Command
{
    protected $signature = 'cns:reset-communication
                            {--dry-run : Report what would change without saving}';

    protected $description = 'Record PROJ STATUS COMM colour resets (green → red 4 days after a status was selected; the status is kept)';

    public function handle(): int
    {
        $expired = $already = $fresh = 0;

        Order::query()->orderBy('id')->chunkById(200, function ($orders) use (&$expired, &$already, &$fresh) {
            foreach ($orders as $order) {
                $until = $order->commFreshUntil();
                if ($until === null) {
                    continue; // YET TO SHARE / NO: always red, nothing to reset
                }
                if (now()->lt($until)) {
                    $fresh++;
                    continue; // still inside its 4 days
                }
                if ($order->last_comm_reset && $order->last_comm_reset->gte($until)) {
                    $already++;
                    continue; // this selection's reset is already recorded
                }

                $expired++;
                if (! $this->option('dry-run')) {
                    // Only the reset time is recorded; statuses are untouched.
                    $order->forceFill(['last_comm_reset' => $until])->saveQuietly();
                }
            }
        });

        $this->info(sprintf(
            '%d status colour(s) reset to red (4 days passed), %d already recorded, %d still green.%s',
            $expired, $already, $fresh, $this->option('dry-run') ? ' (dry run, nothing saved)' : ''
        ));

        return self::SUCCESS;
    }
}
