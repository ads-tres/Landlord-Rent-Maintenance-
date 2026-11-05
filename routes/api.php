<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargeController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LeaseController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\RepairLinkController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RulesController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantPrivacyController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UnitListController;
use App\Http\Middleware\RecordLandlordActivity;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
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
    });
});
