<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Partner login codes (6 digits, WhatsApp).
 *
 * At rest only an HMAC-SHA256 of the code is kept (keyed with APP_KEY and bound to the user id),
 * so a database copy reveals no usable codes and they cannot be brute-forced offline without the
 * key. Each code expires after TTL_MINUTES, is single-use, and is invalidated after
 * MAX_ATTEMPTS wrong guesses.
 *
 * Verification and the attempt counter are single conditional UPDATE statements, so concurrent
 * requests cannot both use one code, and cannot get more than MAX_ATTEMPTS guesses at it.
 */
final class PartnerOtp
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const LOCKED = 'locked';

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function hash(int $userId, string $code): string
    {
        return hash_hmac('sha256', "partner-login-code|{$userId}|{$code}", (string) config('app.key'));
    }

    /** Store a new code (replacing any previous one) with a fresh expiry and attempt counter. */
    public static function issue(User $user, string $code): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'otp_hash'       => self::hash($user->id, $code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'otp_attempts'   => 0,
            'otp'            => null, // legacy plaintext column: never used again
        ]);
    }

    public static function clear(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'otp' => null]);
    }

    /** One verification attempt: self::OK (code consumed), self::INVALID, or self::LOCKED (this guess used the last attempt). */
    public static function verify(User $user, string $code): string
    {
        // Correct, unexpired, not locked: consume it. Only one concurrent request can match this row.
        $consumed = DB::table('users')
            ->where('id', $user->id)
            ->where('otp_hash', self::hash($user->id, $code))
            ->where('otp_attempts', '<', self::MAX_ATTEMPTS)
            ->where('otp_expires_at', '>=', now())
            ->update(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0, 'otp' => null]);
        if ($consumed === 1) {
            return self::OK;
        }

        // Wrong (or expired) guess against a live code: count it atomically ...
        DB::table('users')->where('id', $user->id)->whereNotNull('otp_hash')
            ->where('otp_attempts', '<', self::MAX_ATTEMPTS)
            ->increment('otp_attempts');

        // ... and invalidate the code once the limit is reached (exactly one request sees this).
        $locked = DB::table('users')->where('id', $user->id)->whereNotNull('otp_hash')
            ->where('otp_attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['otp_hash' => null, 'otp_expires_at' => null]);

        return $locked === 1 ? self::LOCKED : self::INVALID;
    }
}
