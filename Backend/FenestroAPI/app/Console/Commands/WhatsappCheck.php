<?php

namespace App\Console\Commands;

use App\Services\WhatsAppService;
use Illuminate\Console\Command;

/**
 * Verify the WhatsApp Business Cloud API setup used by "Send Statement".
 *
 *   php artisan whatsapp:check                 read-only: are the token and phone number ID valid?
 *   php artisan whatsapp:check --to=98XXXXXXXX  also send Meta's standard "hello_world" test
 *                                               template to YOUR OWN number (never a customer's)
 */
class WhatsappCheck extends Command
{
    protected $signature = 'whatsapp:check {--to= : Your own WhatsApp number, to receive the hello_world test message}';

    protected $description = 'Check the WhatsApp Business Cloud API credentials used to send payment statements';

    public function handle(WhatsAppService $whatsApp): int
    {
        $missing = $whatsApp->missingSettings();
        $this->line('WHATSAPP_TOKEN ............ '.(in_array('WHATSAPP_TOKEN', $missing, true) ? '<error>missing</error>' : 'set'));
        $this->line('WHATSAPP_PHONE_NUMBER_ID .. '.(in_array('WHATSAPP_PHONE_NUMBER_ID', $missing, true) ? '<error>missing</error>' : 'set'));
        $this->line('WHATSAPP_SENDING_ENABLED .. '.(config('services.whatsapp.enabled') ? 'on' : '<comment>off (no automatic messages are sent)</comment>'));
        $this->line('CNS_STATEMENT_DRAFTS ...... '.(config('cns.whatsapp.statement_drafts_enabled') ? 'on' : '<comment>off (statements will not be sent)</comment>'));
        $template = config('cns.whatsapp.statement_api.template');
        $this->line('WHATSAPP_STATEMENT_TEMPLATE '.($template ? $template.' ('.config('cns.whatsapp.statement_api.template_language', 'en').')' : '<comment>not set (only works within 24 h of the customer\'s last message)</comment>'));
        $this->line('Test recipient override ... '.(config('cns.whatsapp.test_recipient') ? 'ON — every message goes to WHATSAPP_DRAFT_TEST_RECIPIENT' : 'off'));
        if ($missing) {
            $this->error('Not configured: set '.implode(' and ', $missing).' in the server .env, then run php artisan config:clear.');

            return self::FAILURE;
        }

        $check = $whatsApp->checkConnection($template ?: null, (string) config('cns.whatsapp.statement_api.template_language', 'en'));
        if (! $check['ok']) {
            $this->error($check['error']);

            return self::FAILURE;
        }
        $p = $check['phone'];
        $this->info('Connected to WhatsApp number '.($p['display_phone_number'] ?? '?').' — "'.($p['verified_name'] ?? '?').'" (quality: '.($p['quality_rating'] ?? '?').', name status: '.($p['name_status'] ?? '?').').');
        if ($check['template']) {
            $t = $check['template'];
            $this->line('Statement template "'.$t['name'].'": '.($t['status'] ?? '?').(($t['status'] ?? '') === 'APPROVED' ? '' : ' — statements will be rejected until it is APPROVED.'));
        } elseif ($template) {
            $this->line('Set WHATSAPP_BUSINESS_ACCOUNT_ID to also check the template status.');
        }

        if ($to = $this->option('to')) {
            $ok = $whatsApp->sendTemplate((string) $to, 'hello_world', [], 'en_US', true); // test message, allowed while switched off
            $ok ? $this->info('hello_world test sent to '.$to.'. Check that phone.') : $this->error('The test message was rejected (see storage/logs/laravel.log).');

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
