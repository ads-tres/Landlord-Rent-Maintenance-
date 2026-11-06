<?php

namespace App\Services\Leases;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\RentRefund;
use App\Services\Payments\ReceiptData;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;

/**
 * A statement of account for one lease: the balance brought forward, every charge (by due
 * date) and payment (by date paid) in the period with a running balance, and the balance at
 * the end. Positive balances are owed by the tenant; negative ones are paid in advance.
 */
class LeaseStatement
{
    public static function build(Lease $lease, CarbonImmutable $from, CarbonImmutable $to, string $locale): array
    {
        $lease->loadMissing(['unit.property', 'tenant', 'landlord']);
        $calendar = $lease->landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $birr = fn (int $s) => ReceiptData::birr($s, $locale);
        $date = fn ($d) => DateDisplay::date($d, $locale, $calendar);

        $chargesBefore = (int) Charge::query()->where('lease_id', $lease->id)->where('due_date', '<', $from->toDateString())->sum('amount_due');
        $paidBefore = (int) Payment::query()->where('lease_id', $lease->id)->where('paid_at', '<', $from->toDateString())->sum('amount');
        $refundedBefore = (int) RentRefund::query()->where('lease_id', $lease->id)->where('refunded_on', '<', $from->toDateString())->sum('amount');
        $opening = $chargesBefore - $paidBefore + $refundedBefore;

        $lines = collect();
        Charge::query()->where('lease_id', $lease->id)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->with('utilityBill.meter')
            ->get()
            ->each(fn (Charge $c) => $lines->push([
                'date' => $c->due_date->toDateString(),
                'order' => 0,
                'kind' => 'charge',
                'description' => __($c->type === 'utility' ? 'statement.utility' : 'statement.rent', [
                    'period' => DateDisplay::period($c->period, $locale, $calendar),
                    'meter' => $c->utilityBill?->meter?->name,
                ], $locale),
                'charge' => (int) $c->amount_due,
                'payment' => 0,
            ]));
        Payment::query()->where('lease_id', $lease->id)
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(fn (Payment $p) => $lines->push([
                'date' => $p->paid_at->toDateString(),
                'order' => 1,
                'kind' => 'payment',
                'description' => trim(__('statement.payment', [
                    'method' => __('payments.methods.'.$p->method, [], $locale),
                    'receipt' => $p->receiptNumber(),
                ], $locale).($p->reference ? ' · '.$p->reference : '')),
                'charge' => 0,
                'payment' => (int) $p->amount,
            ]));

        // Rent given back (e.g. paid ahead for after a move-out).
        RentRefund::query()->where('lease_id', $lease->id)
            ->whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->each(fn (RentRefund $r) => $lines->push([
                'date' => $r->refunded_on->toDateString(),
                'order' => 2,
                'kind' => 'refund',
                'description' => __('statement.prepaid_refund', [], $locale),
                'charge' => $r->amount,
                'payment' => 0,
            ]));

        $balance = $opening;
        $rows = $lines->sortBy([['date', 'asc'], ['order', 'asc']])->values()->map(function ($l) use (&$balance, $date, $birr, $locale) {
            $balance += $l['charge'] - $l['payment'];

            return $l + ['balance' => $balance, 'date_display' => $date($l['date']), 'charge_display' => $l['charge'] ? $birr($l['charge']) : '', 'payment_display' => $l['payment'] ? $birr($l['payment']) : '', 'balance_display' => self::balance($balance, $birr, $locale)];
        });

        return [
            'locale' => $locale,
            'calendar' => $calendar,
            'lease_id' => $lease->id,
            'tenant' => $lease->tenant->full_name,
            'place' => $lease->unit->label.' · '.$lease->unit->property->name,
            'landlord' => collect([$lease->landlord?->name ?: $lease->landlord?->phone, $lease->landlord?->name ? $lease->landlord?->phone : null, $lease->landlord?->address])->filter()->implode(', '),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_display' => $date($from),
            'to_display' => $date($to),
            'monthly_rent' => $birr($lease->monthly_rent),
            'opening' => $opening,
            'opening_display' => self::balance($opening, $birr, $locale),
            'charged' => (int) $rows->sum('charge'),
            'paid' => (int) $rows->sum('payment'),
            'closing' => $balance,
            'closing_display' => self::balance($balance, $birr, $locale),
            'lines' => $rows->map(fn ($r) => collect($r)->except('order')->all())->all(),
        ];
    }

    /** "Br 10,000 owed" / "Br 2,000 in credit" / "Br 0". */
    private static function balance(int $santim, \Closure $birr, string $locale): string
    {
        return match (true) {
            $santim > 0 => __('statement.owed', ['amount' => $birr($santim)], $locale),
            $santim < 0 => __('statement.credit', ['amount' => $birr(-$santim)], $locale),
            default => $birr(0),
        };
    }
}
