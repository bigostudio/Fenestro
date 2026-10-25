<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private const PW = 'Correct-Horse-9!';

    private function user(string $role, string $email, string $phone, int $active = 1): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'phone' => $phone, 'password' => self::PW, 'role' => $role, 'is_active' => $active]);
    }

    private function loginToken(string $email, string $pw = self::PW): string
    {
        $this->resetAuth();

        return $this->postJson('/api/admin/auth/login', ['email' => $email, 'password' => $pw])->assertOk()->json('token');
    }

    /** Each HTTP request in production starts fresh; in tests the JWT services are reused, so reset them. */
    private function resetAuth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
    }

    private function newAdmin(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'New Admin', 'email' => 'new@example.test', 'phone' => '9000000099',
            'password' => 'Fresh-Admin-Pass-1!', 'password_confirmation' => 'Fresh-Admin-Pass-1!',
        ];
    }

    public function test_only_admins_can_manage_admin_accounts(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->postJson('/api/admin/users', $this->newAdmin())->assertStatus(401);

        $partner = $this->user('referral_partner', 'p@example.test', '9000000002');
        $partnerToken = auth('api')->login($partner);
        $this->withToken($partnerToken)->getJson('/api/admin/users')->assertStatus(403);
        $this->withToken($partnerToken)->postJson('/api/admin/users', $this->newAdmin())->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_admin_creates_an_admin_who_can_then_log_in(): void
    {
        $this->user('admin', 'a@example.test', '9000000001');
        $token = $this->loginToken('a@example.test');

        $this->withToken($token)->postJson('/api/admin/users', $this->newAdmin())
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $stored = User::where('email', 'new@example.test')->first();
        $this->assertNotSame('Fresh-Admin-Pass-1!', $stored->password);
        $this->loginToken('new@example.test', 'Fresh-Admin-Pass-1!');

        $this->resetAuth();
        $this->withToken($token)->getJson('/api/admin/users')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_create_validates_password_and_uniqueness(): void
    {
        $this->user('admin', 'a@example.test', '9000000001');
        $this->user('referral_partner', 'p@example.test', '9000000002');
        $token = $this->loginToken('a@example.test');

        $this->withToken($token)->postJson('/api/admin/users', $this->newAdmin(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        // A partner's phone/email can't be reused to turn that partner into an admin.
        $this->withToken($token)->postJson('/api/admin/users', $this->newAdmin(['phone' => '9000000002']))
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->withToken($token)->postJson('/api/admin/users', $this->newAdmin(['email' => 'p@example.test']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertSame('referral_partner', User::where('phone', '9000000002')->value('role'));
    }

    public function test_deactivating_an_admin_blocks_login_and_live_session(): void
    {
        $this->user('admin', 'a@example.test', '9000000001');
        $b = $this->user('admin', 'b@example.test', '9000000003');
        $aToken = $this->loginToken('a@example.test');
        $bToken = $this->loginToken('b@example.test');

        $this->resetAuth();
        $this->withToken($aToken)->patchJson("/api/admin/users/{$b->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->resetAuth();
        $this->withToken($bToken)->getJson('/api/admin/order-management/data')->assertStatus(403);
        $this->postJson('/api/admin/auth/login', ['email' => 'b@example.test', 'password' => self::PW])->assertStatus(401);

        // Reactivate restores access.
        $this->resetAuth();
        $this->withToken($aToken)->patchJson("/api/admin/users/{$b->id}", ['is_active' => true])->assertOk();
        $this->loginToken('b@example.test');
    }

    public function test_admin_cannot_change_own_access_or_touch_non_admins(): void
    {
        $a = $this->user('admin', 'a@example.test', '9000000001');
        $partner = $this->user('referral_partner', 'p@example.test', '9000000002');
        $token = $this->loginToken('a@example.test');

        $this->withToken($token)->patchJson("/api/admin/users/{$a->id}", ['is_active' => false])->assertStatus(422);
        $this->withToken($token)->patchJson("/api/admin/users/{$partner->id}", ['is_active' => false])->assertNotFound();
        $this->assertSame(1, (int) $a->fresh()->is_active);
    }

    public function test_admin_can_reset_another_admins_password_and_end_their_sessions(): void
    {
        $this->user('admin', 'a@example.test', '9000000001');
        $b = $this->user('admin', 'b@example.test', '9000000003');
        $aToken = $this->loginToken('a@example.test');
        $bToken = $this->loginToken('b@example.test');

        $this->resetAuth();
        $this->withToken($aToken)->patchJson("/api/admin/users/{$b->id}", ['password' => 'Reset-By-Admin-22!', 'password_confirmation' => 'Reset-By-Admin-22!'])->assertOk();

        $this->resetAuth();
        $this->withToken($bToken)->getJson('/api/admin/order-management/data')->assertStatus(401);
        $this->loginToken('b@example.test', 'Reset-By-Admin-22!');
    }

    public function test_profile_and_own_password_change(): void
    {
        $this->user('admin', 'a@example.test', '9000000001');
        $this->user('admin', 'taken@example.test', '9000000003');
        $token = $this->loginToken('a@example.test');

        $this->withToken($token)->putJson('/api/admin/profile', ['name' => 'Renamed', 'email' => 'taken@example.test'])->assertStatus(422);
        $this->withToken($token)->putJson('/api/admin/profile', ['name' => 'Renamed', 'email' => 'a2@example.test'])
            ->assertOk()->assertJsonPath('user.name', 'Renamed');

        $this->resetAuth();
        $this->withToken($token)->putJson('/api/admin/profile/password', ['current_password' => 'wrong', 'password' => 'Brand-New-Pass-77!', 'password_confirmation' => 'Brand-New-Pass-77!'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->resetAuth();
        $new = $this->withToken($token)->putJson('/api/admin/profile/password', ['current_password' => self::PW, 'password' => 'Brand-New-Pass-77!', 'password_confirmation' => 'Brand-New-Pass-77!'])
            ->assertOk()->json('token');

        // Old token is dead, the returned one works, and the new password logs in.
        $this->resetAuth();
        $this->withToken($token)->getJson('/api/admin/users')->assertStatus(401);
        $this->resetAuth();
        $this->withToken($new)->getJson('/api/admin/users')->assertOk();
        $this->loginToken('a2@example.test', 'Brand-New-Pass-77!');
    }

    // ── Mobile number of an admin (Settings → Admin users → Edit mobile) ──

    private function setPhone(string $token, int $id, $phone)
    {
        $this->resetAuth();

        return $this->withToken($token)->patchJson("/api/admin/users/$id/phone", ['phone' => $phone]);
    }

    public function test_admin_can_change_another_admins_and_their_own_mobile_number(): void
    {
        $me = $this->user('admin', 'a@example.test', '9000000001');
        $other = $this->user('admin', 'b@example.test', '9000000003');
        $token = $this->loginToken('a@example.test');
        $before = [$other->fresh()->email, $other->fresh()->password, $other->fresh()->is_active, $me->fresh()->email, $me->fresh()->password];

        $this->setPhone($token, $other->id, '9876543210')->assertOk()->assertJsonPath('data.phone', '9876543210')->assertJsonPath('data.is_self', false);
        $this->setPhone($token, $me->id, '9123456780')->assertOk()->assertJsonPath('data.phone', '9123456780')->assertJsonPath('data.is_self', true);
        // Keeping the same number is fine (it is "duplicate" only of itself).
        $this->setPhone($token, $me->id, '9123456780')->assertOk();

        // Saved in the database; nothing else changed; the current session still works.
        $this->assertSame(['9876543210', '9123456780'], [$other->fresh()->phone, $me->fresh()->phone]);
        $this->assertSame($before, [$other->fresh()->email, $other->fresh()->password, $other->fresh()->is_active, $me->fresh()->email, $me->fresh()->password]);
        $this->resetAuth();
        $this->withToken($token)->getJson('/api/admin/users')->assertOk();

        // Persists after logging out and in again.
        $this->resetAuth();
        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $again = $this->loginToken('a@example.test');
        $this->resetAuth();
        $list = collect($this->withToken($again)->getJson('/api/admin/users')->assertOk()->json('data'))->keyBy('email');
        $this->assertSame(['9123456780', '9876543210'], [$list['a@example.test']['phone'], $list['b@example.test']['phone']]);
    }

    public function test_mobile_number_must_be_10_digits_and_unique(): void
    {
        $me = $this->user('admin', 'a@example.test', '9000000001');
        $this->user('admin', 'b@example.test', '9000000003');
        $this->user('referral_partner', 'p@example.test', '9000000004');
        $token = $this->loginToken('a@example.test');

        foreach (['90000000', '900000000012', '90000-0001', '+919000000001', 'abcdefghij', '', null] as $bad) {
            $this->setPhone($token, $me->id, $bad)->assertStatus(422)->assertJsonValidationErrors('phone');
        }
        $this->setPhone($token, $me->id, '9000000003')->assertStatus(422)->assertJsonValidationErrors('phone'); // another admin's number
        $this->setPhone($token, $me->id, '9000000004')->assertStatus(422)->assertJsonValidationErrors('phone'); // a partner's number
        $this->assertSame('9000000001', $me->fresh()->phone);
    }

    public function test_only_admins_can_change_admin_mobile_numbers_and_only_admin_accounts(): void
    {
        $admin = $this->user('admin', 'a@example.test', '9000000001');
        $partner = $this->user('referral_partner', 'p@example.test', '9000000004');

        $this->patchJson("/api/admin/users/{$admin->id}/phone", ['phone' => '9555555555'])->assertStatus(401);
        $partnerToken = auth('api')->login($partner);
        $this->resetAuth();
        $this->withToken($partnerToken)->patchJson("/api/admin/users/{$admin->id}/phone", ['phone' => '9555555555'])->assertStatus(403);

        // This screen manages admins only: a partner's number cannot be changed through it.
        $token = $this->loginToken('a@example.test');
        $this->setPhone($token, $partner->id, '9555555555')->assertNotFound();
        $this->assertSame(['9000000001', '9000000004'], [$admin->fresh()->phone, $partner->fresh()->phone]);
    }
}
