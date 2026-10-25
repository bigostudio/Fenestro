<?php

use App\Http\Controllers\WhatsappWebhookController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\InventoryStockController;
use App\Http\Controllers\Admin\ReceivablesController;
use App\Http\Controllers\Admin\OrderManagementController;
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
    Route::post('/register',   [AuthController::class, 'register'])->middleware('throttle:partner-register');
    Route::post('/send-otp',   [AuthController::class, 'sendOtp'])->middleware('throttle:partner-send-otp');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:partner-verify-otp');
});

/*
|--------------------------------------------------------------------------
| Admin Auth Routes (Public) — Email/Password
|--------------------------------------------------------------------------
*/
// No public admin registration or self-service reset: admins are provisioned
// with `php artisan admin:create` / `php artisan admin:reset-password`.
Route::prefix('admin/auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login');
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

    // Admin accounts (Settings → Admin users) and own account (Settings → Account)
    Route::get('/users',            [AdminUserController::class, 'index']);
    Route::post('/users',           [AdminUserController::class, 'store']);
    Route::patch('/users/{user}',   [AdminUserController::class, 'update']);
    Route::patch('/users/{user}/phone', [AdminUserController::class, 'updatePhone']); // any admin's mobile number, incl. your own
    Route::put('/profile',          [AdminUserController::class, 'updateProfile']);
    Route::put('/profile/password', [AdminUserController::class, 'updatePassword'])->middleware('throttle:6,1');
});

/*
|--------------------------------------------------------------------------
| Order Management (Protected — admin role required)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'admin'])->prefix('admin/order-management')->group(function () {
    Route::get('/data', [OrderManagementController::class, 'index']);

    Route::post('/orders',          [OrderManagementController::class, 'storeOrder']);
    Route::put('/orders/{code}',    [OrderManagementController::class, 'updateOrder']);

    Route::post('/inventory',       [OrderManagementController::class, 'storeInventory']);
    Route::put('/inventory/{code}', [OrderManagementController::class, 'updateInventory']);

    Route::post('/orders/{code}/installation-date', [OrderManagementController::class, 'setInstallationDate']);
    Route::get('/orders/{code}/abort-summary',      [OrderManagementController::class, 'abortSummary']);
    Route::post('/orders/{code}/abort',             [OrderManagementController::class, 'abortProject']);
    // Delete Project: only projects with no payment/refund/statement/inventory history (confirmed by typing the ID).
    Route::get('/orders/{code}/delete-check',       [OrderManagementController::class, 'deleteCheck']);
    Route::delete('/orders/{code}',                 [OrderManagementController::class, 'destroyOrder']);

    // Archive: completed (PROJECT COMPLETED) projects, read-only
    Route::get('/archive',                  [ArchiveController::class, 'index']);

    // Receivables (read-only) and payment statements
    Route::get('/receivables',              [ReceivablesController::class, 'index']);
    Route::post('/receivables/statements',  [ReceivablesController::class, 'statements']);
    Route::post('/receivables/statements/{code}/whatsapp', [ReceivablesController::class, 'sendStatement']);
    Route::get('/whatsapp/status',          [ReceivablesController::class, 'whatsappStatus']);
    Route::post('/receivables/statements/deliveries', [ReceivablesController::class, 'deliveries']);

    // Inventory → Add Stock / Bulk Data
    Route::post('/inventory-stock/add',     [InventoryStockController::class, 'add']);
    Route::post('/inventory-stock/bulk',    [InventoryStockController::class, 'bulk']);

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

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API webhook (delivery statuses for Send Statement)
|--------------------------------------------------------------------------
| Called by Meta, not by the admin site: GET verifies the subscription with
| WHATSAPP_VERIFY_TOKEN; POST is accepted only with a valid
| X-Hub-Signature-256 made with WHATSAPP_APP_SECRET.
*/
Route::get('/whatsapp/webhook', [WhatsappWebhookController::class, 'verify'])->middleware('throttle:30,1');
Route::post('/whatsapp/webhook', [WhatsappWebhookController::class, 'receive'])->middleware('throttle:600,1');
