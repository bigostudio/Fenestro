<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Log\Events\MessageLogged;
use Tests\TestCase;

/**
 * Partner login codes are delivered through the WhatsApp Cloud API (faked here: nothing is
 * ever sent), the API never claims a code was sent when it was not, and the code never
 * appears in the log.
 */
class PartnerOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\FakesWhatsappOtp;

    private const PHONE = '9123400005';

    /** @var list<string> every log line written during the test, with its context */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // a real request would fail the test
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logged[] = $e->message.' '.json_encode($e->context));
        User::create(['name' => 'Partner', 'phone' => self::PHONE, 'role' => 'referral_partner', 'is_active' => 1]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function configureWhatsapp(array $extra = []): void
    {
        config(array_merge([
            'services.whatsapp.enabled'         => true,
            'services.whatsapp.token'           => 'test-token',
            'services.whatsapp.phone_number_id' => '111',
            'services.whatsapp.api_base'        => 'https://graph.example.test',
            'services.whatsapp.api_version'     => 'v21.0',
            'services.whatsapp.otp_template'    => 'partner_login_code',
            'services.whatsapp.otp_template_lang' => 'en',
        ], $extra));
    }

    private function storedHash(): ?string
    {
        return User::where('phone', self::PHONE)->value('otp_hash');
    }

    private function assertCodeNotLogged(?string $code): void
    {
        $this->assertNotNull($code);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($code, $line, 'The login code must never be logged.');
            $this->assertStringNotContainsString(self::PHONE, $line, 'Only the masked number may be logged.');
        }
    }

    public function test_the_code_is_sent_with_the_authentication_template_and_never_logged(): void
    {
        $this->app['env'] = 'production';
        $this->configureWhatsapp();
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.OK']]])]);

        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('delivery', 'sent')
            ->assertJsonPath('message', 'OTP sent to your WhatsApp number. It expires in 10 minutes.')
            ->assertJsonMissingPath('otp');

        $otp = $this->sentOtpCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        // Stored only as its keyed hash; the plaintext column stays empty.
        $this->assertSame(\App\Support\PartnerOtp::hash(User::where('phone', self::PHONE)->value('id'), $otp), $this->storedHash());
        $this->assertNull(User::where('phone', self::PHONE)->value('otp'));
        $this->assertEqualsWithDelta(10, now()->diffInMinutes(User::where('phone', self::PHONE)->value('otp_expires_at')), 0.1);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($otp) {
            $t = $r['template'];

            return $r->url() === 'https://graph.example.test/v21.0/111/messages'
                && $r->hasHeader('Authorization', 'Bearer test-token')
                && $r['to'] === '91'.self::PHONE
                && $r['type'] === 'template'
                && $t['name'] === 'partner_login_code' && $t['language']['code'] === 'en'
                && $t['components'][0] === ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $otp]]]
                && $t['components'][1] === ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $otp]]];
        });
        $this->assertCodeNotLogged($otp);
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'WhatsApp login code accepted') && str_contains($l, '9191******05')));

        // The delivered code signs the partner in once.
        $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'otp' => $otp])->assertOk()->assertJsonPath('user.role', 'referral_partner');
    }

    public function test_production_without_whatsapp_setup_fails_honestly_and_stores_no_code(): void
    {
        $this->app['env'] = 'production';
        Http::fake();

        // missing template / sending switched off / missing credentials
        foreach ([['services.whatsapp.otp_template' => null], ['services.whatsapp.enabled' => false], ['services.whatsapp.token' => '']] as $setup) {
            $this->configureWhatsapp($setup);
            $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])
                ->assertStatus(503)
                ->assertJsonMissingPath('delivery')
                ->assertJsonPath('message', fn ($m) => str_contains($m, 'could not send a login code'));
            $this->assertNull($this->storedHash());
        }
        Http::assertNothingSent();
    }

    public function test_a_rejected_or_unreachable_send_clears_the_code(): void
    {
        $this->app['env'] = 'production';
        $this->configureWhatsapp();
        \App\Support\PartnerOtp::issue(User::where('phone', self::PHONE)->first(), '111111'); // an older, still-valid code

        Http::fake(['graph.example.test/*' => Http::response(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 404)]);
        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])->assertStatus(503)->assertJsonMissingPath('delivery');
        $this->assertNull($this->storedHash());

        Http::fake(['graph.example.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])->assertStatus(503);
        $this->assertNull($this->storedHash());

        $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'otp' => '111111'])->assertStatus(422);
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'WhatsApp login code rejected')));
        $this->assertNotEmpty(array_filter($this->logged, fn ($l) => str_contains($l, 'WhatsApp login code send failed')));
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(self::PHONE, $line);
        }
    }

    public function test_development_servers_say_no_code_was_sent_and_keep_the_development_code(): void
    {
        Http::fake();

        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])
            ->assertOk()
            ->assertJsonPath('delivery', 'not_sent')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no code was sent'));
        Http::assertNothingSent();
        $this->assertNotNull($this->storedHash()); // a real (hashed) code exists; it is not shown or logged anywhere
        foreach ($this->logged as $line) {
            $this->assertDoesNotMatchRegularExpression('/(?<!\d)\d{6}(?!\d)/', $line);
        }
        $this->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'otp' => '123456'])->assertOk();
    }

    public function test_no_message_is_sent_to_admin_pending_rejected_or_unknown_numbers(): void
    {
        $this->app['env'] = 'production';
        $this->configureWhatsapp();
        Http::fake();
        User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'phone' => '9123400006', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        User::create(['name' => 'Pending', 'phone' => '9123400007', 'role' => 'referral_partner', 'is_active' => null]);
        User::create(['name' => 'Rejected', 'phone' => '9123400008', 'role' => 'referral_partner', 'is_active' => 0]);

        $this->postJson('/api/auth/send-otp', ['phone' => '9123400006'])->assertNotFound();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123400009'])->assertNotFound();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123400007'])->assertForbidden();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123400008'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, User::whereNotNull('otp_hash')->count());
    }

    public function test_codes_per_number_are_capped_per_hour(): void
    {
        $this->app['env'] = 'production';
        $this->configureWhatsapp();
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.OK']]])]);

        for ($i = 0; $i < 10; $i++) {
            if ($i === 5) {
                $this->travel(61)->seconds(); // past the per-minute limit, still inside the hour
            }
            $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])->assertOk();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])->assertStatus(429);
        Http::assertSentCount(10);
    }
}
