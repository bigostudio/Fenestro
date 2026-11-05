<?php

/*
|--------------------------------------------------------------------------
| Common partner access code
|--------------------------------------------------------------------------
| ONE six-digit code shared by ALL approved referral partners (including administrators approved
| as partners), set by an administrator and shared privately. Partners sign in at Partner Login
| with their own registered number plus this code while it is active; no WhatsApp code is sent.
|
| It is a shared, reusable secret — not a one-time password: anyone who knows it and an approved
| partner's number can sign in as that partner. Keep it off unless needed and change it often.
| Switching it off (or revoking/changing the code) ends every session opened with it.
| No code is stored here — only switches and limits.
*/

return [
    'enabled' => (bool) env('PARTNER_TEMP_ACCESS_ENABLED', false),

    // Wrong codes for ONE number before that number is locked out of common-code login for a while.
    'max_failures_per_phone' => (int) env('PARTNER_COMMON_CODE_MAX_FAILURES_PER_PHONE', 5),
    'lockout_minutes'        => (int) env('PARTNER_COMMON_CODE_LOCKOUT_MINUTES', 15),

    // Wrong codes across ALL numbers within an hour before the code is revoked automatically
    // (a guessing attack spread over many numbers). An administrator then sets a new code.
    'max_failures_per_hour'  => (int) env('PARTNER_COMMON_CODE_MAX_FAILURES_PER_HOUR', 100),
];
