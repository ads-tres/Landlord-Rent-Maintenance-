<?php

namespace App\Services\Units;

use App\Models\Lease;
use App\Models\Listing;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When a unit was let and when it stood empty, and the rent lost while empty.
 *
 * - Occupied: from a lease's start to its end (or the day it was ended early). Draft leases don't count.
 *   An active lease past its end date still counts as occupied up to today.
 * - Vacant: any day between the unit being added to the app and today not covered by a lease.
 * - Lost rent per empty spell: days × monthly rent / 30, using the rent of the lease that followed,
 *   else the last lease before, else the asking rent of a listing. Unknown when the unit was never let or listed.
 */
class UnitOccupancy
{
    /** Days in a month used for the daily rate. */
    public const DAYS_PER_MONTH = 30;

    /**
     * @return Collection<int, array{from: CarbonImmutable, to: CarbonImmutable, lease: Lease}> occupied spells, oldest first
     */
    public function leaseSpells(Unit $unit): Collection
    {
        $today = CarbonImmutable::today();

        return $unit->leases
            ->where('status', '!=', Lease::STATUS_DRAFT)
            ->sortBy('start_date')
            ->values()
            ->map(function (Lease $lease) use ($today) {
                $end = CarbonImmutable::parse($lease->terminated_on ?? $lease->end_date);
                if ($lease->status === Lease::STATUS_ACTIVE && $end->lessThan($today)) {
                    $end = $today;
                }

                return ['from' => CarbonImmutable::parse($lease->start_date), 'to' => $end, 'lease' => $lease];
            });
    }

    /**
     * Empty spells between the unit being added and today.
     *
     * @return list<array{from: CarbonImmutable, to: CarbonImmutable, ongoing: bool, rent: ?int}>
     */
    public function vacancies(Unit $unit): array
    {
        $today = CarbonImmutable::today();
        $cursor = CarbonImmutable::parse($unit->created_at)->startOfDay();
        $spells = $this->leaseSpells($unit);
        $gaps = [];
        $previous = null;

        foreach ($spells as $spell) {
            if ($spell['from']->greaterThan($cursor)) {
                $gaps[] = [
                    'from' => $cursor,
                    'to' => $spell['from']->subDay(),
                    'ongoing' => false,
                    'rent' => self::startingRent($spell['lease']) ?: $previous?->monthly_rent,
                ];
            }
            if ($spell['to']->greaterThanOrEqualTo($cursor)) {
                $cursor = $spell['to']->addDay();
            }
            $previous = $spell['lease'];
        }

        if ($cursor->lessThanOrEqualTo($today)) {
            $gaps[] = [
                'from' => $cursor,
                'to' => $today,
                'ongoing' => true,
                'rent' => $previous?->monthly_rent ?: $this->askingRent($unit),
            ];
        }

        // A gap with no lease rent to go by falls back to the asking rent of a listing.
        foreach ($gaps as $i => $gap) {
            $gaps[$i]['rent'] ??= $this->askingRent($unit);
        }

        return $gaps;
    }

    /**
     * Vacancy within [$from, $to] (clipped to the days the unit was tracked).
     *
     * @return array{tracked_days: int, vacant_days: int, occupancy_percent: ?float, lost: int, priced: bool}
     */
    public function within(Unit $unit, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->max(CarbonImmutable::parse($unit->created_at)->startOfDay());
        $end = $to->min(CarbonImmutable::today());
        $tracked = $start->greaterThan($end) ? 0 : (int) $start->diffInDays($end) + 1;

        $vacant = 0;
        $lost = 0;
        $priced = true;
        foreach ($this->vacancies($unit) as $gap) {
            $days = self::overlap($gap['from'], $gap['to'], $start, $end);
            if ($days === 0) {
                continue;
            }
            $vacant += $days;
            if ($gap['rent'] === null) {
                $priced = false;
            } else {
                $lost += self::lostRent($gap['rent'], $days);
            }
        }

        return [
            'tracked_days' => $tracked,
            'vacant_days' => $vacant,
            'occupancy_percent' => $tracked > 0 ? round(($tracked - $vacant) / $tracked * 100, 1) : null,
            'lost' => $lost,
            'priced' => $priced,
        ];
    }

    /** The rent a lease began at, before any changes. */
    public static function startingRent(Lease $lease): int
    {
        $changes = $lease->relationLoaded('rentChanges') ? $lease->rentChanges : $lease->rentChanges()->get();

        return (int) ($changes->sortBy('effective_date')->first()?->old_rent ?? $lease->monthly_rent);
    }

    public static function lostRent(int $monthlyRent, int $days): int
    {
        return (int) round($monthlyRent * $days / self::DAYS_PER_MONTH);
    }

    /** Days shared by two inclusive date ranges. */
    public static function overlap(CarbonImmutable $a1, CarbonImmutable $a2, CarbonImmutable $b1, CarbonImmutable $b2): int
    {
        $from = $a1->max($b1);
        $to = $a2->min($b2);

        return $from->greaterThan($to) ? 0 : (int) $from->diffInDays($to) + 1;
    }

    private function askingRent(Unit $unit): ?int
    {
        $listing = $unit->relationLoaded('listings')
            ? $unit->listings->sortByDesc('id')->first()
            : $unit->listings()->latest('id')->first();

        return $listing instanceof Listing ? $listing->asking_rent : null;
    }
}
