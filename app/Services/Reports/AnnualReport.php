<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\RentRefund;
use App\Models\Unit;
use App\Support\DateDisplay;
use App\Support\EthiopianDate;
use Carbon\CarbonImmutable;

/**
 * A year at a glance, month by month: rent received, refunds, expenses, net and how many
 * units were let. Ethiopian calendar: the fiscal year (Hamle to Sene, Pagume as its own short
 * month); Gregorian: January to December. Amounts in santim.
 */
class AnnualReport
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(int $year, string $calendar): array
    {
        if ($calendar === DateDisplay::ETHIOPIAN) {
            return [QuarterlyReport::range($year, 1, $calendar)[0], QuarterlyReport::range($year, 4, $calendar)[1]];
        }

        return [CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)];
    }

    public static function yearOf(CarbonImmutable $date, string $calendar): int
    {
        return $calendar === DateDisplay::ETHIOPIAN
            ? QuarterlyReport::quarterOf($date, $calendar)['year']
            : $date->year;
    }

    public function build(int $year, string $calendar, string $locale): array
    {
        [$from, $to] = self::range($year, $calendar);
        $months = $this->months($from, $to, $calendar, $locale);

        $payments = Payment::query()->with('lease.unit.property')->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])->get();
        $refunds = RentRefund::query()->with('lease.unit.property')->whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])->get();
        $expenses = Expense::query()->with('property:id,name')->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])->get();
        $leases = Lease::query()->whereIn('status', [Lease::STATUS_ACTIVE, Lease::STATUS_ENDED])
            ->where('start_date', '<=', $to->toDateString())
            ->whereRaw('COALESCE(terminated_on, end_date) >= ?', [$from->toDateString()])
            ->get(['id', 'unit_id', 'start_date', 'end_date', 'terminated_on']);
        $units = max(1, Unit::query()->count());

        $in = fn ($date, $m) => $date >= $m['from'] && $date <= $m['to'];
        $rows = array_map(function ($m) use ($payments, $refunds, $expenses, $leases, $units, $in) {
            $income = (int) $payments->filter(fn ($p) => $in($p->paid_at->toDateString(), $m))->sum('amount');
            $refunded = (int) $refunds->filter(fn ($r) => $in($r->refunded_on->toDateString(), $m))->sum('amount');
            $spent = (int) $expenses->filter(fn ($e) => $in($e->spent_on->toDateString(), $m))->sum('amount');
            $let = $leases->filter(fn ($l) => $l->start_date->toDateString() <= $m['to']
                && ($l->terminated_on ?? $l->end_date)->toDateString() >= $m['from'])->pluck('unit_id')->unique()->count();

            return [
                'key' => $m['key'],
                'label' => $m['label'],
                'income' => $income,
                'refunded' => $refunded,
                'expenses' => $spent,
                'net' => $income - $refunded - $spent,
                'units_let' => $let,
                'occupancy' => (int) round($let / $units * 100),
            ];
        }, $months);

        $income = (int) $payments->sum('amount');
        $refunded = (int) $refunds->sum('amount');
        $spent = (int) $expenses->sum('amount');
        $propertyOf = fn ($m) => $m?->lease?->unit?->property?->name ?? '—';

        return [
            'year' => $year,
            'calendar' => $calendar,
            'label' => __('reports.year_label.'.$calendar, ['year' => $year], $locale),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_display' => DateDisplay::date($from, $locale, $calendar),
            'to_display' => DateDisplay::date($to, $locale, $calendar),
            'income' => $income,
            'refunded' => $refunded,
            'expenses' => $spent,
            'net' => $income - $refunded - $spent,
            'units' => $units,
            'average_occupancy' => $rows ? (int) round(collect($rows)->avg('occupancy')) : 0,
            'months' => $rows,
            'by_property' => $payments->groupBy($propertyOf)->keys()
                ->merge($expenses->map(fn ($e) => $e->property?->name ?? '—'))
                ->merge($refunds->groupBy($propertyOf)->keys())
                ->unique()->values()
                ->map(function ($name) use ($payments, $refunds, $expenses, $propertyOf) {
                    $income = (int) $payments->filter(fn ($p) => $propertyOf($p) === $name)->sum('amount');
                    $refunded = (int) $refunds->filter(fn ($r) => $propertyOf($r) === $name)->sum('amount');
                    $spent = (int) $expenses->filter(fn ($e) => ($e->property?->name ?? '—') === $name)->sum('amount');

                    return ['property' => $name, 'income' => $income, 'refunded' => $refunded, 'expenses' => $spent, 'net' => $income - $refunded - $spent];
                })->sortByDesc('income')->values()->all(),
        ];
    }

    /** @return list<array{key: string, label: string, from: string, to: string}> */
    private function months(CarbonImmutable $from, CarbonImmutable $to, string $calendar, string $locale): array
    {
        $months = [];
        if ($calendar === DateDisplay::ETHIOPIAN) {
            $start = EthiopianDate::fromGregorian($from);
            [$y, $m] = [$start->year, $start->month];
            while (true) {
                $first = EthiopianDate::toGregorian($y, $m, 1);
                if ($first->gt($to)) {
                    break;
                }
                $days = $m === 13 ? (($y % 4 === 3) ? 6 : 5) : 30;
                $months[] = [
                    'key' => sprintf('%04d-%02d', $y, $m),
                    'label' => EthiopianDate::monthName($m, $locale).' '.$y,
                    'from' => $first->toDateString(),
                    'to' => $first->addDays($days - 1)->toDateString(),
                ];
                [$y, $m] = $m === 13 ? [$y + 1, 1] : [$y, $m + 1];
            }

            return $months;
        }

        for ($d = $from; $d->lte($to); $d = $d->addMonthNoOverflow()) {
            $months[] = [
                'key' => $d->format('Y-m'),
                'label' => $d->locale($locale)->isoFormat('MMMM YYYY'),
                'from' => $d->startOfMonth()->toDateString(),
                'to' => $d->endOfMonth()->toDateString(),
            ];
        }

        return $months;
    }
}
