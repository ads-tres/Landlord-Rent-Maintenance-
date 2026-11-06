<?php

namespace App\Services\MoveOut;

use App\Models\MoveOut;
use App\Services\Payments\ReceiptData;
use App\Support\DateDisplay;

/** The deposit settlement as shown to the tenant (web page) and the landlord (PDF). */
class MoveOutStatement
{
    public static function for(MoveOut $moveOut, string $locale): array
    {
        $moveOut->loadMissing(['lease.unit.property', 'lease.tenant', 'landlord', 'deductions']);
        $lease = $moveOut->lease;
        $calendar = $moveOut->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $t = app(MoveOutService::class)->totals($moveOut);
        $birr = fn (int $s) => ReceiptData::birr($s, $locale);

        return [
            'locale' => $locale,
            'settled' => $moveOut->isSettled(),
            'landlord' => $moveOut->landlord?->name ?: $moveOut->landlord?->phone,
            'tenant' => $lease->tenant->full_name,
            'place' => $lease->unit->label.' · '.$lease->unit->property->name,
            'lease_dates' => DateDisplay::date($lease->start_date, $locale, $calendar).' – '.DateDisplay::date($lease->end_date, $locale, $calendar),
            'moved_out_on' => DateDisplay::date($moveOut->moved_out_on, $locale, $calendar),
            'settled_on' => $moveOut->settled_at ? DateDisplay::date($moveOut->settled_at, $locale, $calendar) : null,
            'keys_returned' => $moveOut->keys_returned,
            'condition_notes' => $moveOut->condition_notes,
            'deposit' => $birr($t['deposit']),
            'lines' => $moveOut->deductions->map(fn ($d) => [
                'kind' => __('move_out.kinds.'.$d->kind, [], $locale),
                'description' => $d->description,
                'amount' => $birr($d->amount),
            ])->all(),
            'deductions_total' => $birr($t['unpaid'] + $t['other']),
            'prepaid_raw' => $t['prepaid'],
            'prepaid' => $birr($t['prepaid']),
            'refund' => $birr($t['refund']),
            'refund_raw' => $t['refund'],
            'balance' => $birr($t['balance']),
            'balance_raw' => $t['balance'],
            'refund_method' => $moveOut->refund_method ? __('payments.methods.'.$moveOut->refund_method, [], $locale) : null,
            'refund_reference' => $moveOut->refund_reference,
        ];
    }
}
