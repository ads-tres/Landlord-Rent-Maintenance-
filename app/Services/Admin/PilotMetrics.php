<?php

namespace App\Services\Admin;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The guide's pilot measures: share of rent logged on time each month, weekly active
 * landlords, and (by hand) how many pilots pay after the free month.
 *
 * Admin code reads across landlords, so every query skips the landlord scope on purpose.
 */
class PilotMetrics
{
    /** @return array<string, mixed> */
    public function overview(): array
    {
        $landlords = User::query()->where('role', User::ROLE_LANDLORD)->get();
        $pilots = $landlords->where('is_pilot', true);
        $month = now()->format('Y-m');
        $onTime = $this->onTime(Charge::query()->withoutGlobalScopes()->where('period', $month)->where('due_date', '<', today()));

        return [
            'landlords' => $landlords->count(),
            'pilots' => $pilots->count(),
            'weekly_active' => $this->weeklyActive(5),
            'on_time' => $onTime,
            'sms_this_month' => (int) SmsMessage::query()->where('created_at', '>=', now()->startOfMonth())->sum('segments'),
            'sms_failed_this_month' => SmsMessage::query()->where('created_at', '>=', now()->startOfMonth())->where('status', 'failed')->count(),
            'waitlist' => DB::table('waitlist_signups')->count(),
            'waitlist_3plus' => DB::table('waitlist_signups')->where('unit_count', '>=', 3)->count(),
        ];
    }

    /**
     * Distinct landlords active per week, newest first.
     *
     * @return list<array{week: string, active: int}>
     */
    public function weeklyActive(int $weeks, ?int $landlordId = null): array
    {
        $result = [];
        for ($i = 0; $i < $weeks; $i++) {
            $start = CarbonImmutable::today()->startOfWeek()->subWeeks($i);
            $query = DB::table('landlord_activity_days')->whereBetween('day', [$start->toDateString(), $start->addDays(6)->toDateString()]);
            if ($landlordId) {
                $query->where('landlord_id', $landlordId);
            }
            $result[] = ['week' => $start->toDateString(), 'active' => $landlordId ? $query->count() : $query->distinct()->count('landlord_id')];
        }

        return $result;
    }

    /** @return Collection<int, array<string, mixed>> one row per landlord */
    public function landlords(): Collection
    {
        $month = now()->format('Y-m');

        return User::query()
            ->where('role', User::ROLE_LANDLORD)
            ->orderByDesc('is_pilot')
            ->orderBy('created_at')
            ->get()
            ->map(fn (User $u) => [
                'user' => $u,
                'units' => Unit::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->count(),
                'occupied' => Unit::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->where('status', Unit::STATUS_OCCUPIED)->count(),
                'tenants' => Tenant::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->count(),
                'active_leases' => Lease::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->where('status', Lease::STATUS_ACTIVE)->count(),
                'payments_this_month' => Payment::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->where('paid_at', '>=', now()->startOfMonth()->toDateString())->count(),
                'on_time' => $this->onTime(Charge::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->where('period', $month)->where('due_date', '<', today())),
                'open_repairs' => MaintenanceRequest::query()->withoutGlobalScopes()->where('landlord_id', $u->id)->where('status', '!=', 'done')->count(),
                'sms_this_month' => (int) SmsMessage::query()->where('landlord_id', $u->id)->where('created_at', '>=', now()->startOfMonth())->sum('segments'),
                'active_days_7' => DB::table('landlord_activity_days')->where('landlord_id', $u->id)->where('day', '>=', today()->subDays(6)->toDateString())->count(),
                'last_active' => DB::table('landlord_activity_days')->where('landlord_id', $u->id)->max('day'),
            ]);
    }

    /**
     * Of the charges already past due, how many were fully paid by their due date.
     *
     * @return array{due: int, on_time: int, percent: ?int}
     */
    public function onTime($chargesQuery): array
    {
        $charges = $chargesQuery->with(['allocations' => fn ($q) => $q->withoutGlobalScopes()->with(['payment' => fn ($p) => $p->withoutGlobalScopes()])])->get();

        $onTime = $charges->filter(function (Charge $c) {
            $paidByDue = $c->allocations
                ->filter(fn ($a) => $a->payment && $a->payment->paid_at->lte($c->due_date))
                ->sum('amount');

            return $paidByDue >= $c->amount_due;
        })->count();

        return [
            'due' => $charges->count(),
            'on_time' => $onTime,
            'percent' => $charges->count() ? (int) round($onTime / $charges->count() * 100) : null,
        ];
    }
}
