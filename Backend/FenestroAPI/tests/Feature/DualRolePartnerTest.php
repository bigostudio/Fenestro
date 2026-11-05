<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\PartnerProfile;
use App\Models\User;
use App\Support\PartnerCommonCode;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesWhatsappOtp;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * An administrator registers as a referral partner with the SAME account and mobile number.
 * The admin identity (id, phone, role, credentials, permissions, sessions) never changes; the
 * partner side is a linked partner_profiles row with its own approval and partner login (WhatsApp OTP or the common partner access code).
 * Partner-login sessions never carry admin rights. Runs as production (no development OTP);
 * WhatsApp is faked — nothing is sent.
 */
class DualRolePartnerTest extends TestCase
{
    use RefreshDatabase;
    use FakesWhatsappOtp;

    private const PW = 'Correct-Horse-9!';

    private const NOT_A_PARTNER = 'This number is not registered as a referral partner. Please register first. Prestige Ventures staff sign in at Admin Login (/admin/login).';

    private User $a;   // administrator who applies as a partner

    private User $b;   // another administrator (approver)

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        config(['partner_access.enabled' => true]);
        $this->fakeWhatsappOtp();
        Queue::fake();
        $this->a = $this->admin('a@example.test', '9123488001', 'Asha Admin');
        $this->b = $this->admin('b@example.test', '9123488002', 'Bala Admin');
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function admin(string $email, string $phone, string $name): User
    {
        return User::create(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::PW, 'role' => 'admin', 'is_active' => 1]);
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
    }

    private function adminLogin(User $u): string
    {
        $this->resetAuth();

        return $this->postJson('/api/admin/auth/login', ['email' => $u->email, 'password' => self::PW])->assertOk()->json('token');
    }

    private function as(string $token, string $method, string $uri, array $data = [])
    {
        $this->resetAuth();

        return $this->withToken($token)->json($method, $uri, $data);
    }

    private function apply(User $u, array $data = [])
    {
        return $this->as($this->adminLogin($u), 'POST', '/api/admin/partner-application', $data + ['name' => $u->name, 'phone' => $u->phone, 'company' => 'Asha Interiors']);
    }

    private function decide(User $actor, User $applicant, bool $approve)
    {
        return $this->as($this->adminLogin($actor), 'PATCH', "/api/admin/partners/{$applicant->id}/status", ['is_active' => $approve]);
    }

    private function approveA(): void
    {
        $this->apply($this->a)->assertCreated();
        $this->decide($this->b, $this->a, true)->assertOk();
    }

    /** Partner login through WhatsApp OTP (faked) → partner-session token. */
    private function partnerLogin(string $phone): string
    {
        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $phone])->assertOk()->assertJsonPath('delivery', 'sent');
        $code = $this->sentOtpCode();
        $this->resetAuth();

        return $this->postJson('/api/auth/verify-otp', ['phone' => $phone, 'otp' => $code])->assertOk()->json('token');
    }

    private function claim(string $token, string $name): mixed
    {
        return JWTAuth::setToken($token)->getPayload()->get($name);
    }

    private function adminSnapshot(User $u): array
    {
        return (array) DB::table('users')->where('id', $u->id)->first(['id', 'name', 'email', 'phone', 'password', 'role', 'is_active', 'company']);
    }

    // 1–4 ─────────────────────────────────────────────────────────────────

    public function test_an_admin_applies_with_the_same_number_and_the_admin_account_is_untouched(): void
    {
        $before = $this->adminSnapshot($this->a);
        $users = User::count();

        $res = $this->apply($this->a)->assertCreated()
            ->assertJsonPath('application.status', 'pending')
            ->assertJsonPath('application.company', 'Asha Interiors');

        $this->assertSame($before, $this->adminSnapshot($this->a), 'admin row changed (id, phone, role, credentials, active)');
        $this->assertSame($users, User::count(), 'a second user was created');
        $this->assertSame(1, User::where('phone', $this->a->phone)->count());
        $profile = PartnerProfile::where('user_id', $this->a->id)->firstOrFail();
        $this->assertSame('pending', $profile->status);
        $this->assertSame('Asha Admin', $profile->name);
        $this->assertStringNotContainsString('password', $res->getContent());

        // The admin session that applied keeps working on admin pages.
        $token = $this->adminLogin($this->a);
        $this->as($token, 'GET', '/api/admin/users')->assertOk();
        $this->as($token, 'GET', '/api/admin/partner-application')->assertOk()->assertJsonPath('application.status', 'pending')->assertJsonPath('phone', $this->a->phone);
    }

    public function test_the_application_must_use_the_accounts_own_number_and_valid_details(): void
    {
        $this->apply($this->a, ['phone' => '9123488999'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->apply($this->a, ['phone' => $this->b->phone])->assertUnprocessable(); // someone else's number
        $this->apply($this->a, ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(0, PartnerProfile::count());
    }

    public function test_a_second_application_is_refused_and_the_database_enforces_one_profile_per_user(): void
    {
        $this->apply($this->a)->assertCreated();
        $this->apply($this->a)->assertStatus(409)->assertJsonPath('application.status', 'pending');
        $this->assertSame(1, PartnerProfile::where('user_id', $this->a->id)->count());

        $this->expectException(QueryException::class);
        PartnerProfile::create(['user_id' => $this->a->id, 'name' => 'Dup', 'status' => 'pending']);
    }

    public function test_only_administrators_can_apply_and_only_through_an_admin_session(): void
    {
        $staff = User::create(['name' => 'Staff', 'phone' => '9123488010', 'role' => 'internal_team', 'is_active' => 1]);
        $this->as(auth('api')->login($staff), 'POST', '/api/admin/partner-application', ['name' => 'Staff', 'phone' => '9123488010'])->assertForbidden();
        $partner = User::create(['name' => 'P', 'phone' => '9123488011', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->resetAuth();
        $this->as(auth('api')->login($partner), 'POST', '/api/admin/partner-application', ['name' => 'P', 'phone' => '9123488011'])->assertForbidden();
        $this->resetAuth();
        $this->flushHeaders(); // withToken() above persists; send this one with no token at all
        $this->postJson('/api/admin/partner-application', ['name' => 'X', 'phone' => '9123488001'])->assertUnauthorized();
        $this->assertSame(0, PartnerProfile::count());
    }

    // 5–7 ─────────────────────────────────────────────────────────────────

    public function test_pending_application_is_listed_for_review_and_blocks_partner_login(): void
    {
        $this->apply($this->a)->assertCreated();

        $rows = collect($this->as($this->adminLogin($this->b), 'GET', '/api/admin/partners/pending')->assertOk()->json('data'));
        $row = $rows->firstWhere('id', $this->a->id);
        $this->assertNotNull($row);
        $this->assertSame('admin', $row['account_type']);
        $this->assertNull($row['is_active']);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('role', $row);

        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $this->a->phone])->assertForbidden()->assertJsonPath('message', 'Your registration is pending approval by the admin.');
    }

    public function test_self_approval_is_refused_and_another_admin_approves_only_the_partner_profile(): void
    {
        $this->apply($this->a)->assertCreated();
        $before = $this->adminSnapshot($this->a);

        $this->decide($this->a, $this->a, true)->assertForbidden()->assertJsonPath('message', 'You cannot approve or reject your own partner application. Another administrator must decide it.');
        $this->decide($this->a, $this->a, false)->assertForbidden();
        $this->assertSame('pending', PartnerProfile::where('user_id', $this->a->id)->value('status'));

        $this->decide($this->b, $this->a, true)->assertOk();
        $p = PartnerProfile::where('user_id', $this->a->id)->first();
        $this->assertSame('approved', $p->status);
        $this->assertSame($this->b->id, $p->decided_by);
        $this->assertSame($before, $this->adminSnapshot($this->a), 'approval touched the admin account');
        $this->decide($this->b, $this->a, false)->assertStatus(422); // decided already

        // Listed with approved partners; Admin login unchanged.
        $rows = collect($this->as($this->adminLogin($this->b), 'GET', '/api/admin/partners/approved')->assertOk()->json('data'));
        $this->assertSame('admin', $rows->firstWhere('id', $this->a->id)['account_type']);
        $this->as($this->adminLogin($this->a), 'GET', '/api/admin/users')->assertOk();
    }

    public function test_rejection_denies_partner_login_but_never_admin_login(): void
    {
        $this->apply($this->a)->assertCreated();
        $this->decide($this->b, $this->a, false)->assertOk();
        $this->assertSame('rejected', PartnerProfile::where('user_id', $this->a->id)->value('status'));

        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $this->a->phone])->assertForbidden()->assertJsonPath('message', 'Your registration has been rejected. Please contact support.');
        $this->as($this->adminLogin($this->a), 'GET', '/api/admin/users')->assertOk();
        $this->assertSame('admin', $this->a->fresh()->role);
        $this->assertSame(1, (int) $this->a->fresh()->is_active);
    }

    public function test_the_approve_endpoint_never_touches_a_staff_account_without_an_application(): void
    {
        $this->decide($this->b, $this->a, true)->assertNotFound();
        $this->decide($this->b, $this->a, false)->assertNotFound();
        $this->assertSame(1, (int) $this->a->fresh()->is_active);
        $this->assertSame(0, PartnerProfile::count());
    }

    // 8–9, 13 ─────────────────────────────────────────────────────────────

    public function test_partner_login_is_a_separate_partner_session_with_no_admin_rights(): void
    {
        $this->approveA();
        $other = User::create(['name' => 'Other Partner', 'phone' => '9123488020', 'role' => 'referral_partner', 'is_active' => 1]);
        Lead::create(['referral_partner_id' => $other->id, 'client_name' => 'Not mine', 'client_phone' => '9000000101', 'type' => 'B2C', 'status' => 'new']);

        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $this->a->phone])->assertOk()->assertJsonPath('delivery', 'sent');
        $code = $this->sentOtpCode();
        $this->resetAuth();
        $res = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()
            ->assertJsonPath('user.id', $this->a->id)
            ->assertJsonPath('user.role', 'referral_partner')
            ->assertJsonPath('user.company', 'Asha Interiors');
        $pt = $res->json('token');
        $this->assertSame('partner', $this->claim($pt, 'ctx'));

        // Partner endpoints: as a partner — own leads only.
        $this->as($pt, 'POST', '/api/leads', ['client_name' => 'Mine', 'client_phone' => '9000000102', 'type' => 'B2B'])->assertCreated();
        $leads = collect($this->as($pt, 'GET', '/api/leads')->assertOk()->json('data'));
        $this->assertSame(['Mine'], $leads->pluck('client_name')->all());
        $this->as($pt, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('user.role', 'referral_partner');

        // Admin-only and internal endpoints: refused, even though the account is an administrator.
        foreach ([['GET', '/api/admin/users'], ['GET', '/api/admin/partners/pending'], ['GET', '/api/admin/leads'], ['GET', '/api/admin/order-management/data'],
            ['GET', '/api/admin/partner-application'], ['PATCH', "/api/admin/partners/{$other->id}/status"]] as [$m, $uri]) {
            $this->as($pt, $m, $uri, ['is_active' => false])->assertForbidden();
        }
        $this->as($pt, 'DELETE', '/api/leads/'.Lead::where('client_name', 'Not mine')->value('id'))->assertForbidden();
        $this->assertSame(2, Lead::count());

        // Refreshing keeps it a partner session.
        $refreshed = $this->as($pt, 'POST', '/api/auth/refresh')->assertOk()->json('token');
        $this->assertSame('partner', $this->claim($refreshed, 'ctx'));
        $this->as($refreshed, 'GET', '/api/admin/users')->assertForbidden();

        // The same person's ADMIN session is unchanged: admin pages, and all leads.
        $at = $this->adminLogin($this->a);
        $this->assertNull($this->claim($at, 'ctx'));
        $this->as($at, 'GET', '/api/admin/users')->assertOk();
        $this->assertSame(2, count($this->as($at, 'GET', '/api/leads')->assertOk()->json('data')));
    }

    public function test_an_admin_token_issued_after_a_partner_token_carries_no_partner_claims(): void
    {
        $this->approveA();
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        $this->resetAuth();
        $pt = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()->json('token');
        $this->assertSame('partner', $this->claim($pt, 'ctx'));
        $this->assertNotNull($this->claim($pt, 'pcc'));

        // Same process, no reset of the JWT services: the next admin token must be clean.
        $at = $this->postJson('/api/admin/auth/login', ['email' => $this->a->email, 'password' => self::PW])->assertOk()->json('token');
        $this->assertNull($this->claim($at, 'ctx'));
        $this->assertNull($this->claim($at, 'pcc'));
    }

    // 10–12 (common partner access code) ──────────────────────────────────

    public function test_the_common_code_signs_in_the_admin_partner_only_as_a_partner(): void
    {
        $this->approveA();
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];

        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => $this->a->phone])->assertOk()->assertJsonPath('delivery', 'temporary_code');
        $this->resetAuth();
        $res = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()->assertJsonPath('user.role', 'referral_partner');
        $this->assertStringNotContainsString($code, $res->getContent());
        $pt = $res->json('token');

        $this->as($pt, 'GET', '/api/leads')->assertOk();
        $this->as($pt, 'GET', '/api/admin/users')->assertForbidden();   // never admin access
        $this->as($pt, 'GET', '/api/admin/leads')->assertForbidden();
        $this->as($pt, 'GET', '/api/admin/partner-common-code')->assertForbidden();

        // Admin LOGIN never accepts the common code (it takes email + password only).
        $this->resetAuth();
        $this->postJson('/api/admin/auth/login', ['email' => $this->a->email, 'password' => $code])->assertUnauthorized();
    }

    public function test_revoking_the_common_code_ends_partner_sessions_but_not_admin_access(): void
    {
        $this->approveA();
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        $this->resetAuth();
        $pt = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()->json('token');
        $at = $this->adminLogin($this->a);
        $this->as($at, 'GET', '/api/admin/users')->assertOk();

        // Revoked by another admin through the admin endpoint.
        $this->as($this->adminLogin($this->b), 'DELETE', '/api/admin/partner-common-code')->assertOk()->assertJsonPath('revoked', true);

        $this->as($pt, 'GET', '/api/leads')->assertUnauthorized()->assertJsonPath('message', 'Your partner access code session has ended. Please sign in again.');
        $this->as($at, 'GET', '/api/admin/users')->assertOk();         // existing admin session unaffected
        $this->as($at, 'GET', '/api/leads')->assertOk();
        $this->as($at, 'POST', '/api/auth/refresh')->assertOk();
        $this->adminLogin($this->a);                                   // admin login unaffected
        $this->assertSame(1, (int) $this->a->fresh()->is_active);
        $this->assertSame('approved', PartnerProfile::where('user_id', $this->a->id)->value('status')); // partner profile itself stays
    }

    public function test_changing_the_code_or_switching_it_off_does_not_affect_admin_access(): void
    {
        $this->approveA();
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        $this->resetAuth();
        $pt = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()->json('token');
        $at = $this->adminLogin($this->a);

        PartnerCommonCode::set(null, 'Bala Admin'); // changed
        $this->as($pt, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->as($at, 'GET', '/api/admin/users')->assertOk();

        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        $this->resetAuth();
        $pt = $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertOk()->json('token');
        config(['partner_access.enabled' => false]);
        $this->as($pt, 'GET', '/api/auth/me')->assertUnauthorized();
        $this->as($at, 'GET', '/api/admin/users')->assertOk();
    }

    public function test_wrong_codes_lock_out_only_the_partner_side(): void
    {
        $this->approveA();
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        $wrong = str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        for ($i = 0; $i < 5; $i++) {
            $this->resetAuth();
            $this->withServerVariables(['REMOTE_ADDR' => "10.9.0.$i"])->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $wrong]);
        }
        $this->resetAuth();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.9'])->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertStatus(429);
        $this->as($this->adminLogin($this->a), 'GET', '/api/admin/users')->assertOk();
    }

    public function test_the_common_code_never_opens_unapproved_or_non_partner_admins(): void
    {
        $code = PartnerCommonCode::set(null, 'Bala Admin')['code'];
        // An admin with no partner profile is not a partner at Partner login (unchanged answer).
        $this->resetAuth();
        $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertNotFound()->assertJsonPath('message', self::NOT_A_PARTNER);
        // Pending application: refused before the code is even checked.
        $this->apply($this->a)->assertCreated();
        $this->resetAuth();
        $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertForbidden()->assertJsonPath('message', 'Your registration is pending approval by the admin.');
        // Rejected application.
        $this->decide($this->b, $this->a, false)->assertOk();
        $this->resetAuth();
        $this->postJson('/api/auth/verify-otp', ['phone' => $this->a->phone, 'otp' => $code])->assertForbidden()->assertJsonPath('message', 'Your registration has been rejected. Please contact support.');
    }

    // 14 + lead attribution ───────────────────────────────────────────────

    public function test_ordinary_partner_registration_and_whatsapp_otp_login_still_work(): void
    {
        $this->resetAuth();
        $this->postJson('/api/auth/register', ['name' => 'Plain Partner', 'phone' => '9123488030', 'company' => 'PP'])->assertCreated();
        $u = User::where('phone', '9123488030')->firstOrFail();
        $this->assertSame('referral_partner', $u->role);
        $this->assertSame(0, PartnerProfile::count()); // ordinary partners get no profile row
        $this->resetAuth();
        $this->postJson('/api/auth/send-otp', ['phone' => '9123488030'])->assertForbidden();

        $this->decide($this->b, $u, true)->assertOk();
        $pt = $this->partnerLogin('9123488030');
        $this->as($pt, 'GET', '/api/auth/me')->assertOk()->assertJsonPath('user.role', 'referral_partner')->assertJsonPath('user.is_active', 1);
        $this->as($pt, 'POST', '/api/leads', ['client_name' => 'C', 'client_phone' => '9000000103', 'type' => 'B2C'])->assertCreated();
        $this->as($pt, 'GET', '/api/admin/users')->assertForbidden();

        // Registering with an admin's number is still blocked (no second identity) and explains the way.
        $this->resetAuth();
        $msg = $this->postJson('/api/auth/register', ['name' => 'X', 'phone' => $this->a->phone])->assertUnprocessable()->json('errors.phone.0');
        $this->assertStringContainsString('Settings → Partner Access', $msg);
        $this->assertSame(1, User::where('phone', $this->a->phone)->count());
    }

    public function test_an_approved_admin_partner_can_be_set_as_a_leads_referral_partner(): void
    {
        $this->approveA();
        $at = $this->adminLogin($this->b);
        $partners = collect($this->as($at, 'GET', '/api/admin/leads/config')->assertOk()->json('partners'));
        $this->assertSame('Asha Interiors', $partners->firstWhere('id', $this->a->id)['company']);
        $this->as($at, 'POST', '/api/admin/leads', ['client_name' => 'Referred by Asha', 'client_phone' => '9000000104', 'type' => 'B2C', 'referral_partner_id' => $this->a->id])->assertCreated();
        $this->as($at, 'POST', '/api/admin/leads', ['client_name' => 'Bad', 'client_phone' => '9000000105', 'type' => 'B2C', 'referral_partner_id' => $this->b->id])
            ->assertUnprocessable()->assertJsonValidationErrors('referral_partner_id'); // an admin without a partner profile
    }
}
