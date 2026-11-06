<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\RentChange;
use App\Models\Unit;
use App\Services\Reports\AnnualReport;
use App\Services\Units\UnitOccupancy;
use App\Support\DateDisplay;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class UnitHistoryController extends Controller
{
    public function __construct(private UnitOccupancy $occupancy) {}

    /** GET /units/{id}/history — everything that happened to a unit, newest first, with this year's vacancy. */
    public function show(Request $request, Unit $unit): JsonResponse
    {
        Gate::authorize('view', $unit);
        $unit->load(['property', 'listings', 'leases.tenant', 'leases.rentChanges', 'maintenanceRequests']);

        $calendar = $request->user()->actingLandlord()->calendar_pref ?: DateDisplay::GREGORIAN;
        $year = AnnualReport::yearOf(CarbonImmutable::today(), $calendar);
        [$from, $to] = AnnualReport::range($year, $calendar);
        $thisYear = $this->occupancy->within($unit, $from, $to);

        $events = collect();
        foreach ($this->occupancy->leaseSpells($unit) as $spell) {
            /** @var Lease $lease */
            $lease = $spell['lease'];
            $events->push([
                'type' => 'lease_started', 'date' => $spell['from']->toDateString(),
                'lease_id' => $lease->id, 'tenant' => $lease->tenant?->full_name,
                'rent' => UnitOccupancy::startingRent($lease),
            ]);
            if ($lease->status === Lease::STATUS_ENDED) {
                $events->push([
                    'type' => 'lease_ended', 'date' => $spell['to']->toDateString(),
                    'lease_id' => $lease->id, 'tenant' => $lease->tenant?->full_name,
                    'early' => $lease->terminated_on !== null && $lease->terminated_on->lessThan($lease->end_date),
                ]);
            }
            $lease->rentChanges->each(fn (RentChange $c) => $events->push([
                'type' => 'rent_changed', 'date' => $c->effective_date->toDateString(),
                'lease_id' => $lease->id, 'old_rent' => $c->old_rent, 'new_rent' => $c->new_rent,
                'upcoming' => $c->effective_date->isAfter(today()),
            ]));
        }

        $unit->maintenanceRequests->each(fn (MaintenanceRequest $r) => $events->push([
            'type' => 'repair', 'date' => $r->opened_at->toDateString(),
            'repair_id' => $r->id, 'title' => $r->title, 'status' => $r->status, 'cost' => $r->cost,
        ]));

        foreach ($this->occupancy->vacancies($unit) as $gap) {
            $days = (int) $gap['from']->diffInDays($gap['to']) + 1;
            $events->push([
                'type' => 'vacant', 'date' => $gap['from']->toDateString(),
                'until' => $gap['ongoing'] ? null : $gap['to']->toDateString(),
                'days' => $days,
                'lost' => $gap['rent'] === null ? null : UnitOccupancy::lostRent($gap['rent'], $days),
            ]);
        }

        // Newest first; on the same day an ending comes before the next start.
        $order = ['rent_changed' => 0, 'lease_started' => 1, 'vacant' => 2, 'lease_ended' => 3, 'repair' => 4];
        $sorted = $events->sort(fn ($a, $b) => [$b['date'], $order[$a['type']]] <=> [$a['date'], $order[$b['type']]])->values();

        $ended = $unit->leases->where('status', Lease::STATUS_ENDED);

        return response()->json(['data' => [
            'unit' => [
                'id' => $unit->id, 'label' => $unit->label, 'status' => $unit->status,
                'property_id' => $unit->property_id, 'property_name' => $unit->property?->name,
                'tracked_since' => CarbonImmutable::parse($unit->created_at)->toDateString(),
            ],
            'year' => ['year' => $year, 'from' => $from->toDateString(), 'to' => $to->toDateString()] + $thisYear
                + ['repair_cost' => (int) $unit->maintenanceRequests->filter(fn ($r) => $r->opened_at->between($from, $to->endOfDay()))->sum('cost')],
            'totals' => [
                'tenants' => $unit->leases->where('status', '!=', Lease::STATUS_DRAFT)->pluck('tenant_id')->unique()->count(),
                'average_stay_months' => $ended->isEmpty() ? null : round($ended->avg(fn (Lease $l) => CarbonImmutable::parse($l->start_date)->diffInMonths(CarbonImmutable::parse($l->terminated_on ?? $l->end_date))), 1),
                'repair_cost' => (int) $unit->maintenanceRequests->sum('cost'),
                'current_rent' => $unit->leases->firstWhere('status', Lease::STATUS_ACTIVE)?->monthly_rent,
            ],
            'events' => $sorted,
        ]]);
    }

    /** GET /vacancy?year= — empty days and rent lost per unit for a (fiscal) year, worst first. */
    public function vacancy(Request $request): JsonResponse
    {
        $calendar = $request->user()->actingLandlord()->calendar_pref ?: DateDisplay::GREGORIAN;
        $year = (int) ($request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']])['year']
            ?? AnnualReport::yearOf(CarbonImmutable::today(), $calendar));
        [$from, $to] = AnnualReport::range($year, $calendar);

        $units = Unit::query()->with(['property', 'listings', 'leases'])->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id, 'label' => $u->label, 'property_name' => $u->property?->name,
                'status' => $u->status,
                'vacant_since' => $u->status === Unit::STATUS_VACANT ? $u->vacant_since?->toDateString() : null,
            ] + $this->occupancy->within($u, $from, $to))
            ->filter(fn ($u) => $u['tracked_days'] > 0)
            ->sortByDesc(fn ($u) => [$u['lost'], $u['vacant_days']])
            ->values();

        return response()->json(['data' => [
            'year' => $year, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'lost' => (int) $units->sum('lost'),
            'vacant_days' => (int) $units->sum('vacant_days'),
            'occupancy_percent' => $units->sum('tracked_days') > 0
                ? round(($units->sum('tracked_days') - $units->sum('vacant_days')) / $units->sum('tracked_days') * 100, 1)
                : null,
            'unpriced_units' => $units->where('priced', false)->count(),
            'units' => $units,
        ]]);
    }
}
