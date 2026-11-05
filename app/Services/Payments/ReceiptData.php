<?php

namespace App\Services\Payments;

use App\Models\Charge;
use App\Models\Payment;
use App\Support\DateDisplay;

/** Everything a receipt shows, shared by the tenant web page, the PDF and the SMS. */
class ReceiptData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Payment $payment, string $locale): array
    {
        $payment->loadMissing(['landlord', 'tenant', 'lease.unit.property', 'allocations.charge']);
        $calendar = $payment->landlord->calendar_pref ?? DateDisplay::GREGORIAN;
        $lease = $payment->lease;

        $outstanding = $lease
            ? (int) $lease->charges()
                ->where('status', '!=', Charge::STATUS_PAID)
                ->withSum('allocations as paid', 'amount')
                ->get()
                ->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid)
            : 0;

        return [
            'number' => $payment->receiptNumber(),
            'locale' => $locale,
            'landlord_name' => $payment->landlord->name ?: $payment->landlord->phone,
            'landlord_phone' => $payment->landlord->phone,
            'tenant_name' => $payment->tenant->full_name,
            'unit' => $lease?->unit?->label,
            'property' => $lease?->unit?->property?->name,
            'paid_at' => self::date($payment->paid_at, $locale, $calendar),
            'amount' => self::birr($payment->amount, $locale),
            'method' => __('payments.methods.'.$payment->method, [], $locale),
            'reference' => $payment->reference,
            'lines' => $payment->allocations
                ->sortBy(fn ($a) => $a->charge->period)
                ->map(fn ($a) => [
                    'label' => self::period($a->charge->period, $locale, $calendar),
                    'amount' => self::birr($a->amount, $locale),
                    'full' => $a->charge->status === Charge::STATUS_PAID,
                ])->values()->all(),
            'outstanding' => $outstanding > 0 ? self::birr($outstanding, $locale) : null,
            'url' => $payment->receiptUrl(),
        ];
    }

    public static function birr(int $santim, string $locale = 'en'): string
    {
        $prefix = $locale === 'am' ? 'ብር' : 'Br';
        $decimals = $santim % 100 === 0 ? 0 : 2;

        return $prefix.' '.number_format($santim / 100, $decimals);
    }

    public static function period(string $period, string $locale, string $calendar = DateDisplay::GREGORIAN): string
    {
        return DateDisplay::period($period, $locale, $calendar);
    }

    public static function date($date, string $locale, string $calendar = DateDisplay::GREGORIAN): string
    {
        return DateDisplay::date($date, $locale, $calendar);
    }
}
