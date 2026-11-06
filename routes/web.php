<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Web\ListingPageController;
use App\Http\Controllers\Web\MoveOutStatementController;
use App\Http\Controllers\Web\PayPageController;
use App\Http\Controllers\Web\ReceiptController;
use App\Http\Controllers\Web\RepairPageController;
use App\Http\Controllers\Web\TenantPortalController;
use App\Http\Controllers\Web\WaitlistController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', [WaitlistController::class, 'show'])->name('landing');
Route::view('/privacy', 'privacy')->name('privacy');
Route::post('/waitlist', [WaitlistController::class, 'store'])
    ->middleware('throttle:waitlist')
    ->name('waitlist.store');

// Tenant receipt page linked from the SMS receipt.
Route::get('/r/{token}', [ReceiptController::class, 'show'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('receipts.show');

// The tenant's own page: balance, payments, repairs, announcements.
Route::get('/t/{token}', [TenantPortalController::class, 'show'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('portal.show');

// Tenant deposit statement linked from the move-out SMS.
Route::get('/mo/{token}', [MoveOutStatementController::class, 'show'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('move-outs.statement');

// Tenant repair page linked from SMS: report a problem with a photo, see recent statuses.
Route::get('/fix/{token}', [RepairPageController::class, 'show'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('repairs.public.show');
Route::post('/fix/{token}', [RepairPageController::class, 'store'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:10,60')
    ->name('repairs.public.store');

// Pilot admin panel (SMS-code login, admin role only).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AdminAuthController::class, 'show'])->name('login');
    Route::post('login', [AdminAuthController::class, 'sendCode'])->middleware('throttle:otp')->name('login.send');
    Route::get('login/code', [AdminAuthController::class, 'showCode'])->name('login.code');
    Route::post('login/code', [AdminAuthController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');

    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('waitlist', [AdminController::class, 'waitlist'])->name('waitlist');
        Route::get('waitlist/export', [AdminController::class, 'exportWaitlist'])->name('waitlist.export');
        Route::patch('waitlist/{signup}', [AdminController::class, 'updateSignup'])->name('waitlist.update');
        Route::post('landlords', [AdminController::class, 'storeLandlord'])->name('landlords.store');
        Route::get('landlords/{landlord}', [AdminController::class, 'showLandlord'])->name('landlords.show');
        Route::post('landlords/{landlord}/import', [AdminController::class, 'previewImport'])->name('landlords.import.preview');
        Route::post('landlords/{landlord}/import/confirm', [AdminController::class, 'confirmImport'])->name('landlords.import.confirm');
        Route::get('import-template.csv', [AdminController::class, 'template'])->name('import.template');
        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::put('settings', [AdminController::class, 'updateSettings'])->name('settings.update');
    });
});

// Public vacancy listings, shared by link (no public search).
Route::prefix('l/{slug}')->where(['slug' => '[a-z0-9]{8}'])->name('listings.public.')->group(function () {
    Route::get('/', [ListingPageController::class, 'show'])->middleware('throttle:120,1')->name('show');
    Route::post('apply', [ListingPageController::class, 'apply'])->middleware('throttle:5,60')->name('apply');
    Route::get('verify', [ListingPageController::class, 'showVerify'])->name('verify');
    Route::post('verify', [ListingPageController::class, 'verify'])->middleware('throttle:10,10')->name('verify.submit');
    Route::post('report', [ListingPageController::class, 'report'])->middleware('throttle:3,60')->name('report');
});

// Tenant online rent page (Chapa checkout). Works only when the landlord has online rent on.
Route::prefix('pay/{token}')->where(['token' => '[a-z0-9]{10}'])->name('pay.')->group(function () {
    Route::get('/', [PayPageController::class, 'show'])->middleware('throttle:60,1')->name('show');
    Route::post('/', [PayPageController::class, 'start'])->middleware('throttle:10,10')->name('start');
    Route::get('done', [PayPageController::class, 'done'])->middleware('throttle:30,1')->name('done');
});
