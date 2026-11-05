<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Login codes are stored hashed, so tests read the code from the (faked) WhatsApp request —
 * the only place it exists in clear. Nothing is ever sent.
 */
trait FakesWhatsappOtp
{
    protected function fakeWhatsappOtp(array $config = []): void
    {
        config(array_merge([
            'services.whatsapp.enabled'           => true,
            'services.whatsapp.token'             => 'test-token',
            'services.whatsapp.phone_number_id'   => '111',
            'services.whatsapp.api_base'          => 'https://graph.example.test',
            'services.whatsapp.api_version'       => 'v21.0',
            'services.whatsapp.otp_template'      => 'partner_login_code',
            'services.whatsapp.otp_template_lang' => 'en',
        ], $config));
        Http::preventStrayRequests();
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);
    }

    /** The code in the most recent faked WhatsApp login-code message. */
    protected function sentOtpCode(): string
    {
        $last = Http::recorded()->last();
        $this->assertNotNull($last, 'No WhatsApp login code was sent.');

        return $last[0]['template']['components'][0]['parameters'][0]['text'];
    }
}
