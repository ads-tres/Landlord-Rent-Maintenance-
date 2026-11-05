<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChargeController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\LeaseController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\RulesController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UnitController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/otp', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
    Route::post('auth/verify', [AuthController::class, 'verify'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/me', [AuthController::class, 'updateMe']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::apiResource('properties', PropertyController::class);
        Route::apiResource('properties.units', UnitController::class)->shallow();
        Route::apiResource('tenants', TenantController::class);

        Route::apiResource('leases', LeaseController::class)->except('destroy');
        Route::post('leases/{lease}/end', [LeaseController::class, 'end']);
        Route::post('leases/{lease}/rent-change', [LeaseController::class, 'rentChange']);

        Route::get('charges', [ChargeController::class, 'index']);
        Route::get('rules', RulesController::class);

        Route::apiResource('payments', PaymentController::class)->except('update');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);

        Route::get('dashboard', DashboardController::class);
    });
});
