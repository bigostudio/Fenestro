<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin login brute-force protection: 5 attempts per minute per
        // email+IP, and 20 per minute per IP across all emails.
        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));
            $tooMany = fn () => response()->json(
                ['message' => 'Too many login attempts. Please wait a minute and try again.'],
                429
            );

            return [
                Limit::perMinute(5)->by('admin-login:'.$email.'|'.$request->ip())->response($tooMany),
                Limit::perMinute(20)->by('admin-login-ip:'.$request->ip())->response($tooMany),
            ];
        });

        // Partner OTP login: limits per phone number and per IP, so codes cannot be guessed
        // by trying many in a row (a code also expires after 10 minutes).
        $otpTooMany = fn () => response()->json(['message' => 'Too many attempts. Please wait a minute and try again.'], 429);
        RateLimiter::for('partner-send-otp', fn (Request $request) => [
            Limit::perMinute(5)->by('send-otp:'.$request->input('phone'))->response($otpTooMany),
            Limit::perMinute(30)->by('send-otp-ip:'.$request->ip())->response($otpTooMany),
        ]);
        RateLimiter::for('partner-verify-otp', fn (Request $request) => [
            Limit::perMinute(10)->by('verify-otp:'.$request->input('phone'))->response($otpTooMany),
            Limit::perMinute(30)->by('verify-otp-ip:'.$request->ip())->response($otpTooMany),
        ]);
        RateLimiter::for('partner-register', fn (Request $request) => Limit::perMinute(10)->by('register-ip:'.$request->ip())->response($otpTooMany));
    }
}
