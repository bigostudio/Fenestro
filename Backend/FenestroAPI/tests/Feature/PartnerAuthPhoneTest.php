<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partner OTP login vs registration for a number that already belongs to a
 * non-partner account (2026-11-01: an admin's mobile number was "not registered" at
 * partner login but "already taken" at registration).
 */
class PartnerAuthPhoneTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_A_PARTNER = 'This number is not registered as a referral partner. Please register first. Prestige Ventures staff sign in at Admin Login (/admin/login).';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production'; // no development OTP shortcut: real OTPs only
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    public function test_an_admin_number_gets_one_consistent_answer_and_no_otp_or_duplicate_account(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'phone' => '9123400001', 'password' => 'Correct-Horse-9!', 'role' => 'admin', 'is_active' => 1]);

        // Login: same text as an unknown number (does not reveal it is a staff number), and points staff to Admin Login.
        $this->postJson('/api/auth/send-otp', ['phone' => '9123400001'])->assertNotFound()->assertJsonPath('message', self::NOT_A_PARTNER);
        $this->postJson('/api/auth/send-otp', ['phone' => '9876501234'])->assertNotFound()->assertJsonPath('message', self::NOT_A_PARTNER);
        $this->postJson('/api/auth/verify-otp', ['phone' => '9123400001', 'otp' => '123456'])->assertNotFound();
        $this->assertNull(User::where('phone', '9123400001')->value('otp')); // no OTP generated for the admin

        // Registration: still blocked (one account per number), with an explanation that matches the login answer.
        $res = $this->postJson('/api/auth/register', ['name' => 'Someone', 'phone' => '9123400001'])->assertStatus(422);
        $this->assertSame('This number is already registered. Partners: use Login to sign in with an OTP. Prestige Ventures staff: sign in at Admin Login (/admin/login).', $res->json('errors.phone.0'));
        $this->assertSame(1, User::where('phone', '9123400001')->count());
        $this->assertSame('admin', User::where('phone', '9123400001')->value('role'));
    }

    public function test_an_approved_partner_requests_and_verifies_a_real_otp_and_duplicates_stay_blocked(): void
    {
        $this->postJson('/api/auth/register', ['name' => 'Ravi Partner', 'phone' => '9123400002'])->assertCreated();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123400002'])->assertForbidden(); // pending approval
        User::where('phone', '9123400002')->update(['is_active' => 1]);

        $this->postJson('/api/auth/send-otp', ['phone' => '9123400002'])->assertOk();
        $otp = User::where('phone', '9123400002')->value('otp');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);
        if ($otp !== '123456') {
            $this->postJson('/api/auth/verify-otp', ['phone' => '9123400002', 'otp' => '123456'])->assertStatus(422); // no shortcut in production
        }
        $this->postJson('/api/auth/verify-otp', ['phone' => '9123400002', 'otp' => $otp])->assertOk()->assertJsonPath('user.role', 'referral_partner');
        $this->assertNull(User::where('phone', '9123400002')->value('otp')); // single use

        $this->postJson('/api/auth/register', ['name' => 'Again', 'phone' => '9123400002'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(1, User::where('phone', '9123400002')->count());
    }
}
