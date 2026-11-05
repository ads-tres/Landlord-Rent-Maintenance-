<?php

use App\Http\Controllers\Web\ReceiptController;
use App\Http\Controllers\Web\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WaitlistController::class, 'show'])->name('landing');
Route::post('/waitlist', [WaitlistController::class, 'store'])
    ->middleware('throttle:waitlist')
    ->name('waitlist.store');

// Tenant receipt page linked from the SMS receipt.
Route::get('/r/{token}', [ReceiptController::class, 'show'])
    ->where('token', '[a-z0-9]{10}')
    ->middleware('throttle:60,1')
    ->name('receipts.show');
