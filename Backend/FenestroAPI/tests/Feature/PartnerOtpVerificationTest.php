<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PartnerOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesWhatsappOtp;
use Tests\TestCase;

/**
 * Partner login codes: hashed at rest, single-use, expiring, and invalidated after 5 wrong
 * guesses — atomically, so neither repeated nor concurrent requests get more than 5 guesses.
 * Runs as production (no development code). WhatsApp is faked: nothing is sent.
 */
class PartnerOtpVerificationTest extends TestCase
{
    use RefreshDatabase;
    use FakesWhatsappOtp;

    private const PHONE = '9123400011';

    private const LOCKED_MESSAGE = 'Too many incorrect codes. This code no longer works — please request a new one.';

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        $this->partner = User::create(['name' => 'Partner', 'phone' => self::PHONE, 'role' => 'referral_partner', 'is_active' => 1]);
        $this->fakeWhatsappOtp();
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function requestCode(): string
    {
        $this->postJson('/api/auth/send-otp', ['phone' => self::PHONE])->assertOk()->assertJsonPath('delivery', 'sent');

        return $this->sentOtpCode();
    }

    private function verify(string $code, string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/verify-otp', ['phone' => self::PHONE, 'otp' => $code]);
    }

    private function wrong(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function row(): object
    {
        return DB::table('users')->where('id', $this->partner->id)->first(['otp', 'otp_hash', 'otp_expires_at', 'otp_attempts']);
    }

    public function test_codes_are_stored_only_as_a_keyed_hash(): void
    {
        $code = $this->requestCode();
        $row = $this->row();

        $this->assertNull($row->otp);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->otp_hash);
        $this->assertStringNotContainsString($code, $row->otp_hash);
        $this->assertSame(PartnerOtp::hash($this->partner->id, $code), $row->otp_hash);
        $this->assertNotSame(PartnerOtp::hash($this->partner->id + 1, $code), $row->otp_hash); // bound to the account
        $this->assertNotSame(hash('sha256', $code), $row->otp_hash);                         // keyed, not a plain digest
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame(PartnerOtp::hash($this->partner->id, $code), $row->otp_hash);   // useless without APP_KEY
        $this->assertArrayNotHasKey('otp_hash', $this->partner->fresh()->toArray());          // never serialised
    }

    public function test_the_correct_code_signs_in_once_and_is_then_cleared(): void
    {
        $code = $this->requestCode();

        $this->verify($code)->assertOk()->assertJsonStructure(['token'])->assertJsonPath('user.role', 'referral_partner')->assertJsonMissingPath('user.otp_hash');
        $row = $this->row();
        $this->assertNull($row->otp_hash);
        $this->assertNull($row->otp_expires_at);
        $this->assertSame(0, (int) $row->otp_attempts);

        $this->verify($code)->assertStatus(422)->assertJsonMissingPath('token'); // single use
    }

    public function test_an_incorrect_code_is_refused_and_counted(): void
    {
        $code = $this->requestCode();

        $this->verify($this->wrong($code))->assertStatus(422)->assertJsonPath('message', 'Invalid or expired OTP.')->assertJsonMissingPath('token');
        $this->assertSame(1, (int) $this->row()->otp_attempts);
        $this->verify($code)->assertOk(); // one wrong guess does not invalidate the code
    }

    public function test_a_code_expires_after_ten_minutes(): void
    {
        $code = $this->requestCode();

        $this->travel(10)->minutes();
        $this->travel(1)->seconds();
        $this->verify($code)->assertStatus(422)->assertJsonPath('message', 'Invalid or expired OTP.')->assertJsonMissingPath('token');

        RateLimiter::clear('send-otp:'.self::PHONE);
        $this->verify($this->requestCode())->assertOk(); // a new code works
    }

    public function test_five_wrong_codes_invalidate_the_code_even_for_the_right_one(): void
    {
        $code = $this->requestCode();

        for ($i = 1; $i <= 4; $i++) {
            $this->verify($this->wrong($code))->assertStatus(422)->assertJsonPath('message', 'Invalid or expired OTP.');
            $this->assertSame($i, (int) $this->row()->otp_attempts);
        }
        $this->verify($this->wrong($code))->assertStatus(422)->assertJsonPath('message', self::LOCKED_MESSAGE);
        $this->assertNull($this->row()->otp_hash);

        $this->verify($code)->assertStatus(422)->assertJsonMissingPath('token'); // the right code no longer works

        // A newly requested code works and starts with a fresh counter.
        $fresh = $this->requestCode();
        $this->assertSame(0, (int) $this->row()->otp_attempts);
        $this->verify($fresh)->assertOk();
    }

