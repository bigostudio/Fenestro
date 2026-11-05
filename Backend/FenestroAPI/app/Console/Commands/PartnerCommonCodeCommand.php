<?php

namespace App\Console\Commands;

use App\Support\PartnerCommonCode;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Server-side management of the ONE common partner access code (same rules as the admin page).
 *   generate — new random code, printed once to this terminal (never logged or stored in clear)
 *   set      — choose the code; it is asked for hidden, never passed as an argument
 *   status   — whether a code is active (never the code)
 *   revoke   — withdraw the code; sessions opened with it end
 * Generating or setting a code replaces the previous one (its sessions end).
 */
class PartnerCommonCodeCommand extends Command
{
    protected $signature = 'partner:common-code
                            {action : generate | set | status | revoke}
                            {--by= : Administrator name recorded as setter / revoker}';

    protected $description = 'Generate, set, show the status of, or revoke the common partner access code';

    public function handle(): int
    {
        $by = trim((string) $this->option('by')) ?: 'console';

        return match ($this->argument('action')) {
            'generate' => $this->apply(null, $by),
            'set'      => $this->apply((string) $this->secret('New 6-digit code (hidden)'), $by),
            'status'   => $this->status(),
            'revoke'   => $this->revoke($by),
            default    => $this->fail('Action must be generate, set, status or revoke.'),
        };
    }

    private function apply(?string $code, string $by): int
    {
        try {
            $r = PartnerCommonCode::set($code, $by);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Common partner access code '.($code === null ? 'generated' : 'set').'. The previous code (if any) no longer works.');
        if ($code === null) {
            $this->line('Code (shown once — not stored in clear): <options=bold>'.$r['code'].'</>');
        }
        $this->warn('This one code signs in EVERY approved partner (with their own number). Share it privately and change it regularly.');

        return self::SUCCESS;
    }

    private function status(): int
    {
        $s = PartnerCommonCode::status();
        if (! $s['enabled']) {
            $this->warn('The common partner access code is switched off on this server (PARTNER_TEMP_ACCESS_ENABLED).');
        }
        $this->line($s['active']
            ? 'Active — set by '.($s['current']['created_by'] ?? '?').' at '.$s['current']['created_at'].' (UTC).'
            : 'No active code.'.($s['last_revoked'] ? ' Last revoked '.$s['last_revoked']['revoked_at'].' ('.$s['last_revoked']['reason'].').' : ''));
        $this->line("Wrong codes in the last hour: {$s['failed_attempts_last_hour']} of {$s['max_failures_per_hour']} before automatic revocation.");

        return self::SUCCESS;
    }

    private function revoke(string $by): int
    {
        $n = PartnerCommonCode::revokeActive('revoked_by_admin', $by);
        $this->info($n ? 'Common partner access code revoked. Sessions opened with it have ended.' : 'There was no active code.');

        return self::SUCCESS;
    }
}
