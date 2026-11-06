<?php

namespace App\Services\Portal;

use App\Models\Announcement;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Services\Payments\ReceiptData;
use App\Services\Rent\RentSchedule;
use App\Support\DateDisplay;

/** Everything the tenant sees on their own page (/t/{token}). Amounts formatted for display. */
class TenantPortal
{
    public static function for(Lease $lease, string $locale): array
    {
        $landlord = $lease->landlord;
        $calendar = $landlord?->calendar_pref ?: DateDisplay::GREGORIAN;
        $birr = fn (int $s) => ReceiptData::birr($s, $locale);
        $date = fn ($d) => DateDisplay::date($d, $locale, $calendar);
        $active = $lease->status === Lease::STATUS_ACTIVE;

        $charges = Charge::query()->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->withSum('allocations as paid', 'amount')
            ->orderBy('due_date')
            ->get();
        $open = $charges->filter(fn (Charge $c) => $c->amount_due > (int) $c->paid);
        $owed = fn ($cs) => (int) $cs->sum(fn (Charge $c) => $c->amount_due - (int) $c->paid);
        $overdue = $open->filter(fn (Charge $c) => $c->due_date->lt(today()));
        $next = $open->first(fn (Charge $c) => $c->due_date->gte(today()));
        $nextDue = $next ? [
            'amount' => $next->amount_due - (int) $next->paid,
            'date' => $next->due_date,
            'type' => $next->type,
            'period' => $next->period,
        ] : null;

        // The next month's charge may not exist yet (charges are created a few days ahead).
        if (! $nextDue && $active) {
            $schedule = app(RentSchedule::class);
            $charged = $charges->where('type', 'rent')->keyBy('period');
            foreach ($schedule->periods($lease) as $period) {
                $due = $schedule->dueDate($lease, $period);
                if ($due->lt(today()) || ($charged->has($period) && $charged[$period]->amount_due <= (int) $charged[$period]->paid)) {
                    continue;
                }
                $nextDue = ['amount' => $schedule->rentFor($lease, $period), 'date' => $due, 'type' => 'rent', 'period' => $period];
                break;
            }
        }

        $payments = Payment::query()->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->latest('paid_at')->latest('id')
            ->limit(12)
            ->get();

        $repairs = MaintenanceRequest::query()->withoutGlobalScopes()
            ->where('lease_id', $lease->id)
            ->latest('opened_at')
            ->limit(5)
            ->get();

        $announcements = Announcement::query()->withoutGlobalScopes()
            ->where('property_id', $lease->unit->property_id)
            ->where('created_at', '>=', now()->subDays(90))
            ->latest()
            ->limit(5)
            ->get();

        $moveOut = $lease->moveOut()->withoutGlobalScopes()->first();

        return [
            'locale' => $locale,
            'active' => $active,
            'tenant' => trim(strtok((string) $lease->tenant->full_name, ' ') ?: ''),
            'unit' => $lease->unit->label,
            'property' => $lease->unit->property->name,
            'landlord' => $landlord?->name ?: null,
            'landlord_phone' => $landlord?->phone,
            'lease_from' => $date($lease->start_date),
            'lease_to' => $date($lease->terminated_on ?? $lease->end_date),
            'days_left' => $active ? max(0, (int) today()->diffInDays($lease->end_date, false)) : null,
            'monthly_rent' => $birr($lease->monthly_rent),
            'balance_raw' => $owed($overdue),
            'balance' => $birr($owed($overdue)),
            'next_due' => $nextDue ? [
                'amount' => $birr($nextDue['amount']),
                'date' => $date($nextDue['date']),
                'label' => __($nextDue['type'] === 'utility' ? 'portal.utility_share' : 'portal.rent_for', [
                    'period' => DateDisplay::period($nextDue['period'], $locale, $calendar),
                ], $locale),
            ] : null,
            'pay_url' => $active && $landlord?->acceptsOnlineRent() && ($overdue->isNotEmpty() || $nextDue) ? $lease->payUrl() : null,
            'payments' => $payments->map(fn (Payment $p) => [
                'date' => $date($p->paid_at),
                'amount' => $birr($p->amount),
                'method' => __('payments.methods.'.$p->method, [], $locale),
                'receipt_url' => $p->receiptUrl(),
            ])->all(),
            'repairs' => $repairs->map(fn (MaintenanceRequest $r) => [
                'title' => $r->title,
                'status' => __('repairs.statuses.'.$r->status, [], $locale),
                'done' => $r->status === 'done',
                'date' => $date($r->opened_at),
            ])->all(),
            'repair_url' => $active ? $lease->repairUrl() : null,
            'announcements' => $announcements->map(fn (Announcement $a) => [
                'date' => $date($a->created_at),
                'body' => $a->body,
            ])->all(),
            'statement_url' => $moveOut?->isSettled() ? $moveOut->statementUrl() : null,
        ];
    }
}
