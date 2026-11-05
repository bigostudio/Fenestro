<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * ONE common six-digit partner access code, shared by all approved referral partners (ordinary
 * partners and administrators approved as partners). Set by an administrator, shared privately;
 * valid until changed or revoked.
 *
 * This is a shared, reusable secret — NOT a one-time password. Controls around it:
 *  - off unless config('partner_access.enabled'); switching it off disables it at once;
 *  - only APPROVED partners can use it (checked at every login and every request of the session);
 *    it only ever opens a PARTNER session (ctx = partner) — never an admin session;
 *  - stored only as a salted HMAC (APP_KEY); the plain code exists once, in the response that
 *    generated it (or in the admin's own input when set by hand) — never in logs or the database;
 *  - at most one active code; setting a new one revokes the old, and changing / revoking it ends
 *    every session opened with the old code (the session remembers the code id: claim 'pcc');
 *  - wrong codes: per number, MAX_FAILURES_PER_PHONE locks that number out for LOCKOUT_MINUTES; across
 *    all numbers, MAX_FAILURES_PER_HOUR revokes the code (a guessing attack spread over many numbers).
 */
final class PartnerCommonCode
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const LOCKED = 'locked';          // this number is locked out for a while

    public const REVOKED = 'revoked';        // this wrong guess tripped the global limit: code revoked

    public const CLAIM = 'pcc';

    public static function enabled(): bool
    {
        return (bool) config('partner_access.enabled');
    }

    public static function hash(string $salt, string $code): string
    {
        return hash_hmac('sha256', "partner-common-code|{$salt}|{$code}", (string) config('app.key'));
    }

    /** The active code's row (never the code; the hash stays server-side), or null. Null while the feature is off. */
    public static function active(): ?object
    {
        if (! self::enabled()) {
            return null;
        }

        return DB::table('partner_common_codes')->whereNull('revoked_at')->orderByDesc('id')->first();
    }

    /** True while code $id is the active code (used to end sessions opened with an older / revoked code). */
    public static function isLive(int $id): bool
    {
        return self::active()?->id === $id;
    }

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /** Codes too easy to guess are refused when set by hand (and never generated). */
    public static function weak(string $code): bool
    {
        if (count(array_unique(str_split($code))) <= 2) {
            return true; // 000000, 111222, 121212 …
        }
        $d = array_map('intval', str_split($code));
        $steps = array_unique(array_map(fn ($i) => ($d[$i + 1] - $d[$i] + 10) % 10, range(0, 4)));

        return count($steps) === 1 && in_array($steps[0], [1, 9], true) // 123456, 654321, 890123 …
            || in_array($code, ['123123', '112233', '102030', '147258', '159753'], true);
    }

    /**
     * Make $code (or a new random one) the common code, replacing any active one — which ends every
     * session opened with it. Returns the code in clear ONCE, for the administrator to share.
     *
     * @return array{id: int, code: string, created_at: string}
     */
    public static function set(?string $code, string $by): array
    {
        if (! self::enabled()) {
            throw new InvalidArgumentException('The common partner access code is switched off on this server (PARTNER_TEMP_ACCESS_ENABLED).');
        }
        if ($code !== null && ! preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('The code must be exactly 6 digits.');
        }
        if ($code !== null && self::weak($code)) {
            throw new InvalidArgumentException('That code is too easy to guess. Choose another, or generate one.');
        }
        do {
            $plain = $code ?? self::generate();
        } while ($code === null && self::weak($plain));

        return DB::transaction(function () use ($plain, $by) {
            self::revokeActive('replaced', $by);
            $salt = bin2hex(random_bytes(16));
            $id = DB::table('partner_common_codes')->insertGetId([
                'code_salt'  => $salt,
                'code_hash'  => self::hash($salt, $plain),
                'created_by' => mb_substr($by, 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            RateLimiter::clear(self::globalKey()); // a fresh code starts with a clean slate

            return ['id' => $id, 'code' => $plain, 'created_at' => now()->utc()->toIso8601ZuluString()];
        });
    }

    /** Revoke the active code (ending its sessions). Returns how many were revoked (0 = none was active). */
    public static function revokeActive(string $reason, ?string $by = null): int
    {
        return DB::table('partner_common_codes')->whereNull('revoked_at')->update([
            'revoked_at' => now(), 'revoked_reason' => $reason, 'revoked_by' => $by ? mb_substr($by, 0, 255) : null, 'updated_at' => now(),
        ]);
    }

    /**
     * One login attempt with the common code for an APPROVED partner (the caller checks approval
     * first). Never opens anything for a pending / rejected / non-partner account.
     */
    public static function verify(object $active, User $user, string $code): string
    {
        $phoneKey = 'partner-common-code-fail:'.$user->id;
        if (RateLimiter::tooManyAttempts($phoneKey, max(1, (int) config('partner_access.max_failures_per_phone')))) {
            return self::LOCKED;
        }
        if (hash_equals($active->code_hash, self::hash($active->code_salt, $code))) {
            RateLimiter::clear($phoneKey);

            return self::OK;
        }

        RateLimiter::hit($phoneKey, max(1, (int) config('partner_access.lockout_minutes')) * 60);
        RateLimiter::hit(self::globalKey(), 3600);
        if (RateLimiter::attempts(self::globalKey()) >= max(1, (int) config('partner_access.max_failures_per_hour'))) {
            $revoked = DB::table('partner_common_codes')->where('id', $active->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'too_many_attempts', 'updated_at' => now()]);
            if ($revoked === 1) {
                return self::REVOKED;
            }
        }

        return RateLimiter::tooManyAttempts($phoneKey, max(1, (int) config('partner_access.max_failures_per_phone'))) ? self::LOCKED : self::INVALID;
    }

    /** Minutes until this account may try the common code again (0 = not locked). */
    public static function lockedMinutes(User $user): int
    {
        return (int) ceil(RateLimiter::availableIn('partner-common-code-fail:'.$user->id) / 60);
    }

    private static function globalKey(): string
    {
        return 'partner-common-code-fail-global';
    }

    /** Status for administrators — never the code, its hash or salt. */
    public static function status(): array
    {
        $active = self::active();
        $last = DB::table('partner_common_codes')->orderByDesc('id')->first(['id', 'created_by', 'created_at', 'revoked_at', 'revoked_reason', 'revoked_by']);
        $iso = fn (?string $v) => $v === null ? null : Carbon::parse($v, (string) config('app.timezone'))->utc()->toIso8601ZuluString();

        return [
            'enabled' => self::enabled(),
            'active'  => (bool) $active,
            'current' => $active ? ['created_by' => $active->created_by, 'created_at' => $iso($active->created_at)] : null,
            'last_revoked' => $last && $last->revoked_at ? [
                'revoked_at' => $iso($last->revoked_at), 'reason' => $last->revoked_reason, 'revoked_by' => $last->revoked_by,
            ] : null,
            'failed_attempts_last_hour' => RateLimiter::attempts(self::globalKey()),
            'max_failures_per_hour'     => (int) config('partner_access.max_failures_per_hour'),
        ];
    }
}
