<?php

namespace App\Services\Reports;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\RentRefund;
use App\Support\DateDisplay;
use App\Support\EthiopianDate;
use Carbon\CarbonImmutable;

/**
 * Rent received in a quarter, for the landlord's quarterly tax filing.
 * Sums payments by the date they were received. It does not calculate tax owed:
 * rental tax rules changed in 2025 and should be confirmed with an accountant.
 *
 * Ethiopian calendar: fiscal-year quarters. Fiscal year 2019 runs 1 Hamle 2018 – 30 Sene 2019:
 *   Q1 Hamle, Nehase, Pagume (2018) + Meskerem (2019) · Q2 Tikimt–Tahsas · Q3 Tir–Megabit · Q4 Miyazia–Sene.
 * Gregorian calendar: calendar quarters (Jan–Mar …).
 */
class QuarterlyReport
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day (inclusive) */
    public static function range(int $year, int $quarter, string $calendar): array
    {
        if ($calendar === DateDisplay::ETHIOPIAN) {
            return match ($quarter) {
                1 => [EthiopianDate::toGregorian($year - 1, 11, 1), EthiopianDate::toGregorian($year, 1, 30)],
                2 => [EthiopianDate::toGregorian($year, 2, 1), EthiopianDate::toGregorian($year, 4, 30)],
                3 => [EthiopianDate::toGregorian($year, 5, 1), EthiopianDate::toGregorian($year, 7, 30)],
                4 => [EthiopianDate::toGregorian($year, 8, 1), EthiopianDate::toGregorian($year, 10, 30)],
            };
        }

        $start = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);

        return [$start, $start->addMonthsNoOverflow(2)->endOfMonth()->startOfDay()];
    }

    /** @return array{year: int, quarter: int} the quarter containing $date */
    public static function quarterOf(CarbonImmutable $date, string $calendar): array
    {
        if ($calendar === DateDisplay::ETHIOPIAN) {
            $e = EthiopianDate::fromGregorian($date);

            return match (true) {
                $e->month >= 11 => ['year' => $e->year + 1, 'quarter' => 1],
                $e->month === 1 => ['year' => $e->year, 'quarter' => 1],
                $e->month <= 4 => ['year' => $e->year, 'quarter' => 2],
                $e->month <= 7 => ['year' => $e->year, 'quarter' => 3],
                default => ['year' => $e->year, 'quarter' => 4],
            };
        }

        return ['year' => $date->year, 'quarter' => intdiv($date->month - 1, 3) + 1];
    }

    /** @return array<string, mixed> amounts in santim */
    public function build(int $year, int $quarter, string $calendar, string $locale): array
    {
        [$from, $to] = self::range($year, $quarter, $calendar);

        $payments = Payment::query()
            ->with(['lease.unit.property', 'tenant'])
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->orderBy('paid_at')
            ->get();

        $expenses = Expense::query()
            ->with('property:id,name')
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get();
        $refunds = RentRefund::query()
            ->with('lease.unit.property')
            ->whereBetween('refunded_on', [$from->toDateString(), $to->toDateString()])
            ->get();
        $refunded = (int) $refunds->sum('amount');
        $income = (int) $payments->sum('amount');
        $spent = (int) $expenses->sum('amount');

        $monthKey = fn (Payment $p) => $calendar === DateDisplay::ETHIOPIAN
            ? (function () use ($p) {
                $e = EthiopianDate::fromGregorian($p->paid_at);

                return sprintf('%04d-%02d', $e->year, $e->month);
            })()
            : $p->paid_at->format('Y-m');

        $monthLabel = function (string $key) use ($calendar, $locale) {
            [$y, $m] = array_map('intval', explode('-', $key));

            return $calendar === DateDisplay::ETHIOPIAN
                ? EthiopianDate::monthName($m, $locale).' '.$y
                : CarbonImmutable::create($y, $m, 1)->locale($locale)->isoFormat('MMMM YYYY');
        };

        return [
            'year' => $year,
            'quarter' => $quarter,
            'calendar' => $calendar,
            'label' => $this->label($year, $quarter, $calendar, $locale),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_display' => DateDisplay::date($from, $locale, $calendar),
            'to_display' => DateDisplay::date($to, $locale, $calendar),
            'total' => $income,
            // Costs recorded in the app. Whether each one is deductible is for an accountant to confirm.
            'expenses_total' => $spent,
            'expenses_count' => $expenses->count(),
            // Rent given back in the quarter (e.g. paid ahead for after a move-out).
            'refunded' => $refunded,
            'net_rent' => $income - $refunded,
            'net' => $income - $refunded - $spent,
            'expenses_by_category' => $expenses->groupBy('category')
                ->map(fn ($group, $category) => [
                    'category' => $category,
                    'label' => __('expenses.categories.'.$category, [], $locale),
                    'total' => (int) $group->sum('amount'),
                ])->sortByDesc('total')->values()->all(),
            'payments_count' => $payments->count(),
            'by_month' => $payments->groupBy($monthKey)->map(fn ($group, $key) => [
                'key' => $key,
                'label' => $monthLabel($key),
                'total' => (int) $group->sum('amount'),
                'count' => $group->count(),
            ])->values()->all(),
            'by_property' => $payments->groupBy(fn (Payment $p) => $p->lease?->unit?->property?->name ?? '—')
                ->map(fn ($group, $name) => [
                    'property' => $name,
                    'total' => (int) $group->sum('amount'),
                    'expenses' => (int) $expenses->filter(fn (Expense $e) => $e->property?->name === $name)->sum('amount'),
                    'refunded' => (int) $refunds->filter(fn (RentRefund $r) => $r->lease?->unit?->property?->name === $name)->sum('amount'),
                    'units' => $group->groupBy(fn (Payment $p) => $p->lease?->unit?->label ?? '—')
                        ->map(fn ($g, $label) => ['unit' => $label, 'total' => (int) $g->sum('amount'), 'count' => $g->count()])
                        ->sortKeys(SORT_NATURAL)->values()->all(),
                ])->sortByDesc('total')->values()->all(),
            'by_method' => $payments->groupBy('method')
                ->map(fn ($group, $method) => ['method' => $method, 'total' => (int) $group->sum('amount')])
                ->values()->all(),
        ];
    }

    private function label(int $year, int $quarter, string $calendar, string $locale): string
    {
        return __('reports.quarter_label.'.$calendar, ['q' => $quarter, 'year' => $year], $locale);
    }
}
