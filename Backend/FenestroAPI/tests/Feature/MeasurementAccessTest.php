<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Measurement;
use App\Models\User;
use App\Support\PartnerCommonCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesWhatsappOtp;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Measurement records are internal (admin / internal team) data. A caller who is refused must get the
 * same response whether or not the requested ID exists, so refusals never disclose record existence.
 * Access checks therefore run before route-model binding (see bootstrap/app.php).
 * Referral partners have no measurement endpoints: their "permitted" access is their own referrals
 * through /api/leads, which must keep working. Runs as production; WhatsApp is faked.
 */
class MeasurementAccessTest extends TestCase
{
    use RefreshDatabase;
    use FakesWhatsappOtp;

    private const PW = 'Correct-Horse-9!';

    private const MISSING = 987654;

    private User $admin;

    private User $p1;

    private User $p2;

    private Measurement $own;     // measurement on a lead referred by partner 1

    private Measurement $other;   // measurement on a lead referred by partner 2

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        config(['partner_access.enabled' => true]);
        $this->fakeWhatsappOtp();
        Queue::fake();
        RateLimiter::clear('partner-common-code-fail-global');

        $this->admin = User::create(['name' => 'Boss', 'email' => 'boss@example.test', 'phone' => '9123466999', 'password' => self::PW, 'role' => 'admin', 'is_active' => 1]);
        $this->p1 = User::create(['name' => 'One', 'phone' => '9123466001', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->p2 = User::create(['name' => 'Two', 'phone' => '9123466002', 'role' => 'referral_partner', 'is_active' => 1]);
        $this->own = $this->measurementFor($this->p1, 'Own Customer', '9000000301');
        $this->other = $this->measurementFor($this->p2, 'Other Customer', '9000000302');
        $this->code = PartnerCommonCode::set(null, 'Boss')['code'];
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function measurementFor(User $partner, string $name, string $phone): Measurement
    {
        $lead = Lead::create(['referral_partner_id' => $partner->id, 'client_name' => $name, 'client_phone' => $phone, 'type' => 'B2C', 'status' => 'new']);
        $m = Measurement::create(['lead_id' => $lead->id, 'customer_name' => $name, 'customer_phone' => $phone, 'type' => 'B2C', 'measured_by' => 'Ashu', 'status' => 'submitted', 'date' => '2026-11-05']);
        $m->apertures()->create(['aperture_ref_id' => 'A1', 'series' => '60', 'types' => [], 'width' => '1200', 'height' => '1500']);

        return $m;
    }

    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
    }

    private function adminToken(): string
    {
        $this->resetAuth();

        return $this->postJson('/api/admin/auth/login', ['email' => $this->admin->email, 'password' => self::PW])->assertOk()->json('token');
    }

    private function partnerToken(User $p): string
    {
        $this->resetAuth();

        return $this->postJson('/api/auth/verify-otp', ['phone' => $p->phone, 'otp' => $this->code])->assertOk()->json('token');
    }

    private function as(?string $token, string $method, string $uri, array $data = [])
    {
        $this->resetAuth();
        $this->flushHeaders();

        return $token === null ? $this->json($method, $uri, $data) : $this->withToken($token)->json($method, $uri, $data);
    }

    private function payload(Measurement $m, string $customer): array
    {
        return ['leadId' => $m->lead_id, 'customerName' => $customer, 'customerPhone' => $m->customer_phone, 'type' => 'B2C',
            'measuredBy' => 'Ashu', 'date' => '2026-11-06', 'status' => 'submitted',
            'apertures' => [['id' => 'A1', 'width' => '1300', 'height' => '1500']]];
    }

    /** [method, uri, body] for every measurement endpoint that takes an ID. */
    private function endpoints(int|string $id): array
    {
        return [
            ['GET', "/api/measurements/{$id}", []],
            ['GET', "/api/measurements/{$id}/export/excel", []],
            ['PUT', "/api/measurements/{$id}", $this->payload($this->other, 'Overwritten')],
        ];
    }

    // ── Authorized access is preserved ──────────────────────────────────────

    public function test_admin_can_view_export_and_update_any_measurement(): void
    {
        $t = $this->adminToken();
        foreach ([$this->own, $this->other] as $m) {
            $this->as($t, 'GET', "/api/measurements/{$m->id}")->assertOk()->assertJsonPath('success', true);
            // File contents are verified in MeasurementExcelExportTest.
            $this->as($t, 'GET', "/api/measurements/{$m->id}/export/excel")->assertOk();
        }
        $this->as($t, 'PUT', "/api/measurements/{$this->own->id}", $this->payload($this->own, 'Renamed Customer'))->assertOk()->assertJsonPath('success', true);
        $this->assertSame('Renamed Customer', $this->own->fresh()->customer_name);
        $this->assertSame('1300', (string) $this->own->apertures()->first()->width);

        $this->as($t, 'GET', '/api/admin/measurements')->assertOk();
    }

    public function test_internal_team_keeps_access(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'phone' => '9123466777', 'password' => self::PW, 'role' => 'internal_team', 'is_active' => 1]);
        $this->resetAuth();
        $t = JWTAuth::fromUser($staff);
        $this->as($t, 'GET', "/api/measurements/{$this->other->id}")->assertOk();
    }

    public function test_authorized_users_still_get_404_for_a_missing_measurement(): void
    {
        $t = $this->adminToken();
        $this->as($t, 'GET', '/api/measurements/'.self::MISSING)->assertNotFound();
        $this->as($t, 'PUT', '/api/measurements/'.self::MISSING, $this->payload($this->own, 'X'))->assertNotFound();
    }

    public function test_partner_can_still_see_their_own_referrals_but_not_others(): void
    {
        $res = $this->as($this->partnerToken($this->p1), 'GET', '/api/leads?per_page=500')->assertOk();
        $names = collect($res->json('data'))->pluck('client_name')->all();
        $this->assertSame(['Own Customer'], $names);
    }

    // ── Refusals: no access, no data, no existence disclosure ───────────────

    public function test_partner_cannot_access_any_measurement_including_one_on_their_own_referral(): void
    {
        $t = $this->partnerToken($this->p1);
        foreach ([$this->own, $this->other] as $m) {
            foreach ($this->endpoints($m->id) as [$method, $uri, $body]) {
                $res = $this->as($t, $method, $uri, $body)->assertForbidden();
                $this->assertStringNotContainsString($m->customer_name, $res->getContent());
                $this->assertStringNotContainsString($m->customer_phone, $res->getContent());
            }
        }
        $this->as($t, 'GET', '/api/admin/measurements')->assertForbidden();
        $this->as($t, 'POST', '/api/measurements', $this->payload($this->own, 'New'))->assertForbidden();
        $this->assertSame('Other Customer', $this->other->fresh()->customer_name, 'refused update must not change the record');
        $this->assertSame(2, Measurement::count());
    }

    public function test_partner_gets_identical_responses_for_existing_and_nonexistent_ids(): void
    {
        foreach ([$this->partnerToken($this->p1), JWTAuth::fromUser($this->p1)] as $t) {
            foreach (['existing' => $this->other->id, 'missing' => self::MISSING, 'non-numeric' => 'abc'] as $id) {
                foreach ($this->endpoints($id) as $i => [$method, $uri, $body]) {
                    $res = $this->as($t, $method, $uri, $body);
                    $seen[$i][] = [$res->status(), $res->getContent()];
                }
            }
            foreach ($seen as $i => $responses) {
                $this->assertSame(403, $responses[0][0], "endpoint #{$i}");
                $this->assertCount(1, array_unique(array_map('serialize', $responses)), "endpoint #{$i}: responses differ between existing and missing IDs");
            }
            unset($seen);
        }
    }

    public function test_dual_role_admin_in_a_partner_session_gets_identical_refusals(): void
    {
        $this->as($this->adminToken(), 'POST', '/api/admin/partner-application', ['name' => 'Boss', 'phone' => $this->admin->phone, 'company' => 'Boss Co'])->assertCreated();
        // a second admin approves the profile (self-approval is refused)
        $approver = User::create(['name' => 'Approver', 'email' => 'appr@example.test', 'phone' => '9123466998', 'password' => self::PW, 'role' => 'admin', 'is_active' => 1]);
        $this->resetAuth();
        $at = $this->postJson('/api/admin/auth/login', ['email' => $approver->email, 'password' => self::PW])->assertOk()->json('token');
        $this->as($at, 'PATCH', "/api/admin/partners/{$this->admin->id}/status", ['is_active' => true])->assertOk();

        $t = $this->partnerToken($this->admin);
        $a = $this->as($t, 'GET', "/api/measurements/{$this->other->id}");
        $b = $this->as($t, 'GET', '/api/measurements/'.self::MISSING);
        $a->assertForbidden();
        $this->assertSame([$a->status(), $a->getContent()], [$b->status(), $b->getContent()]);
    }

    public function test_unauthenticated_requests_get_identical_401s(): void
    {
        $a = $this->as(null, 'GET', "/api/measurements/{$this->other->id}")->assertUnauthorized();
        $b = $this->as(null, 'GET', '/api/measurements/'.self::MISSING)->assertUnauthorized();
        $this->assertSame($a->getContent(), $b->getContent());
    }

    public function test_other_role_guarded_routes_with_ids_do_not_disclose_existence_either(): void
    {
        $t = $this->partnerToken($this->p1);
        $lead = $this->other->lead_id;
        foreach ([['DELETE', '/api/leads/%s'], ['GET', '/api/admin/leads/%s'], ['GET', '/api/admin/leads/%s/history']] as [$method, $pattern]) {
            $a = $this->as($t, $method, sprintf($pattern, $lead));
            $b = $this->as($t, $method, sprintf($pattern, self::MISSING));
            $a->assertForbidden();
            $this->assertSame([$a->status(), $a->getContent()], [$b->status(), $b->getContent()], "{$method} {$pattern}");
        }
        $this->assertNotNull(Lead::find($lead), 'refused delete must not remove the lead');
    }
}
