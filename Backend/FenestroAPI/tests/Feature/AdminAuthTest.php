<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\FakesWhatsappOtp;

    private const PASSWORD = 'Correct-Horse-9!';

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('admin-login:admin@example.test|127.0.0.1');
    }

    private function makeUser(array $attrs = []): User
    {
        return User::create($attrs + [
            'name'      => 'Admin',
            'email'     => 'admin@example.test',
            'phone'     => '9000000001',
            'password'  => self::PASSWORD,
            'role'      => 'admin',
            'is_active' => 1,
        ]);
    }

    public function test_password_is_stored_hashed(): void
    {
        $user = $this->makeUser();
        $this->assertNotSame(self::PASSWORD, $user->fresh()->password);
        $this->assertStringStartsWith('$2y$', $user->fresh()->password);
    }

    public function test_admin_can_log_in_with_correct_credentials(): void
    {
        $this->makeUser();

        $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'expires_in', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonMissingPath('user.password');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => 'wrong'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_unknown_email_gets_the_same_error(): void
    {
        $this->postJson('/api/admin/auth/login', ['email' => 'nobody@example.test', 'password' => 'whatever'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_non_admin_with_valid_password_cannot_log_in(): void
    {
        $this->makeUser(['role' => 'referral_partner']);

        $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_deactivated_admin_cannot_log_in(): void
    {
        $this->makeUser(['is_active' => 0]);

        $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])
            ->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => 'wrong'])->assertStatus(401);
        }

        // Even the correct password is refused once the limit is hit.
        $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])
            ->assertStatus(429);
    }

    public function test_public_admin_registration_and_reset_endpoints_are_gone(): void
    {
        $this->postJson('/api/admin/auth/register', [
            'name' => 'Intruder', 'email' => 'x@example.test', 'phone' => '9000000009',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->makeUser();
        $this->postJson('/api/admin/auth/forgot-password', ['email' => 'admin@example.test'])->assertNotFound();
        $this->postJson('/api/admin/auth/reset-password', [])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_public_partner_registration_can_never_create_an_admin(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Intruder', 'phone' => '9000000019', 'company' => 'X',
            'role' => 'admin', 'is_active' => 1, 'email' => 'x2@example.test', 'password' => 'Whatever-Pass-1!',
        ])->assertSuccessful();

        $u = User::where('phone', '9000000019')->firstOrFail();
        $this->assertSame('referral_partner', $u->role);   // role in the request is ignored
        $this->assertNull($u->is_active);                   // waits for admin approval
        $this->assertNull($u->email);
        // And it cannot sign in at the admin login.
        $this->postJson('/api/admin/auth/login', ['email' => 'x2@example.test', 'password' => 'Whatever-Pass-1!'])->assertStatus(401);
    }

    public function test_admin_account_management_requires_a_signed_in_admin(): void
    {
        $payload = ['name' => 'X', 'email' => 'x3@example.test', 'phone' => '9000000029', 'password' => 'Brand-New-Pass-77!', 'password_confirmation' => 'Brand-New-Pass-77!'];
        $this->postJson('/api/admin/users', $payload)->assertStatus(401);
        $this->putJson('/api/admin/profile/password', ['current_password' => 'x', 'password' => 'Brand-New-Pass-77!', 'password_confirmation' => 'Brand-New-Pass-77!'])->assertStatus(401);
        $this->assertDatabaseMissing('users', ['email' => 'x3@example.test']);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $this->makeUser();
        $token = $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->json('token');

        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertOk();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Fresh app state so the guard doesn't reuse the cached user.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertStatus(401);
    }

    public function test_otp_login_never_issues_an_admin_token(): void
    {
        $this->makeUser(); // admin with phone 9000000001

        // Neither the bypass code nor a requested OTP works for an admin phone.
        $this->postJson('/api/auth/send-otp', ['phone' => '9000000001'])->assertNotFound();
        $this->postJson('/api/auth/verify-otp', ['phone' => '9000000001', 'otp' => '123456'])
            ->assertNotFound()
            ->assertJsonMissingPath('token');
    }

    public function test_partner_otp_login_still_works(): void
    {
        User::create(['name' => 'Partner', 'phone' => '9000000005', 'role' => 'referral_partner', 'is_active' => 1]);

        $this->fakeWhatsappOtp();
        $this->postJson('/api/auth/send-otp', ['phone' => '9000000005'])->assertOk();
        $otp = $this->sentOtpCode();

        $this->postJson('/api/auth/verify-otp', ['phone' => '9000000005', 'otp' => $otp])
            ->assertOk()
            ->assertJsonPath('user.role', 'referral_partner');
    }

    public function test_fixed_otp_works_on_local_and_test_servers_only(): void
    {
        User::create(['name' => 'Partner', 'phone' => '9000000005', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->postJson('/api/auth/verify-otp', ['phone' => '9000000005', 'otp' => '123456'])->assertOk(); // testing

        $this->app['env'] = 'production';
        try {
            $this->postJson('/api/auth/verify-otp', ['phone' => '9000000005', 'otp' => '123456'])
                ->assertStatus(422)->assertJsonMissingPath('token');
            // The real code still works in production (WhatsApp faked — nothing is sent).
            $this->fakeWhatsappOtp();
            $this->postJson('/api/auth/send-otp', ['phone' => '9000000005'])->assertOk();
            $otp = $this->sentOtpCode();
            $this->assertNotSame('123456', $otp);
            $this->postJson('/api/auth/verify-otp', ['phone' => '9000000005', 'otp' => $otp])->assertOk()->assertJsonPath('user.role', 'referral_partner');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_pending_and_rejected_partners_cannot_sign_in_even_with_a_valid_code(): void
    {
        foreach (['9000000006' => null, '9000000007' => 0] as $phone => $state) {
            $partner = User::create(['name' => 'Partner', 'phone' => $phone, 'role' => 'referral_partner', 'is_active' => $state]);
            \App\Support\PartnerOtp::issue($partner, '654321'); // a valid, unexpired code
            $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'otp' => '654321'])->assertForbidden()->assertJsonMissingPath('token');
            $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'otp' => '123456'])->assertForbidden()->assertJsonMissingPath('token');
        }
    }

    public function test_partner_otp_endpoints_are_rate_limited(): void
    {
        User::create(['name' => 'Partner', 'phone' => '9000000008', 'role' => 'referral_partner', 'is_active' => 1]);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/verify-otp', ['phone' => '9000000008', 'otp' => '000001'])->assertStatus(422);
        }
        $this->postJson('/api/auth/verify-otp', ['phone' => '9000000008', 'otp' => '000001'])->assertStatus(429);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/send-otp', ['phone' => '9000000008'])->assertOk();
        }
        $this->postJson('/api/auth/send-otp', ['phone' => '9000000008'])->assertStatus(429);
    }

    public function test_deactivating_an_admin_ends_existing_sessions(): void
    {
        $user = $this->makeUser();
        $token = $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->json('token');
        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertOk();

        $user->update(['is_active' => 0]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertStatus(403);
        $this->withToken($token)->postJson('/api/measurements', [])->assertStatus(403); // Fenestro "internal" route
        $this->withToken($token)->postJson('/api/auth/refresh')->assertStatus(401);
    }

    public function test_password_reset_ends_existing_sessions(): void
    {
        $user = $this->makeUser();
        $token = $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->json('token');
        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertOk();

        $this->artisan('admin:reset-password', ['email' => 'admin@example.test'])
            ->expectsQuestion('New password (min 12 chars, mixed case, number, symbol)', 'Brand-New-Pass-77!')
            ->expectsQuestion('Confirm new password', 'Brand-New-Pass-77!')
            ->assertSuccessful();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/admin/order-management/data')->assertStatus(401);
        $this->withToken($token)->postJson('/api/auth/refresh')->assertStatus(401);

        // The new password works and its token is accepted.
        $fresh = $this->postJson('/api/admin/auth/login', ['email' => 'admin@example.test', 'password' => 'Brand-New-Pass-77!'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($fresh)->getJson('/api/admin/order-management/data')->assertOk();
        $this->assertNotSame($user->password, $user->fresh()->password);
    }

    public function test_admin_create_command_provisions_a_hashed_admin(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ops', '--email' => 'ops@example.test', '--phone' => '9000000002'])
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $user = User::where('email', 'ops@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertNotSame(self::PASSWORD, $user->password);
    }

    public function test_admin_create_command_rejects_weak_password(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ops', '--email' => 'ops@example.test', '--phone' => '9000000002'])
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'ops@example.test']);
    }

    public function test_admin_create_command_validates_phone_and_never_duplicates_an_account(): void
    {
        $ask = fn ($phone, $email = 'ops@example.test') => $this->artisan('admin:create', ['--name' => 'Ops Admin', '--email' => $email, '--phone' => $phone])
            ->expectsQuestion('Password (min 12 chars, mixed case, number, symbol)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD);

        // Phone must be exactly 10 digits.
        foreach (['93604747', '93604747410', '+919360474741', '93604-74741'] as $bad) {
            $ask($bad)->assertFailed();
        }
        $this->assertDatabaseMissing('users', ['email' => 'ops@example.test']);

        // A valid number is stored exactly as entered; the account is an active admin who can log in.
        $ask('9360474741')->assertSuccessful();
        $u = User::where('email', 'ops@example.test')->firstOrFail();
        $this->assertSame(['Ops Admin', '9360474741', 'admin', 1], [$u->name, $u->phone, $u->role, (int) $u->is_active]);
        $this->postJson('/api/admin/auth/login', ['email' => 'ops@example.test', 'password' => self::PASSWORD])->assertOk();

        // Running it again never creates a second account or overwrites the first.
        $hash = $u->password;
        $ask('9360474741', 'other@example.test')->assertFailed();   // phone already used
        $ask('9000000077', 'ops@example.test')->assertFailed();     // email already used
        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame($hash, $u->fresh()->password);
    }
}
