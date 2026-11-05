<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use App\Support\PartnerCommonCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesWhatsappOtp;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * ONE common partner access code shared by all approved referral partners. Runs as production
 * (no development OTP); WhatsApp is faked — nothing is sent.
 */
class PartnerCommonCodeTest extends TestCase
{
    use RefreshDatabase;
    use FakesWhatsappOtp;

    private const PW = 'Correct-Horse-9!';

    private const ENDED = 'Your partner access code session has ended. Please sign in again.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        config(['partner_access.enabled' => true, 'partner_access.max_failures_per_phone' => 5, 'partner_access.lockout_minutes' => 15, 'partner_access.max_failures_per_hour' => 100]);
        $this->fakeWhatsappOtp();
        Queue::fake();
        RateLimiter::clear('partner-common-code-fail-global');
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
    }

    private function partner(string $phone, ?int $active = 1, string $name = 'Partner'): User
    {
        return User::create(['name' => $name, 'phone' => $phone, 'role' => 'referral_partner', 'is_active' => $active]);
    }

    private function admin(string $email = 'boss@example.test', string $phone = '9123477999'): User
    {
        return User::firstOrCreate(['email' => $email], ['name' => 'Boss', 'phone' => $phone, 'password' => self::PW, 'role' => 'admin', 'is_active' => 1]);
    }

    private function adminToken(?User $u = null): string
    {
        $u ??= $this->admin();
        $this->resetAuth();

        return $this->postJson('/api/admin/auth/login', ['email' => $u->email, 'password' => self::PW])->assertOk()->json('token');
    }

    private function verify(string $phone, string $code, string $ip = '127.0.0.1')
    {
        $this->resetAuth();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/verify-otp', ['phone' => $phone, 'otp' => $code]);
    }

    private function as(string $token, string $method, string $uri, array $data = [])
    {
        $this->resetAuth();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function wrong(string $code, int $n = 1): string
    {
        return str_pad((string) (((int) $code + $n) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    // 1–2 ─────────────────────────────────────────────────────────────────

    public function test_several_approved_partners_sign_in_with_the_same_code_and_see_only_their_own_leads(): void
    {
        $p1 = $this->partner('9123477001', 1, 'One');
        $p2 = $this->partner('9123477002', 1, 'Two');
        $p3 = $this->partner('9123477003', 1, 'Three');
        Lead::create(['referral_partner_id' => $p1->id, 'client_name' => 'Lead of One', 'client_phone' => '9000000201', 'type' => 'B2C', 'status' => 'new']);
        Lead::create(['referral_partner_id' => $p2->id, 'client_name' => 'Lead of Two', 'client_phone' => '9000000202', 'type' => 'B2C', 'status' => 'new']);
        $code = PartnerCommonCode::set(null, 'Boss')['code'];

        foreach ([[$p1, 'Lead of One'], [$p2, 'Lead of Two'], [$p3, null]] as [$p, $own]) {
            $this->resetAuth();
            $this->postJson('/api/auth/send-otp', ['phone' => $p->phone])->assertOk()->assertJsonPath('delivery', 'temporary_code');
            $t = $this->verify($p->phone, $code)->assertOk()->assertJsonPath('user.id', $p->id)->json('token');
            $names = collect($this->as($t, 'GET', '/api/leads')->assertOk()->json('data'))->pluck('client_name')->all();
            $this->assertSame($own ? [$own] : [], $names, "partner {$p->name} sees other partners' leads");
            $this->as($t, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('user.id', $p->id);
            $this->as($t, 'GET', '/api/admin/users')->assertForbidden();
        }
        Http::assertNothingSent(); // no WhatsApp code while the common code is active
    }

    // 3–4 ─────────────────────────────────────────────────────────────────

    public function test_pending_rejected_and_unknown_numbers_cannot_sign_in_with_the_code(): void
    {
        $this->partner('9123477011', null);
        $this->partner('9123477012', 0);
        $code = PartnerCommonCode::set(null, 'Boss')['code'];

        $this->verify('9123477011', $code)->assertForbidden()->assertJsonPath('message', 'Your registration is pending approval by the admin.');
        $this->verify('9123477012', $code)->assertForbidden()->assertJsonPath('message', 'Your registration has been rejected. Please contact support.');
        $this->verify('9123477013', $code)->assertNotFound();
        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123477011'])->assertForbidden();
    }

    public function test_a_session_ends_when_the_partner_is_no_longer_approved(): void
    {
        $p = $this->partner('9123477014');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $t = $this->verify($p->phone, $code)->assertOk()->json('token');
        DB::table('users')->where('id', $p->id)->update(['is_active' => 0]);
        $this->as($t, 'GET', '/api/leads')->assertUnauthorized()->assertJsonPath('message', self::ENDED);
    }

    // 6 — admin login ─────────────────────────────────────────────────────

    public function test_admin_login_and_admin_sessions_are_unaffected(): void
    {
        $admin = $this->admin();
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $at = $this->adminToken($admin);
        $this->as($at, 'GET', '/api/admin/users')->assertOk();

        // The common code never authenticates an administrator: not at admin login, not at partner login.
        $this->resetAuth();
        $this->postJson('/api/admin/auth/login', ['email' => $admin->email, 'password' => $code])->assertUnauthorized();
        $this->verify($admin->phone, $code)->assertNotFound();

        PartnerCommonCode::set(null, 'Boss');
        PartnerCommonCode::revokeActive('revoked_by_admin', 'Boss');
        $this->as($at, 'GET', '/api/admin/users')->assertOk();
        $this->assertNull(JWTAuth::setToken($at)->getPayload()->get('pcc'));
    }

    // 7–8 — changing / revoking ends sessions ─────────────────────────────

    public function test_changing_the_code_ends_sessions_and_only_the_new_code_works(): void
    {
        $p1 = $this->partner('9123477021');
        $p2 = $this->partner('9123477022');
        $old = PartnerCommonCode::set(null, 'Boss')['code'];
        $t1 = $this->verify($p1->phone, $old)->assertOk()->json('token');
        $t2 = $this->verify($p2->phone, $old)->assertOk()->json('token');
        $refreshed = $this->as($t1, 'POST', '/api/auth/refresh')->assertOk()->json('token');

        $res = $this->as($this->adminToken(), 'POST', '/api/admin/partner-common-code', ['generate' => true])->assertCreated();
        $new = $res->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $new);
        $this->assertNotSame($old, $new);

        foreach ([$refreshed, $t2] as $t) {
            foreach ([['GET', '/api/leads'], ['GET', '/api/auth/me'], ['POST', '/api/auth/refresh'], ['POST', '/api/leads']] as [$m, $uri]) {
                $this->as($t, $m, $uri, ['client_name' => 'C', 'client_phone' => '9000000203', 'type' => 'B2C'])->assertUnauthorized();
            }
        }
        $this->assertSame(0, Lead::count());
        $this->verify($p1->phone, $old)->assertStatus(422);
        $this->verify($p1->phone, $new)->assertOk();
        $this->verify($p2->phone, $new)->assertOk();
    }

    public function test_revoking_the_code_ends_sessions_and_returns_partners_to_the_whatsapp_otp(): void
    {
        $p = $this->partner('9123477031');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $t = $this->verify($p->phone, $code)->assertOk()->json('token');

        $this->as($this->adminToken(), 'DELETE', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('revoked', true)->assertJsonPath('status.active', false);
        $this->as($t, 'GET', '/api/leads')->assertUnauthorized()->assertJsonPath('message', self::ENDED);
        $this->verify($p->phone, $code)->assertStatus(422);

        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $p->phone])->assertOk()->assertJsonPath('delivery', 'sent');
        $this->verify($p->phone, $this->sentOtpCode())->assertOk();
        $this->assertSame('revoked_by_admin', DB::table('partner_common_codes')->value('revoked_reason'));
        $this->assertSame('Boss', DB::table('partner_common_codes')->value('revoked_by'));
    }

    public function test_switching_the_feature_off_disables_the_code_and_its_sessions(): void
    {
        $p = $this->partner('9123477032');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $t = $this->verify($p->phone, $code)->assertOk()->json('token');
        config(['partner_access.enabled' => false]);
        $this->as($t, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $p->phone])->assertOk()->assertJsonPath('delivery', 'sent');
        $this->as($this->adminToken(), 'POST', '/api/admin/partner-common-code', ['generate' => true])->assertUnprocessable();
    }

    // 9 — rate limiting ───────────────────────────────────────────────────

    public function test_wrong_codes_lock_out_that_number_only(): void
    {
        $p1 = $this->partner('9123477041');
        $p2 = $this->partner('9123477042');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];

        for ($i = 1; $i <= 5; $i++) {
            $this->verify($p1->phone, $this->wrong($code, $i), "10.2.0.$i")->assertStatus($i < 5 ? 422 : 429);
        }
        // Locked: even the right code is refused for this number for a while …
        $this->verify($p1->phone, $code, '10.2.0.9')->assertStatus(429);
        // … other partners are unaffected, and the code itself stays active.
        $this->verify($p2->phone, $code, '10.2.0.10')->assertOk();
        $this->travel(16)->minutes();
        $this->verify($p1->phone, $code, '10.2.0.11')->assertOk();
    }

    public function test_many_wrong_codes_across_numbers_revoke_the_code(): void
    {
        config(['partner_access.max_failures_per_hour' => 8]);
        $phones = array_map(fn ($i) => '91234770'.(50 + $i), range(0, 3));
        foreach ($phones as $ph) {
            $this->partner($ph);
        }
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $statuses = [];
        $n = 0;
        foreach ($phones as $ph) {
            for ($i = 1; $i <= 2; $i++) {
                $statuses[] = $this->verify($ph, $this->wrong($code, ++$n), "10.3.0.$n")->status();
            }
        }
        $this->assertSame('too_many_attempts', DB::table('partner_common_codes')->value('revoked_reason'));
        $this->assertNull(PartnerCommonCode::active());
        $this->verify($phones[0], $code, '10.3.0.99')->assertStatus(422); // no code active any more
    }

    public function test_the_existing_per_number_and_per_ip_request_limits_still_apply(): void
    {
        $p = $this->partner('9123477061');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        config(['partner_access.max_failures_per_phone' => 50]); // isolate the route throttle (10/min per number)
        for ($i = 0; $i < 10; $i++) {
            $this->verify($p->phone, $this->wrong($code, $i + 1), "10.4.0.$i");
        }
        $this->verify($p->phone, $code, '10.4.0.99')->assertStatus(429);
    }

    // 10 — normal WhatsApp OTP ────────────────────────────────────────────

    public function test_without_an_active_code_the_whatsapp_otp_works_as_before(): void
    {
        $p = $this->partner('9123477071');
        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $p->phone])->assertOk()->assertJsonPath('delivery', 'sent');
        $otp = $this->sentOtpCode();
        $t = $this->verify($p->phone, $otp)->assertOk()->json('token');
        $this->verify($p->phone, $otp)->assertStatus(422);         // still single-use
        $this->verify($p->phone, '123456')->assertStatus(422);     // no development shortcut in production
        $this->assertNull(JWTAuth::setToken($t)->getPayload()->get('pcc'));
        $this->as($t, 'GET', '/api/leads')->assertOk();
        // A WhatsApp-OTP session is not ended by setting or revoking the common code.
        PartnerCommonCode::set(null, 'Boss');
        PartnerCommonCode::revokeActive('revoked_by_admin', 'Boss');
        $this->as($t, 'GET', '/api/leads')->assertOk();
    }

    // Storage, admin management, exposure ─────────────────────────────────

    public function test_the_code_is_stored_only_as_a_salted_keyed_hash(): void
    {
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $row = DB::table('partner_common_codes')->first();
        $this->assertSame(PartnerCommonCode::hash($row->code_salt, $code), $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row));
        $this->assertNotSame(hash('sha256', $code), $row->code_hash);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame(PartnerCommonCode::hash($row->code_salt, $code), $row->code_hash); // useless without APP_KEY
    }

    public function test_admin_generates_sees_it_once_sets_changes_and_revokes(): void
    {
        Log::spy();
        $at = $this->adminToken();
        $this->as($at, 'GET', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('active', false);

        $res = $this->as($at, 'POST', '/api/admin/partner-common-code', ['generate' => true])->assertCreated();
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $code = $res->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertFalse(PartnerCommonCode::weak($code));

        // Status never reveals it (nor hash / salt).
        $status = $this->as($at, 'GET', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('active', true)->assertJsonPath('current.created_by', 'Boss');
        foreach ([$code, 'code_hash', 'code_salt'] as $secret) {
            $this->assertStringNotContainsString($secret, $status->getContent());
        }

        // Set by hand: validated, not echoed back; weak codes refused.
        foreach (['12345', '1234567', 'abcdef', '000000', '123456', '654321', '121212'] as $bad) {
            $this->as($at, 'POST', '/api/admin/partner-common-code', ['code' => $bad])->assertUnprocessable()->assertJsonValidationErrors('code');
        }
        $set = $this->as($at, 'POST', '/api/admin/partner-common-code', ['code' => '480317'])->assertCreated()->assertJsonPath('code', null);
        $this->assertStringNotContainsString('480317', $set->getContent());
        $this->assertSame(1, DB::table('partner_common_codes')->whereNull('revoked_at')->count()); // only one active
        $this->assertSame('replaced', DB::table('partner_common_codes')->orderBy('id')->value('revoked_reason'));

        $this->as($at, 'DELETE', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('revoked', true);
        $this->as($at, 'DELETE', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('revoked', false);

        foreach (['info', 'warning', 'error', 'notice', 'debug'] as $level) {
            Log::shouldNotHaveReceived($level, [\Mockery::on(fn ($m, $ctx = []) => str_contains($m.json_encode($ctx), $code) || str_contains($m.json_encode($ctx), '480317'))]);
        }
    }

    public function test_only_admin_sessions_can_manage_the_code(): void
    {
        $p = $this->partner('9123477081');
        $code = PartnerCommonCode::set(null, 'Boss')['code'];
        $pt = $this->verify($p->phone, $code)->assertOk()->json('token');
        foreach ([['GET', '/api/admin/partner-common-code'], ['POST', '/api/admin/partner-common-code'], ['DELETE', '/api/admin/partner-common-code']] as [$m, $uri]) {
            $this->as($pt, $m, $uri, ['generate' => true])->assertForbidden();
        }
        $staff = User::create(['name' => 'Staff', 'phone' => '9123477082', 'role' => 'internal_team', 'is_active' => 1]);
        $this->resetAuth();
        $this->as(auth('api')->login($staff), 'POST', '/api/admin/partner-common-code', ['generate' => true])->assertForbidden();
        $this->resetAuth();
        $this->flushHeaders();
        $this->postJson('/api/admin/partner-common-code', ['generate' => true])->assertUnauthorized();
        $this->assertNotNull(PartnerCommonCode::active()); // unchanged
    }

    public function test_sessions_from_the_retired_per_partner_codes_are_ended(): void
    {
        $p = $this->partner('9123477091');
        $this->resetAuth();
        $old = auth('api')->claims(['ctx' => 'partner', 'pac' => 1])->login($p); // a token from the old per-partner mechanism
        $this->as($old, 'GET', '/api/leads')->assertUnauthorized()->assertJsonPath('message', self::ENDED);
    }

    public function test_console_command_generates_shows_sets_and_revokes(): void
    {
        $this->artisan('partner:common-code', ['action' => 'generate', '--by' => 'Boss'])->expectsOutputToContain('shown once')->assertSuccessful();
        $this->assertNotNull(PartnerCommonCode::active());
        $this->artisan('partner:common-code', ['action' => 'status'])->expectsOutputToContain('Active — set by Boss')->assertSuccessful();
        $this->artisan('partner:common-code', ['action' => 'set'])->expectsQuestion('New 6-digit code (hidden)', '111111')->assertFailed();   // weak
        $this->artisan('partner:common-code', ['action' => 'set'])->expectsQuestion('New 6-digit code (hidden)', '480317')->assertSuccessful();
        $p = $this->partner('9123477095');
        $this->verify($p->phone, '480317')->assertOk();
        $this->artisan('partner:common-code', ['action' => 'revoke'])->expectsOutputToContain('revoked')->assertSuccessful();
        $this->assertNull(PartnerCommonCode::active());
    }

    /** Guard: every route a partner token can use (auth:api without admin/internal) runs the session check. */
    public function test_every_partner_reachable_route_checks_the_session(): void
    {
        $missing = [];
        foreach (app('router')->getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if (! in_array('auth:api', $mw, true) || array_intersect(['admin', 'internal'], $mw)) {
                continue;
            }
            if (! in_array('partner_session', $mw, true)) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        $this->assertSame([], $missing, 'Partner-reachable routes without partner_session.');
    }
}
