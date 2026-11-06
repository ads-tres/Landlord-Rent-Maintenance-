<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\SmsMessage;
use App\Services\Portal\PortalLink;
use App\Services\Sms\SmsNotifier;
use App\Support\DateDisplay;

/** Sends the tenant a receipt link (Telegram if linked, otherwise SMS). */
class ReceiptNotifier
{
    public function __construct(private readonly SmsNotifier $sms) {}

    public function send(Payment $payment, string $locale): SmsMessage
    {
        $payment->loadMissing(['landlord', 'tenant', 'lease.unit', 'allocations.charge']);
        $periods = $payment->allocations->map(fn ($a) => $a->charge->period)->sort()->values();
        $calendar = $payment->landlord->calendar_pref ?? DateDisplay::GREGORIAN;

        $body = __('sms.receipt', [
            'amount' => ReceiptData::birr($payment->amount, $locale),
            'unit' => $payment->lease?->unit?->label ?? '',
            'months' => DateDisplay::periodRange($periods->first(), $periods->last(), $locale, $calendar),
            'url' => $payment->receiptUrl(),
            'app' => config('app.name'),
        ], $locale);
        if ($payment->lease) {
            $body = app(PortalLink::class)->append($payment->lease, $body, $locale);
        }

        return $this->sms->toTenant($payment->tenant, SmsMessage::KIND_RECEIPT, $body, $payment);
    }
}