    public function test_the_limit_cannot_be_bypassed_by_changing_ip_or_after_the_rate_limit_resets(): void
    {
        $code = $this->requestCode();

        foreach (range(1, 5) as $i) {
            RateLimiter::clear('verify-otp:'.self::PHONE); // as if spread out over time
            $this->verify($this->wrong($code), "10.0.0.$i")->assertStatus(422);
        }
        RateLimiter::clear('verify-otp:'.self::PHONE);
        $this->verify($code, '10.0.0.99')->assertStatus(422)->assertJsonMissingPath('token');
        $this->assertNull($this->row()->otp_hash);
    }

    public function test_concurrent_requests_cannot_reuse_a_code_or_exceed_five_guesses(): void
    {
        // Two requests that both loaded the account before either finished: only one can use the code.
        $code = $this->requestCode();
        $requestA = User::find($this->partner->id);
        $requestB = User::find($this->partner->id);
        $this->assertSame(PartnerOtp::OK, PartnerOtp::verify($requestA, $code));
        $this->assertSame(PartnerOtp::INVALID, PartnerOtp::verify($requestB, $code));

        // Other requests used up the 5 guesses but had not yet cleared the code: the right code is
        // still refused, because the limit is checked inside the same UPDATE that consumes the code.
        PartnerOtp::issue($this->partner, '246810');
        DB::table('users')->where('id', $this->partner->id)->update(['otp_attempts' => PartnerOtp::MAX_ATTEMPTS]);
        $this->assertSame(PartnerOtp::LOCKED, PartnerOtp::verify($this->partner, '246810'));
        $this->assertSame(PartnerOtp::INVALID, PartnerOtp::verify($this->partner, '246810'));

        // Many wrong guesses never push the counter past the limit, and exactly one of them reports the lock.
        PartnerOtp::issue($this->partner, '135790');
        $results = array_map(fn () => PartnerOtp::verify($this->partner, '000000'), range(1, 8));
        $this->assertSame([PartnerOtp::INVALID, PartnerOtp::INVALID, PartnerOtp::INVALID, PartnerOtp::INVALID, PartnerOtp::LOCKED,
            PartnerOtp::INVALID, PartnerOtp::INVALID, PartnerOtp::INVALID], $results);
        $this->assertSame(PartnerOtp::MAX_ATTEMPTS, (int) $this->row()->otp_attempts);
        $this->assertSame(PartnerOtp::INVALID, PartnerOtp::verify($this->partner, '135790'));
    }

    public function test_approval_and_partner_only_rules_still_apply_with_a_valid_code(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'phone' => '9123400012', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);
        PartnerOtp::issue($admin, '112233');
        $this->postJson('/api/auth/verify-otp', ['phone' => '9123400012', 'otp' => '112233'])->assertNotFound()->assertJsonMissingPath('token');

        $code = $this->requestCode();
        $this->partner->update(['is_active' => 0]);
        $this->verify($code)->assertForbidden()->assertJsonMissingPath('token');
        $this->partner->update(['is_active' => null]);
        $this->verify($code)->assertForbidden()->assertJsonMissingPath('token');
        $this->assertSame(0, (int) $this->row()->otp_attempts); // refused before the code is checked
    }

    public function test_the_migration_clears_legacy_plaintext_codes_without_touching_accounts(): void
    {
        DB::table('users')->where('id', $this->partner->id)->update(['otp' => '987654', 'otp_expires_at' => now()->addMinutes(5)]);
        $before = DB::table('users')->where('id', $this->partner->id)->first(['name', 'phone', 'role', 'is_active']);

        (require database_path('migrations/2026_11_03_000001_hash_partner_login_codes.php'))->up(); // columns exist: only clears

        $this->assertNull($this->row()->otp);
        $this->assertEquals($before, DB::table('users')->where('id', $this->partner->id)->first(['name', 'phone', 'role', 'is_active']));
    }
}
