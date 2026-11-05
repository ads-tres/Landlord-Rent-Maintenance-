<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PropertyController;
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
    });
});
