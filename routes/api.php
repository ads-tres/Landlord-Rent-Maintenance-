<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargeController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LeaseController;
use App\Http\Controllers\Api\V1\ListingController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MeterController;
use App\Http\Controllers\Api\V1\OnlineRentController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\RepairLinkController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RulesController;
use App\Http\Controllers\Api\V1\SignedAttachmentController;
use App\Http\Controllers\Api\V1\TelegramLinkController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantPrivacyController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UnitListController;
use App\Http\Controllers\Api\V1\UtilityBillController;
use App\Http\Controllers\ChapaWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\RecordLandlordActivity;
use Illuminate\Support\Facades\Route;

// Telegram bot updates (checked with the webhook secret header).
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:120,1')->name('telegram.webhook');

// Chapa payment webhooks (signature checked, then verified with Chapa).
Route::post('chapa/webhook', ChapaWebhookController::class)->middleware('throttle:120,1')->name('chapa.webhook');

Route::prefix('v1')->group(function () {
    Route::get('attachments/{attachment}/signed', SignedAttachmentController::class)
        ->middleware('signed')
        ->name('attachments.signed');

    Route::post('auth/otp', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', RecordLandlordActivity::class])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/me', [AuthController::class, 'updateMe']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::apiResource('properties', PropertyController::class);
        Route::apiResource('properties.units', UnitController::class)->shallow();
        Route::get('units', UnitListController::class);
        Route::apiResource('tenants', TenantController::class);
        Route::get('tenants/{tenant}/export', [TenantPrivacyController::class, 'export']);
        Route::post('tenants/{tenant}/anonymize', [TenantPrivacyController::class, 'anonymize']);

        Route::apiResource('leases', LeaseController::class)->except('destroy');
        Route::post('leases/{lease}/end', [LeaseController::class, 'end']);
        Route::post('leases/{lease}/rent-change', [LeaseController::class, 'rentChange']);
        Route::post('leases/{lease}/activate', [LeaseController::class, 'activate']);

        Route::get('listings', [ListingController::class, 'index']);
        Route::post('units/{unit}/listing', [ListingController::class, 'store']);
        Route::get('listings/{listing}', [ListingController::class, 'show']);
        Route::patch('listings/{listing}', [ListingController::class, 'update']);
        Route::post('listings/{listing}/photos', [ListingController::class, 'addPhoto']);
        Route::get('listings/{listing}/applications', [ListingController::class, 'applications']);
        Route::patch('applications/{application}', [ListingController::class, 'updateApplication']);

        Route::get('charges', [ChargeController::class, 'index']);
        Route::get('rules', RulesController::class);

        Route::apiResource('payments', PaymentController::class)->except('update');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);

        Route::get('dashboard', DashboardController::class);
        Route::get('reports/quarterly', [ReportController::class, 'quarterly']);

        Route::apiResource('maintenance', MaintenanceController::class)->except('destroy');
        Route::post('maintenance/{maintenance}/photos', [MaintenanceController::class, 'addPhoto']);
        Route::get('attachments/{attachment}', [AttachmentController::class, 'show']);
        Route::post('leases/{lease}/repair-link/send', [RepairLinkController::class, 'send']);
        Route::post('leases/{lease}/repair-link/reset', [RepairLinkController::class, 'reset']);

        Route::apiResource('properties.meters', MeterController::class)->shallow();
        Route::get('meters/{meter}/bills', [UtilityBillController::class, 'index']);
        Route::post('meters/{meter}/bills', [UtilityBillController::class, 'store']);
        Route::delete('bills/{bill}', [UtilityBillController::class, 'destroy']);

        Route::get('properties/{property}/announcements', [AnnouncementController::class, 'index']);
        Route::post('properties/{property}/announcements/preview', [AnnouncementController::class, 'preview']);
        Route::post('properties/{property}/announcements', [AnnouncementController::class, 'store'])->middleware('throttle:10,60');

        Route::get('assets', [AssetController::class, 'all']);
        Route::get('properties/{property}/assets', [AssetController::class, 'index']);
        Route::post('properties/{property}/assets', [AssetController::class, 'store']);
        Route::patch('assets/{asset}', [AssetController::class, 'update']);
        Route::delete('assets/{asset}', [AssetController::class, 'destroy']);
        Route::post('assets/{asset}/services', [AssetController::class, 'logService']);

        Route::get('chapa/banks', [OnlineRentController::class, 'banks']);
        Route::get('payout-account', [OnlineRentController::class, 'show']);
        Route::put('payout-account', [OnlineRentController::class, 'update']);
        Route::post('leases/{lease}/pay-link/send', [OnlineRentController::class, 'sendPayLink']);

        Route::post('telegram/link', [TelegramLinkController::class, 'store']);
        Route::delete('telegram/link', [TelegramLinkController::class, 'destroy']);
    });
});
