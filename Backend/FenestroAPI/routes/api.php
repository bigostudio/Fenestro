<?php

use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MeasurementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth Routes (Public) — Referral Partners (SMS/OTP)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/register',   [AuthController::class, 'register']);
    Route::post('/send-otp',   [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
});

/*
|--------------------------------------------------------------------------
| Admin Auth Routes (Public) — Email/Password
|--------------------------------------------------------------------------
*/
Route::prefix('admin/auth')->group(function () {
    Route::post('/register',        [AdminAuthController::class, 'register']);
    Route::post('/login',           [AdminAuthController::class, 'login']);
    Route::post('/forgot-password', [AdminAuthController::class, 'forgotPassword']);
    Route::post('/reset-password',  [AdminAuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| Auth Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:api')->prefix('auth')->group(function () {
    Route::post('/logout',  [AuthController::class, 'logout']);
    Route::get('/me',       [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Protected — admin role required)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'admin'])->prefix('admin')->group(function () {
    Route::get('/partners/approved',         [PartnerController::class, 'approved']);
    Route::get('/partners/pending',          [PartnerController::class, 'pending']);
    Route::patch('/partners/{user}/status', [PartnerController::class, 'updateStatus']);

    Route::get('/measurements',              [MeasurementController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| Lead Routes
|--------------------------------------------------------------------------
*/

// Approved partner: submit a lead
Route::middleware(['auth:api', 'approved_partner'])->group(function () {
    Route::post('/leads', [LeadController::class, 'store']);
});

// Admin / internal team: view all leads; approved partner: view own leads
Route::middleware(['auth:api'])->group(function () {
    Route::get('/leads', [LeadController::class, 'index']);
});

// Admin / internal team: delete a lead (cascades to its measurements/apertures)
Route::middleware(['auth:api', 'internal'])->group(function () {
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Measurement Routes (Protected)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'internal'])->group(function () {
    Route::post('/measurements',                              [MeasurementController::class, 'store']);
    Route::put('/measurements/{measurement}',                 [MeasurementController::class, 'update']);
    Route::get('/measurements/{measurement}',                 [MeasurementController::class, 'show']);
    Route::get('/measurements/{measurement}/export/excel',    [MeasurementController::class, 'exportExcel']);
});
