<?php

namespace App\Console\Commands;

use App\Support\ActionHub;
use Illuminate\Console\Command;

/**
 * Action Hub automatic statuses (On Track → Get Ready → Delayed), run by the
 * scheduler every 5 minutes (routes/console.php) so statuses and their audit
 * rows are recorded even when nobody has the page open. Idempotent: a change
 * already applied is never applied or logged again. The page also refreshes
 * statuses whenever it loads data.
 */
class ActionHubRefreshStatuses extends Command
{
    protected $signature = 'action-hub:refresh-statuses';

    protected $description = 'Apply due Action Hub status changes (Get Ready two working days before, Delayed once overdue)';

    public function handle(): int
    {
        $changed = ActionHub::refresh();
        $this->info("{$changed} action(s) changed status.");

        return self::SUCCESS;
    }
}
