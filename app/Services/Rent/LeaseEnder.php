<?php

namespace App\Services\Rent;

use App\Models\Lease;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Ends an active lease: drops unpaid charges after the end month and frees the unit. */
class LeaseEnder
{
    public function end(Lease $lease, CarbonImmutable $endedOn): void
    {
        DB::transaction(function () use ($lease, $endedOn) {
            $lease->update([
                'status' => Lease::STATUS_ENDED,
                'terminated_on' => $endedOn->lessThan($lease->end_date) ? $endedOn : null,
            ]);

            $lease->charges()
                ->where('period', '>', $endedOn->format('Y-m'))
                ->whereDoesntHave('allocations')
                ->delete();

            $lease->unit->update(['status' => Unit::STATUS_VACANT]); // model update so vacant_since is set
        });
    }
}
