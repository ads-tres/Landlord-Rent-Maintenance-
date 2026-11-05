<?php

namespace App\Services\Rent;

use App\Models\Charge;
use App\Models\Lease;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out a lease's monthly rent charges.
 *
 * - One charge per month of the term: a 2-year lease from 2026-11-05 to 2028-11-04
 *   has 24 charges, for periods 2026-11 through 2028-10.
 * - Each charge is due on the lease's due day, clamped to the month's length
 *   (due day 30 in February is the 28th or 29th) and never before the start date.
 * - Charges are only created up to the current month; the daily job adds the rest.
 * - Periods follow the Gregorian calendar for now. Ethiopian-month periods are
 *   planned with the calendar feature.
 */
class RentSchedule
{
    /** @return list<string> every period (YYYY-MM) in the lease term */
    public function periods(Lease $lease): array
    {
        $start = CarbonImmutable::parse($lease->start_date)->startOfDay();
        $endExclusive = CarbonImmutable::parse($lease->terminated_on ?? $lease->end_date)->startOfDay()->addDay();

        // Whole months in the term, counting a final part month as one.
        $months = (int) floor($start->diffInMonths($endExclusive, true));
        if ($start->addMonthsNoOverflow($months)->lessThan($endExclusive)) {
            $months++;
        }
        $first = $start->startOfMonth();

        $periods = [];
        for ($i = 0; $i < max($months, 1); $i++) {
            $periods[] = $first->addMonthsNoOverflow($i)->format('Y-m');
        }

        return $periods;
    }

    public function dueDate(Lease $lease, string $period): CarbonImmutable
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $period);
        $due = $month->day(min($lease->due_day, $month->daysInMonth));
        $start = CarbonImmutable::parse($lease->start_date)->startOfDay();

        return $due->lessThan($start) ? $start : $due;
    }

    /** Rent in effect for a period, taking rent changes into account. */
    public function rentFor(Lease $lease, string $period): int
    {
        $changes = $lease->relationLoaded('rentChanges')
            ? $lease->rentChanges->sortBy('effective_date')
            : $lease->rentChanges()->orderBy('effective_date')->get();

        $rent = $changes->first()?->old_rent ?? $lease->monthly_rent;

        foreach ($changes as $change) {
            if (CarbonImmutable::parse($change->effective_date)->format('Y-m') <= $period) {
                $rent = $change->new_rent;
            }
        }

        return $rent;
    }

    /**
     * Creates any missing charges from the start of the lease up to the month of $until.
     *
     * @return Collection<int, Charge> the charges created
     */
    public function sync(Lease $lease, ?CarbonInterface $until = null): Collection
    {
        $untilPeriod = CarbonImmutable::parse($until ?? now())->format('Y-m');

        $existing = $lease->charges()->where('type', 'rent')->pluck('period')->all();
        $lease->loadMissing('rentChanges');

        $created = collect();
        foreach ($this->periods($lease) as $period) {
            if ($period > $untilPeriod) {
                break;
            }
            if (in_array($period, $existing, true)) {
                continue;
            }

            $created->push($this->createCharge($lease, $period));
        }

        return $created;
    }

    /** Creates the rent charge for one period (used for advance payments too). */
    public function createCharge(Lease $lease, string $period): Charge
    {
        $charge = new Charge([
            'period' => $period,
            'type' => 'rent',
            'amount_due' => $this->rentFor($lease, $period),
            'due_date' => $this->dueDate($lease, $period),
            'status' => Charge::STATUS_DUE,
        ]);
        $charge->landlord_id = $lease->landlord_id;
        $charge->lease()->associate($lease);
        $charge->save();

        return $charge;
    }

    /**
     * Updates unpaid rent charges from $fromPeriod onwards after a rent change.
     * Charges that already have money allocated are left alone.
     */
    public function reprice(Lease $lease, string $fromPeriod): int
    {
        $lease->load('rentChanges');
        $updated = 0;

        $lease->charges()
            ->where('type', 'rent')
            ->where('period', '>=', $fromPeriod)
            ->whereIn('status', [Charge::STATUS_DUE, Charge::STATUS_LATE])
            ->whereDoesntHave('allocations')
            ->get()
            ->each(function (Charge $charge) use ($lease, &$updated) {
                $charge->update(['amount_due' => $this->rentFor($lease, $charge->period)]);
                $updated++;
            });

        return $updated;
    }
}
