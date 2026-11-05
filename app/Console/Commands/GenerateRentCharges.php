<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Services\Rent\RentSchedule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily: creates this month's rent charge for every active lease (safe to re-run),
 * and brings each lease's current rent up to date with scheduled rent changes.
 */
#[Signature('rent:generate-charges')]
#[Description('Create missing rent charges up to the current month for all active leases')]
class GenerateRentCharges extends Command
{
    public function handle(RentSchedule $schedule): int
    {
        $created = 0;

        Lease::query()
            ->withoutGlobalScopes()
            ->active()
            ->with('rentChanges')
            ->chunkById(200, function ($leases) use ($schedule, &$created) {
                foreach ($leases as $lease) {
                    $created += $schedule->sync($lease)->count();

                    $current = $schedule->rentFor($lease, now()->format('Y-m'));
                    if ($current !== $lease->monthly_rent) {
                        $lease->update(['monthly_rent' => $current]);
                    }
                }
            });

        $this->info("Created {$created} charge(s).");

        return self::SUCCESS;
    }
}
