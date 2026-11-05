<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\ReceiptData;
use Illuminate\View\View;

/** Public receipt page linked from the tenant's SMS: /r/{token}. No login. */
class ReceiptController extends Controller
{
    public function show(string $token): View
    {
        $payment = Payment::query()->withoutGlobalScopes()->where('receipt_token', $token)->firstOrFail();

        return view('receipts.show', ['r' => ReceiptData::for($payment, app()->getLocale())]);
    }
}
